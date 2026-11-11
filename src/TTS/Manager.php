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
 * Central orchestration for TTS providers. Implements provider selection,
 * SQLite-based audio caching, usage recording and automatic fallback to
 * the local Piper provider on cloud failures.
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
            $name = $this->getProviderName($provider);
            $this->providers[$name] = $provider;
        }
    }

    private function getProviderName(ProviderInterface $provider): string
    {
        if ($provider instanceof ElevenLabsProvider) {
            return 'elevenlabs';
        }
        if ($provider instanceof PiperProvider) {
            return 'piper';
        }
        return 'default';
    }

    /**
     * Generate speech. Attempts requested provider with automatic fallback
     * to Piper on failure. Caches results in SQLite and records usage.
     */
    public function generate(string $text, string $voiceId, ?string $providerName = null): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text is required.');
        }

        if ($voiceId === '') {
            $voiceId = '21m00Tcm4TlvDq8ikWAM';
        }

        $providerName = $providerName ?? 'elevenlabs';
        $hash = $this->computeHash($text, $voiceId . ':' . $providerName);

        $cached = $this->repository->getCachedSynthesis($hash);
        if ($cached !== null) {
            return new SynthesisResult(
                audioContent: $cached['audio_content'],
                format: $cached['format'] ?? 'mp3',
                duration: isset($cached['duration']) ? (float) $cached['duration'] : null,
                metadata: $cached['metadata'] ?? []
            );
        }

        $result = null;
        $usedProvider = $providerName;

        try {
            if (!isset($this->providers[$providerName])) {
                throw new RuntimeException("Provider '{$providerName}' is not configured.");
            }

            $provider = $this->providers[$providerName];
            $options = [];
            $result = $provider->synthesize($text, $voiceId, $options);
        } catch (RuntimeException $e) {
            if ($providerName !== 'piper' && isset($this->providers['piper'])) {
                try {
                    $piper = $this->providers['piper'];
                    $result = $piper->synthesize($text, $voiceId, []);
                    $usedProvider = 'piper';
                } catch (RuntimeException $fallbackEx) {
                    throw new RuntimeException(
                        'Primary and fallback providers both failed. ' .
                        'Original: ' . $e->getMessage() . ' | Fallback: ' . $fallbackEx->getMessage()
                    );
                }
            } else {
                throw $e;
            }
        }

        if ($result === null) {
            throw new RuntimeException('No synthesis result could be produced.');
        }

        $this->repository->saveGeneration([
            'hash' => $hash,
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $usedProvider,
            'format' => $result->format,
            'duration' => $result->duration,
            'metadata' => $result->metadata,
            'audio_content' => $result->audioContent,
            'audio_size_bytes' => strlen($result->audioContent),
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'user_id' => $_SESSION['user_id'] ?? 1,
        ]);

        return $result;
    }

    private function computeHash(string $text, string $voiceContext): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceContext);
        return hash('sha256', $normalized);
    }
}
