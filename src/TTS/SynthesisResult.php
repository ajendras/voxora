<?php

declare(strict_types=1);

namespace Voxora\TTS;

use RuntimeException;

/**
 * SynthesisResult
 *
 * Immutable value object holding generated audio bytes and metadata.
 * Supports MP3 from cloud providers and WAV from Piper.
 */
final class SynthesisResult
{
    public function __construct(
        public readonly string $audioContent,
        public readonly string $format,
        public readonly ?float $duration = null,
        public readonly array $metadata = []
    ) {
        if ($this->audioContent === '') {
            throw new RuntimeException('Audio content cannot be empty.');
        }

        if (!in_array($this->format, ['mp3', 'wav'], true)) {
            throw new RuntimeException('Unsupported audio format: ' . $this->format);
        }
    }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Failed to create directory for audio: ' . $directory);
            }
        }

        if (file_put_contents($path, $this->audioContent) === false) {
            throw new RuntimeException('Failed to write audio file to ' . $path);
        }
    }

    public function toDataUri(): string
    {
        $mime = $this->format === 'wav' ? 'audio/wav' : 'audio/mpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($this->audioContent);
    }
}
