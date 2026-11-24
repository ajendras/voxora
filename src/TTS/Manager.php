<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use RuntimeException;
use InvalidArgumentException;

/**
 * Manager
 *
 * Central facade for all TTS operations. Routes to registered providers,
 * handles result caching in SQLite, records usage for analytics, applies
 * FFmpeg post-processing, supports fallback to Piper and dispatches webhooks.
 * Provider specific parameters (stability, similarity, style, speed) are
 * normalized, clamped and mapped by normalizeOptions() before being passed
 * to the underlying provider.
 */
final class Manager
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
        $elevenConfig = $this->config['providers']['eleven'] ?? $this->config['providers']['elevenlabs'] ?? [];
        $this->providers['elevenlabs'] = new ElevenLabsProvider($elevenConfig);

        $openaiConfig = $this->config['providers']['openai'] ?? [];
        $this->providers['openai'] = new OpenAIProvider($openaiConfig);

        $piperConfig = $this->config['providers']['piper'] ?? [];
        $this->providers['piper'] = new PiperProvider($piperConfig);
    }

    public function registerProvider(string $name, ProviderInterface $provider): void
    {
        $this->providers[strtolower(trim($name))] = $provider;
    }

    /**
     * Generate speech using the selected provider.
     *
     * Provider-specific parameters (stability, similarity, style, speed) are
     * automatically normalized, clamped and mapped by the Manager before
     * being forwarded to the provider's synthesize() method.
     */
    public function generate(
        string $text,
        string $voiceId,
        ?string $provider = null,
        array $options = []
    ): SynthesisResult {
        $providerName = $this->resolveProviderName($provider);

        if (!isset($this->providers[$providerName])) {
            throw new InvalidArgumentException("Provider '{$providerName}' is not registered.");
        }

        $providerInstance = $this->providers[$providerName];
        $normalizedOptions = $this->normalizeOptions($providerName, $options);

        $cacheKey = $this->createCacheKey($text, $voiceId, $providerName, $normalizedOptions);

        if ($cached = $this->repository->findCachedResult($cacheKey)) {
            return $cached;
        }

        try {
            $result = $providerInstance->synthesize($text, $voiceId, $normalizedOptions);

            if (!empty($normalizedOptions['normalize'])) {
                $result = $result->process($normalizedOptions);
            }

            $this->repository->cacheResult($cacheKey, $result, [
                'text' => substr($text, 0, 255),
                'voice_id' => $voiceId,
                'provider' => $providerName,
                'duration' => $result->duration ?? 0.0,
                'user_id' => $normalizedOptions['user_id'] ?? null,
            ]);

            if ($this->webhookDispatcher !== null) {
                $context = [
                    'text' => $text,
                    'voice_id' => $voiceId,
                    'provider' => $providerName,
                    'options' => $normalizedOptions,
                ];
                $this->webhookDispatcher->dispatchSuccess($result, $context);
            }

            return $result;
        } catch (RuntimeException $e) {
            if ($providerName !== 'piper' && ($this->config['fallback']['enabled'] ?? true)) {
                return $this->providers['piper']->synthesize($text, $voiceId, $normalizedOptions);
            }
            throw $e;
        }
    }

    private function resolveProviderName(?string $provider): string
    {
        if ($provider === null) {
            return strtolower($this->config['default_provider'] ?? 'elevenlabs');
        }

        return strtolower(trim($provider));
    }

    /**
     * Normalizes provider-specific parameters (stability, similarity, style, speed).
     * Clamps values to valid ranges, maps "similarity" to "similarity_boost" for
     * ElevenLabs, pulls defaults from config when available, and removes
     * unsupported keys for local providers.
     */
    private function normalizeOptions(string $providerName, array $options): array
    {
        $providerConfig = $this->config['providers'][$providerName] ?? $this->config['providers']['elevenlabs'] ?? [];

        $defaults = [
            'format' => 'mp3',
            'normalize' => $this->config['ffmpeg']['normalize'] ?? true,
            'target_loudness' => $this->config['ffmpeg']['target_loudness'] ?? -16.0,
            'speed' => $providerConfig['speed'] ?? 1.0,
            'stability' => $providerConfig['stability'] ?? 0.5,
            'similarity' => $providerConfig['similarity'] ?? 0.75,
            'style' => $providerConfig['style'] ?? 0.0,
        ];

        $options = array_merge($defaults, $options);

        // Clamp values to safe ranges
        $options['stability'] = max(0.0, min(1.0, (float) $options['stability']));
        $options['similarity'] = max(0.0, min(1.0, (float) $options['similarity']));
        $options['style'] = max(0.0, min(1.0, (float) $options['style']));
        $options['speed'] = (float) $options['speed'];

        if ($providerName === 'elevenlabs' || $providerName === 'eleven') {
            if (!isset($options['similarity_boost'])) {
                $options['similarity_boost'] = $options['similarity'];
            }
            $options['speed'] = max(0.5, min(2.0, $options['speed']));
        } elseif ($providerName === 'openai') {
            $options['speed'] = max(0.25, min(4.0, $options['speed']));
        } elseif ($providerName === 'piper') {
            // Piper does not use these parameters
            unset(
                $options['stability'],
                $options['similarity'],
                $options['style'],
                $options['speed'],
                $options['similarity_boost']
            );
        }

        return $options;
    }

    private function createCacheKey(string $text, string $voiceId, string $provider, array $options): string
    {
        $relevant = [
            'text' => md5($text),
            'voice' => $voiceId,
            'provider' => $provider,
            'format' => $options['format'] ?? 'mp3',
            'stability' => $options['stability'] ?? null,
            'similarity' => $options['similarity'] ?? null,
            'style' => $options['style'] ?? null,
            'speed' => $options['speed'] ?? null,
        ];

        return hash('sha256', json_encode($relevant, JSON_THROW_ON_ERROR));
    }

    public function listVoices(?string $provider = null): array
    {
        if ($provider !== null) {
            $name = strtolower(trim($provider));
            if (isset($this->providers[$name])) {
                return $this->providers[$name]->listVoices();
            }
            throw new InvalidArgumentException("Unknown provider: {$provider}");
        }

        $all = [];
        foreach ($this->providers as $name => $prov) {
            $voices = $prov->listVoices();
            foreach ($voices as $v) {
                $v['provider'] = $name;
                $all[] = $v;
            }
        }
        return $all;
    }

    public function cloneVoice(
        string $name,
        string $samplePath,
        ?string $provider = null,
        array $options = []
    ): string {
        $providerName = $this->resolveProviderName($provider);

        if (!isset($this->providers[$providerName])) {
            throw new InvalidArgumentException("Provider '{$providerName}' is not registered.");
        }

        $voiceId = $this->providers[$providerName]->cloneVoice($name, $samplePath, $options);
        $this->repository->saveCustomVoice($voiceId, $name, $providerName, $options);

        return $voiceId;
    }

    public function listProviders(): array
    {
        return array_keys($this->providers);
    }

    public function getProvider(string $name): ProviderInterface
    {
        $name = strtolower(trim($name));
        if (!isset($this->providers[$name])) {
            throw new InvalidArgumentException("Provider '{$name}' not registered.");
        }
        return $this->providers[$name];
    }
}
