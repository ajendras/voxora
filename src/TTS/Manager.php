<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;

/**
 * Manager
 *
 * Central service for TTS operations. Routes requests to the appropriate
 * ProviderInterface implementation, handles caching, usage logging, fallback
 * to Piper, and post-processing. Supports the new OpenAIProvider with
 * voice listing and SSML compatibility.
 */
final class Manager
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    private SqliteRepository $repository;
    private ?WebhookDispatcher $webhookDispatcher;
    private array $config;

    public function __construct(
        SqliteRepository $repository,
        array $config,
        ?WebhookDispatcher $webhookDispatcher = null
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->webhookDispatcher = $webhookDispatcher;
    }

    public function registerProvider(string $name, ProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function generate(
        string $text,
        string $voiceId,
        ?string $providerName = null,
        array $options = []
    ): SynthesisResult {
        $providerName = $providerName ?? 'elevenlabs';
        $provider = $this->getProvider($providerName);

        $hash = $this->computeHash($text, $voiceId, $providerName);

        if ($cached = $this->repository->getCachedSynthesis($hash)) {
            return SynthesisResult::fromCache($cached);
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

        if (!empty($options['normalize']) || ($options['format'] ?? '') === 'mp3') {
            $result = $result->process($options);
        }

        $this->repository->cacheSynthesis($hash, $result);

        $this->repository->recordUsage([
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $providerName,
            'duration' => $result->duration,
            'user_id' => $options['user_id'] ?? null,
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        if ($this->webhookDispatcher !== null) {
            $this->webhookDispatcher->dispatchSuccess($result, [
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => $providerName,
            ]);
        }

        return $result;
    }

    public function cloneVoice(
        string $name,
        string $samplePath,
        string $providerName = 'elevenlabs',
        ?int $userId = null
    ): string {
        $provider = $this->getProvider($providerName);
        $voiceId = $provider->cloneVoice($name, $samplePath, $userId);

        $this->repository->saveCustomVoice([
            'id' => $voiceId,
            'name' => $name,
            'provider' => $providerName,
            'is_cloned' => true,
            'user_id' => $userId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $voiceId;
    }

    /**
     * Returns voices for a provider (built-in + cloned voices from repository).
     */
    public function listVoices(string $providerName = 'elevenlabs'): array
    {
        $provider = $this->getProvider($providerName);
        $voices = $provider->listVoices();
        $cloned = $this->repository->listClonedVoices($providerName);

        return array_merge($voices, $cloned);
    }

    private function getProvider(string $name): ProviderInterface
    {
        if (!isset($this->providers[$name])) {
            throw new InvalidArgumentException("No provider registered for: {$name}. Available: " . implode(', ', array_keys($this->providers)));
        }

        return $this->providers[$name];
    }

    private function computeHash(string $text, string $voiceId, string $provider): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId) . '::' . $provider;
        return hash('sha256', $normalized);
    }
}
