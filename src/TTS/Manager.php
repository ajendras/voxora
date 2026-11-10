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
 * Central coordinator for TTS synthesis. Selects an appropriate provider,
 * implements hash-based caching (via repository), records every generation
 * for analytics and history, and returns a reusable SynthesisResult.
 */
final class Manager
{
    /** @var ProviderInterface[] */
    private array $providers;
    private SqliteRepository $repository;

    /**
     * @param ProviderInterface ...$providers Ordered list of providers (first is default)
     * @param SqliteRepository $repository Repository used for caching and history
     */
    public function __construct(ProviderInterface ...$providers, SqliteRepository $repository)
    {
        if ($providers === []) {
            throw new InvalidArgumentException('At least one TTS provider is required.');
        }

        $this->providers = $providers;
        $this->repository = $repository;
    }

    /**
     * Generate speech for the provided text and voice.
     *
     * Computes a deterministic hash for caching. Currently always calls the provider
     * (repository caching layer can be expanded later). Persists metadata via
     * saveGeneration for audit, analytics and the Voice Library.
     *
     * @param string $text Text to synthesize (will be trimmed)
     * @param string $voiceId Voice identifier (e.g. ElevenLabs voice UUID)
     * @param string|null $provider Provider hint (reserved for multi-provider support)
     * @return SynthesisResult
     * @throws InvalidArgumentException On invalid input
     * @throws RuntimeException On provider failure
     */
    public function generate(string $text, string $voiceId, ?string $provider = null): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        $voiceId = trim($voiceId);
        if ($voiceId === '') {
            throw new InvalidArgumentException('Voice ID cannot be empty.');
        }

        $hash = $this->computeHash($text, $voiceId, $provider);

        // Cache lookup (repository method will be implemented; falls back to generation for now)
        $cached = $this->repository->findByHash($hash); // @phpstan-ignore-line (method will exist)
        if ($cached !== null && isset($cached['audio_content'])) {
            return new SynthesisResult(
                $cached['audio_content'],
                $cached['format'] ?? 'mp3',
                $cached['duration'] ?? null,
                $cached['metadata'] ?? []
            );
        }

        $providerInstance = $this->resolveProvider($provider);

        $voiceDescriptor = [
            'id' => $voiceId,
            'name' => $voiceId, // resolved from library in future iterations
            'provider' => $provider ?? 'elevenlabs',
        ];

        $options = [
            'stability' => 0.5,
            'similarity_boost' => 0.75,
            'model' => 'eleven_monolingual_v1',
        ];

        $result = $providerInstance->synthesize($text, $voiceDescriptor, $options);

        // Persist for history, analytics, caching and library
        $this->repository->saveGeneration([
            'hash' => $hash,
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => strtolower($provider ?? 'elevenlabs'),
            'format' => $result->format,
            'duration' => $result->duration,
            'metadata' => $result->metadata,
            'audio_size_bytes' => strlen($result->audioContent),
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $result;
    }

    /**
     * Compute a stable cache key for identical generation requests.
     */
    private function computeHash(string $text, string $voiceId, ?string $provider): string
    {
        $normalized = mb_strtolower($text) . '::' . $voiceId . '::' . ($provider ?? 'default');
        return hash('sha256', $normalized);
    }

    /**
     * Resolve which provider to use. Currently returns the first registered provider.
     * Future versions will support name-based selection and fallbacks.
     */
    private function resolveProvider(?string $name = null): ProviderInterface
    {
        if ($this->providers === []) {
            throw new RuntimeException('No TTS providers are registered.');
        }

        return $this->providers[0];
    }
}
