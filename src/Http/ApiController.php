<?php

declare(strict_types=1);

namespace Voxora\Http;

use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\Security\RateLimiter;
use Voxora\TTS\Manager;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Storage\SqliteRepository;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;

/**
 * ApiController
 *
 * JSON-first REST API for programmatic access to TTS capabilities.
 * All endpoints (except listVoices) require a valid scoped token.
 * Returns JSON with audio URLs, metadata, or error details.
 * Now includes Redis-backed rate limiting on protected actions
 * and export of the voice library as portable JSON bundles.
 */
final class ApiController
{
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private array $config;
    private WebhookDispatcher $webhookDispatcher;
    private RateLimiter $rateLimiter;

    public function __construct(
        KeyManager $keyManager,
        Manager $ttsManager,
        SqliteRepository $repository,
        array $config,
        WebhookDispatcher $webhookDispatcher,
        RateLimiter $rateLimiter
    ) {
        $this->keyManager = $keyManager;
        $this->ttsManager = $ttsManager;
        $this->repository = $repository;
        $this->config = $config;
        $this->webhookDispatcher = $webhookDispatcher;
        $this->rateLimiter = $rateLimiter;
    }

    public function handle(string $action, Request $request): void
    {
        try {
            $method = $action . 'Action';
            if (method_exists($this, $method)) {
                $this->{$method}($request);
                return;
            }
            $this->json(['error' => 'Action not found'], 404);
        } catch (RuntimeException | InvalidArgumentException $e) {
            $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            $this->json(['error' => 'Internal server error'], 500);
        }
    }

    private function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function listVoicesAction(Request $request): void
    {
        $provider = $request->getQueryParam('provider');
        $voices = $this->ttsManager->listVoices($provider);

        $this->json([
            'success' => true,
            'voices' => $voices,
            'count' => count($voices),
        ]);
    }

    public function speechAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'tts:generate');

        $ip = $request->getHeader('X-Forwarded-For') ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->rateLimiter->check('api:' . ($token->getUserId() ?? $ip), $ip);

        $text = trim($request->getPost()['text'] ?? $request->getBody());
        if ($text === '') {
            $this->json(['error' => 'Text is required'], 400);
            return;
        }

        $voiceId = $request->getPost()['voice_id'] ?? $request->getQueryParam('voice_id', '');
        $provider = $request->getPost()['provider'] ?? $request->getQueryParam('provider');
        $options = $this->extractOptions($request);

        $result = $this->ttsManager->generate($text, $voiceId, $provider, $options);

        $ext = ($options['format'] ?? 'mp3') === 'wav' ? 'wav' : 'mp3';
        $filename = 'speech-' . time() . '.' . $ext;
        $dir = rtrim($this->config['audio']['directory'] ?? 'storage/audio', '/');
        $path = $dir . '/' . $filename;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $result->save($path);

        $publicUrl = rtrim($this->config['audio']['public_url_prefix'] ?? '/audio', '/') . '/' . $filename;

        $this->repository->recordGeneration([
            'user_id' => $token->getUserId(),
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $provider ?? 'default',
            'duration' => $result->duration ?? 0.0,
            'audio_path' => $path,
        ]);

        $context = [
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $provider,
            'user_id' => $token->getUserId(),
            'audio_url' => $publicUrl,
        ];
        $this->webhookDispatcher->dispatchSuccess($result, $context);

        $this->json([
            'success' => true,
            'audio_url' => $publicUrl,
            'duration' => $result->duration ?? 0.0,
            'format' => $ext,
        ]);
    }

    public function cloneVoiceAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'tts:clone');

        $name = trim($request->getPost()['name'] ?? '');
        $file = $request->getUploadedFile('sample');

        if ($name === '' || $file === null || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->json(['error' => 'Valid name and sample audio file are required'], 400);
            return;
        }

        $providerName = $request->getPost()['provider'] ?? 'elevenlabs';
        $voiceId = $this->ttsManager->cloneVoice(
            name: $name,
            samplePath: $file['tmp_name'],
            provider: $providerName
        );

        $this->repository->saveCustomVoice($voiceId, $name, $token->getUserId());

        $this->json([
            'success' => true,
            'voice_id' => $voiceId,
            'name' => $name,
            'provider' => $providerName,
        ]);
    }

    public function exportLibraryAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'library:read');
        $bundle = $this->repository->exportLibrary();

        header('Content-Disposition: attachment; filename="voxora-voice-library.json"');
        $this->json([
            'success' => true,
            'bundle' => $bundle,
            'version' => $bundle['version'] ?? '1.0',
            'exported_at' => $bundle['exported_at'] ?? (new DateTimeImmutable())->format('c'),
        ]);
    }

    public function importLibraryAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'library:write');
        $file = $request->getUploadedFile('bundle');

        if ($file === null || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->json(['error' => 'Bundle file is required'], 400);
            return;
        }

        $bundle = json_decode(file_get_contents($file['tmp_name']), true, 512, JSON_THROW_ON_ERROR);
        $imported = $this->repository->importLibrary($bundle);

        $this->json([
            'success' => true,
            'imported_count' => $imported,
        ]);
    }

    public function historyAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'tts:history');

        $params = [
            'user_id' => $token->getUserId(),
            'page' => (int) $request->getQueryParam('page', 1),
            'per_page' => (int) $request->getQueryParam('per_page', 20),
            'filters' => [
                'search' => $request->getQueryParam('search'),
                'provider' => $request->getQueryParam('provider'),
                'date_from' => $request->getQueryParam('date_from'),
                'date_to' => $request->getQueryParam('date_to'),
            ],
        ];

        $history = $this->repository->getHistory($params);
        $this->json($history);
    }

    public function issueTokenAction(Request $request): void
    {
        $token = $this->requireValidToken($request, 'tokens:issue');

        if ($token->getRole() !== 'admin') {
            $this->json(['error' => 'Admin role required to issue tokens'], 403);
            return;
        }

        $name = trim($request->getPost()['name'] ?? 'API Token');
        $scopes = (array) ($request->getPost()['scopes'] ?? ['tts:generate']);
        $role = $request->getPost()['role'] ?? 'user';
        $userId = isset($request->getPost()['user_id']) ? (int) $request->getPost()['user_id'] : null;

        $tokenStr = $this->keyManager->issueToken(
            name: $name,
            scopes: $scopes,
            role: $role,
            userId: $userId
        );

        $this->json([
            'success' => true,
            'token' => $tokenStr,
            'name' => $name,
            'scopes' => $scopes,
            'role' => $role,
        ]);
    }

    private function requireValidToken(Request $request, string $requiredScope): Token
    {
        $header = $request->getHeader('X-Api-Key') ?? $request->getHeader('Authorization') ?? '';
        if ($header === '') {
            $this->json(['error' => 'Missing API token'], 401);
            exit(0);
        }

        if (str_starts_with($header, 'Bearer ')) {
            $header = substr($header, 7);
        }

        $token = $this->keyManager->validate($header);
        if ($token === null || !$token->hasScope($requiredScope)) {
            $this->json(['error' => 'Invalid token or insufficient scope'], 403);
            exit(0);
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractOptions(Request $request): array
    {
        $post = $request->getPost();
        $options = [
            'normalize' => true,
            'target_loudness' => -16.0,
            'format' => 'mp3',
        ];

        if (isset($post['format'])) {
            $options['format'] = strtolower(trim((string) $post['format']));
        }
        if (isset($post['normalize'])) {
            $options['normalize'] = filter_var($post['normalize'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($post['target_loudness'])) {
            $options['target_loudness'] = (float) $post['target_loudness'];
        }

        return $options;
    }
}
