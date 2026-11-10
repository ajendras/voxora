<?php

declare(strict_types=1);

namespace Voxora\TTS;

/**
 * ProviderInterface
 *
 * Defines the contract for all Text-to-Speech providers (ElevenLabs, OpenAI, Azure, local, etc).
 * Implementations must return a SynthesisResult containing binary audio and metadata.
 */
interface ProviderInterface
{
    /**
     * Synthesize speech from text using the given voice and options.
     *
     * @param string $text Text to be spoken (max length enforced by provider)
     * @param array $voice Voice descriptor: ['id' => string, 'name' => string, 'provider' => string, ...]
     * @param array $options Provider-specific options (stability, similarity_boost, speed, model, etc.)
     * @return SynthesisResult Binary audio data and associated metadata
     * @throws \RuntimeException When the provider API returns an error or audio cannot be generated
     */
    public function synthesize(string $text, array $voice, array $options = []): SynthesisResult;
}

/**
 * SynthesisResult
 *
 * Immutable value object returned by all TTS providers. Contains the generated audio
 * and metadata for caching, analytics, and file storage.
 */
final class SynthesisResult
{
    /**
     * @param string $audioContent Raw binary audio content (MP3 by default)
     * @param string $format File format (mp3, wav, etc.)
     * @param float|null $duration Duration in seconds (if known from provider response)
     * @param array $metadata Additional provider-specific data (voice_id, model, usage, etc.)
     */
    public function __construct(
        public readonly string $audioContent,
        public readonly string $format = 'mp3',
        public readonly ?float $duration = null,
        public readonly array $metadata = []
    ) {
        if (empty($audioContent)) {
            throw new \InvalidArgumentException('Audio content cannot be empty.');
        }
        if (!in_array($format, ['mp3', 'wav'], true)) {
            throw new \InvalidArgumentException('Unsupported audio format: ' . $format);
        }
    }

    /**
     * Persist the audio to a file (creates directory if needed).
     */
    public function save(string $filepath): bool
    {
        $directory = dirname($filepath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new \RuntimeException('Failed to create audio directory: ' . $directory);
            }
        }

        return file_put_contents($filepath, $this->audioContent) !== false;
    }

    /**
     * Returns a data URI suitable for browser preview.
     */
    public function toDataUri(): string
    {
        $mime = $this->format === 'mp3' ? 'audio/mpeg' : 'audio/wav';
        return 'data:' . $mime . ';base64,' . base64_encode($this->audioContent);
    }
}
