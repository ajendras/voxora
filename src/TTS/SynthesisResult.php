<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * SynthesisResult
 *
 * Immutable value object holding generated audio data and metadata.
 * Now includes FFmpeg-powered post-processing for loudness normalization
 * (EBU R128 loudnorm filter), format conversion and duration extraction.
 */
final class SynthesisResult
{
    public function __construct(
        public readonly string $audioContent,
        public readonly string $format = 'mp3',
        public readonly ?float $duration = null,
        public readonly array $metadata = []
    ) {
        if (empty($this->audioContent)) {
            throw new InvalidArgumentException('Audio content cannot be empty.');
        }
    }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Failed to create directory: ' . $directory);
            }
        }

        if (file_put_contents($path, $this->audioContent) === false) {
            throw new RuntimeException('Failed to write audio to ' . $path);
        }
    }

    public function toDataUri(): string
    {
        $mime = match (strtolower($this->format)) {
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => 'audio/mpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($this->audioContent);
    }

    /**
     * Post-process audio with FFmpeg.
     *
     * Supports normalization (loudnorm filter), format conversion (mp3/wav)
     * and returns a new immutable instance. Gracefully degrades when FFmpeg
     * is unavailable (returns original unless normalization was explicitly requested).
     *
     * @param array $options Keys: normalize (bool), target_loudness (float), format (string), bitrate (string)
     * @param string $ffmpegBinary Path to ffmpeg executable
     * @return self
     */
    public function process(array $options = [], string $ffmpegBinary = 'ffmpeg'): self
    {
        $normalize = $options['normalize'] ?? true;
        $targetLoudness = (float) ($options['target_loudness'] ?? -16.0);
        $targetFormat = strtolower($options['format'] ?? $this->format);
        $bitrate = $options['bitrate'] ?? ($targetFormat === 'mp3' ? '192k' : null);

        if (!$normalize && $targetFormat === $this->format) {
            return $this;
        }

        if (!self::isAvailable($ffmpegBinary)) {
            if ($normalize) {
                throw new RuntimeException('FFmpeg is required for normalization but is not available in PATH.');
            }
            return $this; // format change skipped when ffmpeg unavailable
        }

        $inputExt = $this->format === 'mp3' ? 'mp3' : 'wav';
        $outputExt = $targetFormat === 'mp3' ? 'mp3' : 'wav';

        $tmpInput = tempnam(sys_get_temp_dir(), 'vox_in_') . '.' . $inputExt;
        $tmpOutput = tempnam(sys_get_temp_dir(), 'vox_out_') . '.' . $outputExt;

        try {
            if (file_put_contents($tmpInput, $this->audioContent) === false) {
                throw new RuntimeException('Failed to write temporary input file.');
            }

            $args = [$ffmpegBinary, '-y', '-i', $tmpInput];

            $filters = [];
            if ($normalize) {
                $filters[] = "loudnorm=I={$targetLoudness}:TP=-1.0:LRA=11:linear=true";
            }
            if ($filters !== []) {
                $args[] = '-af';
                $args[] = implode(',', $filters);
            }

            if ($targetFormat === 'mp3') {
                $args = array_merge($args, ['-codec:a', 'libmp3lame']);
                if ($bitrate !== null) {
                    $args = array_merge($args, ['-b:a', $bitrate]);
                }
            } else {
                $args = array_merge($args, ['-acodec', 'pcm_s16le', '-ar', '22050']);
            }

            $args[] = $tmpOutput;

            $process = new Process($args);
            $process->setTimeout(30);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException('FFmpeg failed: ' . $process->getErrorOutput());
            }

            $newContent = file_get_contents($tmpOutput);
            if ($newContent === false || strlen($newContent) < 100) {
                throw new RuntimeException('Processed audio file is empty or corrupt.');
            }

            $newDuration = $this->measureDuration($ffmpegBinary, $tmpOutput) ?? $this->duration;

            $newMetadata = array_merge($this->metadata, [
                'processed' => true,
                'normalized' => $normalize,
                'target_loudness' => $targetLoudness,
                'original_format' => $this->format,
                'bitrate' => $bitrate,
            ]);

            return new self(
                $newContent,
                $targetFormat,
                $newDuration,
                $newMetadata
            );
        } finally {
            if (isset($tmpInput) && file_exists($tmpInput)) {
                @unlink($tmpInput);
            }
            if (isset($tmpOutput) && file_exists($tmpOutput)) {
                @unlink($tmpOutput);
            }
        }
    }

    private static function isAvailable(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful() && str_contains(strtolower($process->getOutput() . $process->getErrorOutput()), 'ffmpeg');
        } catch (\Throwable) {
            return false;
        }
    }

    private function measureDuration(string $ffmpegBinary, string $filePath): ?float
    {
        try {
            $process = new Process([$ffmpegBinary, '-i', $filePath, '-f', 'null', '-']);
            $process->setTimeout(10);
            $process->run();

            $output = $process->getErrorOutput();
            if (preg_match('/Duration:\s*(\d+):(\d+):(\d+\.\d+)/', $output, $matches)) {
                $hours = (int)$matches[1];
                $minutes = (int)$matches[2];
                $seconds = (float)$matches[3];

                return ($hours * 3600) + ($minutes * 60) + $seconds;
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
