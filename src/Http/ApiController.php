<?php

declare(strict_types=1);

namespace Voxora\Http;

use Voxora\Security\KeyManager;
use Voxora\Security\Token;
use Voxora\TTS\Manager;
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
 */
final class ApiController
{
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private array $config;

    public function __construct(
        KeyManager $keyManager,
        Manager $ttsManager,
        SqliteRepository $repository,
        array $config
    ) {
        $this->keyManager = $keyManager;
        $this->ttsManager = $ttsManager;
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * POST /api/v1/speech
     *
     * Accepts JSON or form data with "text" and "voice_id".
     * Validates token + scope, generates speech, persists file and metadata.
     */
    public function speechAction(Request $request): Response
    {
        $token = $this->validateToken($request, 'tts:generate');
        if ($token === null) {
            return Response::json([
                'error' => 'Unauthorized - valid token with tts:generate scope required',
                'code' => 'invalid_token'
            ], 401);
        }

        $input = $this->parseInput($request);

        $text = trim($input['text'] ?? '');
        $voiceId = trim($input['voice_id'] ?? '');

        if ($text === '') {
            return Response::json(['error' => 'The "text" field is required.'], 400);
        }

        if ($voiceId === '') {
            return Response::json(['error' => 'The "voice_id" field is required.'], 400);
        }

        try {
            $options = [
                'normalize' => ($input['normalize'] ?? 'true') !== 'false',
                'target_loudness' => (float) ($input['target_loudness'] ?? -16.0),
                'format' => strtolower($input['format'] ?? 'mp3'),
            ];

            $result = $this->ttsManager->generate(
                $text,
                $voiceId,
                $input['provider'] ?? null,
                $options
            );

            $ext = $result->format === 'wav' ? 'wav' : 'mp3';
            $filename = 'vox-' . bin2hex(random_bytes(10)) . '.' . $ext;
            $audioDir = $this->config['audio']['directory'] ?? __DIR__ . '/../../storage/audio';
            $audioPath = $audioDir . '/' . $filename;
            $result->save($audioPath);

            $urlPrefix = $this->config['audio']['public_url_prefix'] ?? '/audio';
            $publicUrl = $urlPrefix . '/' . $filename;

            $this->repository->saveGeneration([
                'hash' => $this->computeHash($text, $voiceId),
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => strtolower($input['provider'] ?? 'elevenlabs'),
                'format' => $result->format,
                'duration' => $result->duration ?? null,
                'metadata' => $result->metadata,
                'audio_size_bytes' => strlen($result->audioContent),
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'user_id' => $token->userId ?? 1,
            ]);

            return Response::json([
                'success' => true,
                'audio_url' => $publicUrl,
                'preview_uri' => $result->toDataUri(),
                'duration' => $result->duration,
                'metadata' => $result->metadata,
                'filename' => $filename,
                'text_preview' => substr($text, 0, 80) . (strlen($text) > 80 ? '...' : ''),
                'user_id' => $token->userId,
                'role' => $token->role,
                'normalized' => $result->metadata['normalized'] ?? false,
                'format' => $result->format,
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => 'Failed to generate speech: ' . $e->getMessage()], 502);
        }
    }

    /**
     * GET /api/v1/history
     *
     * Returns recent generation history (requires valid token).
     */
    public function historyAction(Request $request): Response
    {
        $token = $this->validateToken($request);
        if ($token === null) {
            return Response::json(['error' => 'Unauthorized'], 401);
        }

        $limit = max(1, min(100, (int) ($request->getPost('limit', 20))));
        $history = $this->repository->getHistory($limit, $token->userId);

        return Response::json([
            'history' => $history,
            'count' => count($history),
            'user_id' => $token->userId,
            'role' => $token->role,
        ]);
    }

    /**
     * POST /api/v1/tokens
     *
     * Issues a new scoped, encrypted token. For demo purposes this endpoint
     * does not require prior authentication.
     */
    public function issueTokenAction(Request $request): Response
    {
        $input = $this->parseInput($request);

        $name = trim($input['name'] ?? '');
        if ($name === '') {
            return Response::json(['error' => 'name is required'], 400);
        }

        $scopes = is_array($input['scopes'] ?? null)
            ? $input['scopes']
            : ['tts:generate'];

        $role = in_array($input['role'] ?? 'user', ['user', 'admin'], true)
            ? ($input['role'] ?? 'user')
            : 'user';

        try {
            $tokenString = $this->keyManager->issueToken($name, $scopes, $role);

            return Response::json([
                'success' => true,
                'token' => $tokenString,
                'name' => $name,
                'scopes' => $scopes,
                'role' => $role,
                'expires_at' => (new DateTimeImmutable('+90 days'))->format('c'),
                'message' => 'Store this token securely. It cannot be retrieved later.',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET /api/v1/voices
     *
     * Returns curated voice library (public endpoint).
     */
    public function listVoicesAction(Request $request): Response
    {
        $voices = $this->repository->listLibrary();

        return Response::json([
            'voices' => $voices,
            'default_voice' => '21m00Tcm4TlvDq8ikWAM',
            'provider' => 'elevenlabs',
        ]);
    }

    /**
     * POST /api/v1/clone-voice
     *
     * Voice cloning workflow: accepts name + sample file upload, calls
     * Manager::cloneVoice() (which hits provider API), persists the voice.
     */
    public function cloneVoiceAction(Request $request): Response
    {
        $token = $this->validateToken($request, 'tts:generate');
        if ($token === null) {
            return Response::json([
                'error' => 'Unauthorized - valid token with tts:generate scope required',
                'code' => 'invalid_token'
            ], 401);
        }

        $name = trim($request->getPost('name', ''));
        if ($name === '') {
            return Response::json(['error' => 'name parameter is required'], 400);
        }

        if (!isset($_FILES['sample']) || $_FILES['sample']['error'] !== UPLOAD_ERR_OK) {
            return Response::json(['error' => 'sample file upload is required'], 400);
        }

        $sample = $_FILES['sample'];
        $allowed = ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/mp3'];
        if (!in_array($sample['type'], $allowed, true)) {
            return Response::json(['error' => 'Only MP3 and WAV files are allowed for cloning'], 400);
        }

        $samplePath = $sample['tmp_name'];

        try {
            $voiceId = $this->ttsManager->cloneVoice(
                $name,
                $samplePath,
                'elevenlabs',
                $token->userId ?? 1
            );

            return Response::json([
                'success' => true,
                'voice_id' => $voiceId,
                'name' => $name,
                'provider' => 'elevenlabs',
                'message' => 'Voice successfully cloned via provider API and persisted. Use the voice_id in synthesis requests.',
            ]);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            return Response::json(['error' => 'Cloning failed: ' . $e->getMessage()], 502);
        }
    }

    private function validateToken(Request $request, string $requiredScope = ''): ?Token
    {
        $authHeader = $request->getHeader('Authorization') ?? $request->getHeader('X-Api-Key') ?? '';
        $tokenStr = null;

        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            $tokenStr = $matches[1];
        } elseif (str_starts_with($authHeader, 'vxo_')) {
            $tokenStr = $authHeader;
        } else {
            $tokenStr = $request->getPost('token', '');
        }

        if (empty($tokenStr)) {
            return null;
        }

        $token = $this->keyManager->validate($tokenStr);
        if ($token === null) {
            return null;
        }

        if ($requiredScope !== '' && !$token->hasScope($requiredScope)) {
            return null;
        }

        return $token;
    }

    private function parseInput(Request $request): array
    {
        $contentType = strtolower($request->getHeader('Content-Type') ?? '');

        if (str_contains($contentType, 'application/json')) {
            $body = file_get_contents('php://input');
            if ($body === false || trim($body) === '') {
                return [];
            }
            try {
                $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                return is_array($data) ? $data : [];
            } catch (\JsonException) {
                return [];
            }
        }

        // Form-encoded fallback (used by HTMX or simple clients)
        return [
            'text' => $request->getPost('text', ''),
            'voice_id' => $request->getPost('voice_id', ''),
            'provider' => $request->getPost('provider', ''),
            'name' => $request->getPost('name', ''),
            'scopes' => $request->getPost('scopes', ''),
            'role' => $request->getPost('role', ''),
            'normalize' => $request->getPost('normalize', 'true'),
            'format' => $request->getPost('format', 'mp3'),
            'target_loudness' => $request->getPost('target_loudness', '-16'),
        ];
    }

    private function computeHash(string $text, string $voiceId): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId);
        return hash('sha256', $normalized);
    }
}
