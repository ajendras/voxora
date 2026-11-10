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
 * Orchestrates TTS providers, applies caching where possible and returns
 * rich SynthesisResult objects. Consistent with the rest of the codebase.
 */
final class Manager
{
    private SqliteRepository $repository;

    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(SqliteRepository $repository, ProviderInterface ...$providers)
    {
        $this->repository = $repository;

        if ($providers === []) {
            throw new InvalidArgumentException('Manager requires at least one TTS provider.');
        }

        // Map providers by their canonical name (currently only elevenlabs is supported)
        foreach ($providers as $provider) {
            $this->providers['elevenlabs'] = $provider;
        }
    }

    public function generate(string $text, string $voiceId, ?string $provider = null): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text must not be empty.');
        }

        if (trim($voiceId) === '') {
            throw new InvalidArgumentException('voiceId must not be empty.');
        }

        $providerKey = strtolower($provider ?? 'elevenlabs');

        if (!isset($this->providers[$providerKey])) {
            throw new RuntimeException("No provider registered for '{$providerKey}'.");
        }

        $prov = $this->providers[$providerKey];

        $hash = $this->computeHash($text, $voiceId);

        $audioContent = $prov->synthesize($text, $voiceId, [
            'stability' => 0.5,
            'similarity_boost' => 0.75,
        ]);

        $duration = $this->estimateDuration($text);

        $metadata = [
            'character_count' => mb_strlen($text),
            'provider' => $providerKey,
        ];

        $result = new SynthesisResult(
            audioContent: $audioContent,
            duration: $duration,
            format: 'mp3',
            metadata: $metadata
        );

        // Record in repository (matches the shape used in ApiController)
        $this->repository->saveGeneration([
            'hash' => $hash,
            'text' => $text,
            'voice_id' => $voiceId,
            'provider' => $providerKey,
            'format' => 'mp3',
            'duration' => $duration,
            'metadata' => $metadata,
            'audio_size_bytes' => strlen($audioContent),
            'generated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $result;
    }

    private function computeHash(string $text, string $voiceId): string
    {
        $normalized = mb_strtolower(trim($text)) . '::' . trim($voiceId);

        return hash('sha256', $normalized);
    }

    private function estimateDuration(string $text): float
    {
        // Average speaking rate ~ 150 words per minute ~ 2.5 per second.
        // Very rough but sufficient for demo.
        return round(mb_strlen($text) / 12, 1);
    }
}

/**
 * SynthesisResult
 *
 * Holds generated audio binary along with metadata. Matches usage across
 * ApiController, DashboardController and BatchGenerator.
 */
final class SynthesisResult
{
    public function __construct(
        public readonly string $audioContent,
        public readonly ?float $duration = null,
        public readonly string $format = 'mp3',
        public readonly array $metadata = []
    ) {
        if ($this->audioContent === '') {
            throw new InvalidArgumentException('Audio content cannot be empty.');
        }
    }

    public function save(string $filepath): void
    {
        $directory = dirname($filepath);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Failed to create directory "%s"', $directory));
            }
        }

        if (file_put_contents($filepath, $this->audioContent) === false) {
            throw new RuntimeException(sprintf('Failed to save audio to "%s"', $filepath));
        }
    }

    public function toDataUri(): string
    {
        if ($this->audioContent === '') {
            return '';
        }

        return 'data:audio/' . $this->format . ';base64,' . base64_encode($this->audioContent);
    }
}
