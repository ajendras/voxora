<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * SynthesisResult
 *
 * Value object holding generated audio bytes, format, computed duration and metadata.
 * Automatically extracts duration from WAV RIFF header.
 * Supports file save, data URI and FFmpeg-based post-processing (normalization, format conversion).
 */
final class SynthesisResult
{
    public readonly string $format;
    public readonly array $metadata;
    public ?float $duration = null;

    public function __construct(
        public readonly string $audioContent,
        string $format = 'mp3',
        ?float $duration = null,
        array $metadata = []
    ) {
        $this->format = strtolower($format);
        $this->metadata = $metadata;

        if ($duration !== null) {
            $this->duration = round($duration, 2);
        } elseif ($this->format === 'wav' && $this->audioContent !== '') {
            $this->duration = $this->calculateWavDuration($this->audioContent);
        }
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory: ' . $dir);
            }
        }

        if (file_put_contents($path, $this->audioContent) === false) {
            throw new RuntimeException('Failed to write audio file: ' . $path);
        }
    }

    public function toDataUri(): string
    {
        $mime = $this->format === 'wav' ? 'audio/wav' : 'audio/mpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($this->audioContent);
    }

    /**
     * Post-process audio with FFmpeg (normalization, loudness, format conversion).
     * Returns a new immutable instance.
     *
     * @param array<string, mixed> $options
     */
    public function process(array $options = []): self
    {
        $normalize = $options['normalize'] ?? true;
        $targetLoudness = (float) ($options['target_loudness'] ?? -16.0);
        $targetFormat = strtolower($options['format'] ?? $this->format);
        $ffmpegBinary = $options['ffmpeg_binary'] ?? 'ffmpeg';

        $inputTmp = tempnam(sys_get_temp_dir(), 'vox_in_');
        $outputTmp = tempnam(sys_get_temp_dir(), 'vox_out_') . '.' . $targetFormat;

        try {
            $this->save($inputTmp);

            $filter = $normalize
                ? 'loudnorm=I=' . $targetLoudness . ':TP=-1.5:LRA=11:linear=true'
                : '';

            $cmd = array_filter([
                $ffmpegBinary,
                '-y',
                '-i',
                $inputTmp,
                ...($filter !== '' ? ['-af', $filter] : []),
                '-f',
                $targetFormat,
                $outputTmp,
            ]);

            $process = new Process($cmd);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new RuntimeException('FFmpeg processing failed: ' . $process->getErrorOutput());
            }

            $processedAudio = file_get_contents($outputTmp);
            if ($processedAudio === false) {
                throw new RuntimeException('Failed to read processed audio output.');
            }

            $newDuration = $this->extractDuration($outputTmp, $ffmpegBinary);

            return new self(
                $processedAudio,
                $targetFormat,
                $newDuration,
                array_merge($this->metadata, [
                    'normalized' => $normalize,
                    'target_loudness' => $targetLoudness,
                    'processed' => true,
                ])
            );
        } finally {
            if (file_exists($inputTmp)) {
                unlink($inputTmp);
            }
            if (file_exists($outputTmp)) {
                unlink($outputTmp);
            }
        }
    }

    private function calculateWavDuration(string $data): ?float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return null;
        }

        $channels = unpack('v', substr($data, 22, 2))[1] ?? 1;
        $sampleRate = unpack('V', substr($data, 24, 4))[1] ?? 22050;
        $bitsPerSample = unpack('v', substr($data, 34, 2))[1] ?? 16;
        $dataSize = unpack('V', substr($data, 40, 4))[1] ?? 0;

        $bytesPerSecond = $sampleRate * $channels * ($bitsPerSample / 8);

        if ($bytesPerSecond > 0 && $dataSize > 0) {
            return round($dataSize / $bytesPerSecond, 2);
        }

        return null;
    }

    private function extractDuration(string $filePath, string $ffmpegBinary): ?float
    {
        $ffprobe = str_replace(['ffmpeg', 'ffmpeg.exe'], ['ffprobe', 'ffprobe.exe'], $ffmpegBinary);

        $process = new Process([
            $ffprobe,
            '-v', 'quiet',
            '-print_format', 'compact=print_section=0:nokey=1',
            '-show_entries', 'format=duration',
            $filePath,
        ]);

        $process->run();

        if ($process->isSuccessful()) {
            $output = trim($process->getOutput());
            return is_numeric($output) ? (float) $output : null;
        }

        return null;
    }
}
