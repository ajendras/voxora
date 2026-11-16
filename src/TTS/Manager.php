<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;

/**
 * Manager
 *
 * Central orchestration for all TTS operations. Implements the plugin system
 * via registerProvider() so new ProviderInterface implementations can be
 * added at runtime. Built-in providers are registered by Kernel. Handles
 * caching, usage recording, cloud-to-local fallback, FFmpeg post-processing
 * and webhook dispatch.
 */
final class Manager
{
    private SqliteRepository $repository;
    private array $config;
    private WebhookDispatcher $webhookDispatcher;

    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(
        SqliteRepository $repository,
        array $config,
        WebhookDispatcher $webhookDispatcher
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->webhookDispatcher = $webhookDispatcher;
    }

    /**
     * Register a new TTS provider at runtime.
     *
     * This is the entry point of the plugin system. After Kernel::boot() any
     * class implementing ProviderInterface can be registered under a unique
     * name. The name can then be used in generate(), cloneVoice(), the
     * dashboard dropdown and API calls.
     *
     * @param string $name Unique provider name (e.g. "openai", "azure")
     * @param ProviderInterface $provider Fully configured instance
     * @return self Fluent interface
     */
    public function registerProvider(string $name, ProviderInterface $provider): self
    {
        $normalized = strtolower(trim($name));
        $this->providers[$normalized] = $provider;
        return $this;
    }

    /**
     * Generate speech. Uses registered provider (plugin system), falls back
     * to Piper on cloud errors, applies caching and post-processing.
     */
    public function generate(
        string $text,
        string $voiceId,
        ?string $provider = null,
        array $options = []
    ): SynthesisResult {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }
        if (trim($voiceId) === '') {
            throw new InvalidArgumentException('voiceId cannot be empty.');
        }

        $providerName = strtolower(trim($provider ?? 'elevenlabs'));
        $providerInstance = $this->resolveProvider($providerName);

        $hash = $this->computeHash($text, $voiceId, $providerName);

        $cached = $this->repository->findGenerationByHash($hash);
        if ($cached !== null && !empty($cached['audio_path']) && file_exists($cached['audio_path'])) {
            $audioContent = file_get_contents($cached['audio_path']);
            if ($audioContent !== false) {
                return new SynthesisResult(
                    audioContent: $audioContent,
                    format: $cached['format'] ?? 'mp3',
                    duration: (float) ($cached['duration'] ?? 0.0),
                    metadata: $cached['metadata'] ?? []
                );
            }
        }

        $defaultOptions = [
            'normalize' => $this->config['ffmpeg']['normalize'] ?? true,
            'target_loudness' => $this->config['ffmpeg']['target_loudness'] ?? -16.0,
            'format' => 'mp3',
        ];
        $options = array_merge($defaultOptions, $options);

        try {
            $result = $providerInstance->synthesize($text, $voiceId, $options);
        } catch (\Throwable $e) {
            if ($providerName !== 'piper' && isset($this->providers['piper'])) {
                $providerInstance = $this->providers['piper'];
                $result = $providerInstance->synthesize(
                    $text,
                    $voiceId,
                    array_merge($options, ['format' => 'wav'])
                );
            } else {
                throw new RuntimeException('Synthesis failed: ' . $e->getMessage(), 0, $e);
            }
        }

        if (($options['normalize'] ?? false) || ($options['format'] ?? 'mp3') !== 'wav') {
            $result = $result->process($options);
        }

        $audioDir = $this->config['audio']['directory'] ?? __DIR__ . '/../../storage/audio';
        if (!is_dir($audioDir)) {
            mkdir($audioDir, 0755, true);
        }
        $ext = $result->format ?? 'mp3';
        $filename = 'tts-' . substr($hash, 0, 12) . '.' . $ext;
        $fullPath = rtrim($audioDir, '/\\') . '/' . $filename;
        $result->save($fullPath);

        $this->repository->saveGeneration([
            'hash' => $hash,
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $providerName,
            'format' => $result->format ?? 'mp3',
            'duration' => $result->duration ?? 0.0,
            'metadata' => $result->metadata ?? [],
            'audio_size_bytes' => strlen($result->audioContent ?? ''),
            'audio_path' => $fullPath,
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => 1,
        ]);

        $publicUrl = ($this->config['audio']['public_url_prefix'] ?? '/audio') . '/' . $filename;
        $this->webhookDispatcher->dispatchSuccess($result, [
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $providerName,
            'audio_url' => $publicUrl,
            'user_id' => 1,
            'filename' => $filename,
        ]);

        return $result;
    }

    /**
     * Clone a voice using the selected provider. Persists the voice in SQLite.
     */
    public function cloneVoice(
        string $name,
        string $samplePath,
        string $provider = 'elevenlabs',
        ?int $userId = null
    ): string {
        if (!is_file($samplePath)) {
            throw new InvalidArgumentException('Sample file does not exist.');
        }

        $providerName = strtolower(trim($provider));
        $providerInstance = $this->resolveProvider($providerName);

        $voiceId = $providerInstance->cloneVoice($name, $samplePath);

        $this->repository->saveCustomVoice([
            'voice_id' => $voiceId,
            'name' => trim($name),
            'provider' => $providerName,
            'is_cloned' => 1,
            'user_id' => $userId ?? 1,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $voiceId;
    }

    private function resolveProvider(string $name): ProviderInterface
    {
        if (isset($this->providers[$name])) {
            return $this->providers[$name];
        }

        throw new InvalidArgumentException("No provider registered for '{$name}'. Use registerProvider() to add plugins.");
    }

    private function computeHash(string $text, string $voiceId, string $provider): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId) . '::' . $provider;
        return hash('sha256', $normalized);
    }

    /**
     * Return names of all registered providers (used by UI and API).
     *
     * @return string[]
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }
}
