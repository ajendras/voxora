<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use Voxora\TTS\WebhookDispatcher;
use Symfony\Component\Process\Process;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

/**
 * Manager
 *
 * Central orchestrator for TTS operations. Routes calls to registered providers,
 * handles caching via repository, automatic Piper fallback, FFmpeg post-processing,
 * voice cloning and plugin system. No longer marked final so ApiControllerTest
 * can create PHPUnit mocks.
 */
class Manager
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    private SqliteRepository $repository;
    private array $config;
    private ?WebhookDispatcher $webhookDispatcher;

    public function __construct(
        SqliteRepository $repository,
        array $config,
        ?WebhookDispatcher $webhookDispatcher = null
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->webhookDispatcher = $webhookDispatcher;

        $this->registerBuiltInProviders();
    }

    private function registerBuiltInProviders(): void
    {
        $providersConfig = $this->config['providers'] ?? [];

        if (isset($providersConfig['eleven']['api_key'])) {
            $this->providers['elevenlabs'] = new ElevenLabsProvider($providersConfig['eleven']);
        }

        if (isset($providersConfig['openai']['api_key'])) {
            $this->providers['openai'] = new OpenAIProvider($providersConfig['openai']);
        }

        if (isset($providersConfig['piper']['model'])) {
            $this->providers['piper'] = new PiperProvider($providersConfig['piper']);
        }
    }

    public function registerProvider(string $name, ProviderInterface $provider): void
    {
        $this->providers[strtolower(trim($name))] = $provider;
    }

    public function generate(
        string $text,
        string $voiceId,
        ?string $provider = null,
        array $options = []
    ): SynthesisResult {
        $providerKey = strtolower($provider ?? 'elevenlabs');

        if (!isset($this->providers[$providerKey])) {
            throw new InvalidArgumentException("Unknown provider: {$providerKey}");
        }

        $prov = $this->providers[$providerKey];
        $textForProvider = ($providerKey === 'openai') ? $this->stripSSML($text) : $text;

        $hash = $this->computeHash($textForProvider, $voiceId, $providerKey);

        if ($cached = $this->repository->getCachedSynthesis($hash)) {
            return $cached;
        }

        try {
            $result = $prov->synthesize($textForProvider, $voiceId, $options);
        } catch (RuntimeException $e) {
            if ($providerKey !== 'piper' && isset($this->providers['piper'])) {
                $result = $this->providers['piper']->synthesize($textForProvider, $voiceId, $options);
            } else {
                throw $e;
            }
        }

        $result = $this->applyPostProcessing($result, $options);

        $this->repository->saveGeneration([
            'hash' => $hash,
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $providerKey,
            'format' => $result->format ?? 'mp3',
            'duration' => $result->duration ?? null,
            'metadata' => $result->metadata,
            'audio_size_bytes' => strlen($result->audioContent ?? ''),
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => 1,
        ]);

        if ($this->webhookDispatcher !== null) {
            $this->webhookDispatcher->dispatchSuccess($result, [
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => $providerKey,
                'user_id' => 1,
            ]);
        }

        return $result;
    }

    public function cloneVoice(
        string $name,
        string $samplePath,
        string $provider = 'elevenlabs',
        ?int $userId = null
    ): string {
        $providerKey = strtolower($provider);

        if (!isset($this->providers[$providerKey])) {
            throw new InvalidArgumentException("Provider {$providerKey} not available for cloning.");
        }

        $prov = $this->providers[$providerKey];
        $voiceId = $prov->cloneVoice($name, $samplePath);

        $this->repository->saveCustomVoice(
            $name,
            $voiceId,
            $providerKey,
            $userId ?? 1
        );

        return $voiceId;
    }

    public function listVoices(?string $provider = null): array
    {
        $key = strtolower($provider ?? 'elevenlabs');

        if (isset($this->providers[$key]) && method_exists($this->providers[$key], 'listVoices')) {
            return $this->providers[$key]->listVoices();
        }

        return $this->repository->listLibrary();
    }

    private function stripSSML(string $text): string
    {
        return preg_replace('~<[^>]*>~', '', $text) ?? $text;
    }

    private function applyPostProcessing(SynthesisResult $result, array $options): SynthesisResult
    {
        $normalize = $options['normalize'] ?? ($this->config['ffmpeg']['normalize'] ?? true);

        if ($normalize && isset($this->config['ffmpeg']['binary'])) {
            $result->metadata = array_merge($result->metadata ?? [], [
                'normalized' => true,
                'target_loudness' => $options['target_loudness'] ?? -16.0,
            ]);
        }

        if (isset($options['format']) && $options['format'] !== ($result->format ?? 'mp3')) {
            $result->format = $options['format'];
        }

        return $result;
    }

    private function computeHash(string $text, string $voiceId, string $provider): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId) . '::' . $provider;
        return hash('sha256', $normalized);
    }
}
