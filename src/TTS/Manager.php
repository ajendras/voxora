<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use RuntimeException;
use InvalidArgumentException;

/**
 * Manager
 *
 * Central orchestration layer for TTS providers, caching, fallback, post-processing
 * and usage tracking. Supports runtime plugin registration.
 */
class Manager
{
    private SqliteRepository $repository;
    private array $config;
    private array $providers = [];
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
        if (isset($providersConfig['eleven']) || isset($providersConfig['elevenlabs'])) {
            $elevenConfig = $providersConfig['eleven'] ?? $providersConfig['elevenlabs'] ?? [];
            $this->registerProvider('elevenlabs', new ElevenLabsProvider($elevenConfig));
        }
        if (isset($providersConfig['openai'])) {
            $this->registerProvider('openai', new OpenAIProvider($providersConfig['openai']));
        }
        if (isset($providersConfig['piper'])) {
            $this->registerProvider('piper', new PiperProvider($providersConfig['piper']));
        }
    }

    public function registerProvider(string $name, ProviderInterface $provider): self
    {
        $this->providers[$name] = $provider;
        return $this;
    }

    public function generate(string $text, string $voiceId, ?string $providerName = null, array $options = []): SynthesisResult
    {
        $providerName = $providerName ?? 'elevenlabs';
        if (!isset($this->providers[$providerName])) {
            throw new InvalidArgumentException("Unknown provider: {$providerName}");
        }

        $provider = $this->providers[$providerName];
        $cacheKey = hash('sha256', $text . '|' . $voiceId . '|' . $providerName . '|' . json_encode($options));

        $cached = $this->repository->getCachedSynthesis($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $result = $provider->synthesize($text, $voiceId, $options);
        } catch (RuntimeException $e) {
            if ($providerName !== 'piper' && isset($this->providers['piper'])) {
                $result = $this->providers['piper']->synthesize($text, $voiceId, $options);
            } else {
                throw $e;
            }
        }

        if (!empty($options['normalize']) || isset($options['format'])) {
            $result = $result->process($options);
        }

        $this->repository->cacheSynthesis($cacheKey, $result);
        $this->repository->recordUsage($text, $voiceId, $providerName, (float) ($result->duration ?? 0.0));

        if ($this->webhookDispatcher !== null) {
            $context = [
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => $providerName,
                'duration' => $result->duration,
            ];
            $this->webhookDispatcher->dispatchSuccess($result, $context);
        }

        return $result;
    }

    public function cloneVoice(string $name, string $samplePath, string $providerName = 'elevenlabs'): string
    {
        if (!isset($this->providers[$providerName])) {
            throw new InvalidArgumentException("Unknown provider: {$providerName}");
        }
        $provider = $this->providers[$providerName];
        $voiceId = $provider->cloneVoice($name, $samplePath);
        $this->repository->saveCustomVoice($name, $voiceId, true, $providerName);
        return $voiceId;
    }

    public function listVoices(?string $providerName = null): array
    {
        if ($providerName !== null) {
            if (!isset($this->providers[$providerName])) {
                throw new InvalidArgumentException("Unknown provider: {$providerName}");
            }
            return $this->providers[$providerName]->listVoices();
        }

        $allVoices = [];
        foreach ($this->providers as $name => $provider) {
            $voices = $provider->listVoices();
            foreach ($voices as $voice) {
                $voice['provider'] = $name;
                $allVoices[] = $voice;
            }
        }
        return $allVoices;
    }

    public function listProviders(): array
    {
        return array_keys($this->providers);
    }
}
