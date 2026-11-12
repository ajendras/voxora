<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Manager
 *
 * Orchestrates TTS providers, caching, usage tracking and fallback.
 * Now includes voice cloning workflow.
 */
final class Manager
{
    private SqliteRepository $repository;

    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(SqliteRepository $repository, ProviderInterface ...$providers)
    {
        $this->repository = $repository;
        foreach ($providers as $provider) {
            $this->providers[$provider->getName()] = $provider;
        }

        if (empty($this->providers)) {
            throw new RuntimeException('At least one TTS provider is required.');
        }
    }

    public function generate(string $text, string $voiceId, ?string $provider = null): SynthesisResult
    {
        $providerName = $provider ?? 'elevenlabs';
        if (!isset($this->providers[$providerName])) {
            $providerName = array_key_first($this->providers);
        }

        $providerInstance = $this->providers[$providerName];
        $hash = $this->computeHash($text, $voiceId, $providerName);

        $cached = $this->repository->getCachedResult($hash);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $options = [];
            $result = $providerInstance->synthesize($text, $voiceId, $options);

            $this->repository->cacheResult($hash, $result);
            $this->repository->saveGeneration([
                'hash' => $hash,
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => $providerName,
                'format' => $result->format,
                'duration' => $result->duration,
                'metadata' => $result->metadata,
                'audio_size_bytes' => strlen($result->audioContent),
                'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'user_id' => 1,
            ]);

            return $result;
        } catch (RuntimeException $e) {
            if ($providerName !== 'piper' && isset($this->providers['piper'])) {
                return $this->providers['piper']->synthesize($text, $voiceId, $options ?? []);
            }
            throw $e;
        }
    }

    public function cloneVoice(string $name, string $samplePath, string $provider = 'elevenlabs', ?int $userId = null): string
    {
        if (!isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Provider '{$provider}' is not configured.");
        }

        $providerInstance = $this->providers[$provider];
        $voiceId = $providerInstance->cloneVoice($name, $samplePath);

        $this->repository->saveCustomVoice([
            'voice_id' => $voiceId,
            'name' => $name,
            'provider' => $provider,
            'is_cloned' => true,
            'user_id' => $userId ?? 1,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $voiceId;
    }

    private function computeHash(string $text, string $voiceId, string $provider): string
    {
        return hash('sha256', strtolower(trim($text)) . '::' . trim($voiceId) . '::' . $provider);
    }
}
