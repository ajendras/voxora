<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * SynthesisResult
 *
 * Immutable value object for TTS output. Holds raw audio, format, provider,
 * metadata and optional duration. The process() method applies FFmpeg-based
 * normalization/format conversion with graceful degradation when FFmpeg
 * is unavailable or fails.
 */
final class SynthesisResult
{
    public readonly string $audioContent;
    public readonly string $format;
    public readonly string $provider;
    public readonly array $metadata;
    public readonly ?float $duration;

    public function __construct(
        string $audioContent,
        string $format = 'mp3',
        string $provider = 'elevenlabs',
        array $metadata = [],
        ?float $duration = null
    ) {
        $this->audioContent = $audioContent;
        $this->format = $format;
        $this->provider = $provider;
        $this->metadata = $metadata;
        $this->duration = $duration ?? ($format === 'wav' ? $this->parseWavDuration($audioContent) : null);
    }

    public function process(array $options = []): self
    {
        $normalize = $options['normalize'] ?? true;
        $targetLoudness = $options['target_loudness'] ?? -16.0;
        $targetFormat = $options['format'] ?? $this->format;
        $ffmpegBinary = $options['ffmpeg_binary'] ?? 'ffmpeg';

        if (!$normalize && $targetFormat === $this->format) {
            return $this;
        }

        $inputFile = tempnam(sys_get_temp_dir(), 'vox_in_') . '.' . $this->format;
        $outputFile = tempnam(sys_get_temp_dir(), 'vox_out_') . '.' . $targetFormat;

        file_put_contents($inputFile, $this->audioContent);

        $filter = $normalize ? "loudnorm=I={$targetLoudness}:TP=-1:LRA=11" : 'anull';
        $command = [
            $ffmpegBinary,
            '-i', $inputFile,
            '-af', $filter,
            '-ar', '22050',
            '-y',
            $outputFile,
        ];

        $process = new Process($command);

        try {
            $process->mustRun();

            $newContent = file_get_contents($outputFile);
            if ($newContent === false) {
                throw new RuntimeException('Failed to read processed output');
            }

            // Extract duration with ffprobe
            $probeCmd = [
                $ffmpegBinary,
                '-i', $outputFile,
                '-show_entries', 'format=duration',
                '-of', 'csv=p=0',
                '-v', 'quiet',
            ];
            $probe = new Process($probeCmd);
            $probe->run();
            $durStr = trim($probe->getOutput());
            $newDuration = is_numeric($durStr) ? (float) $durStr : $this->duration;

            @unlink($inputFile);
            @unlink($outputFile);

            return new self(
                $newContent,
                $targetFormat,
                $this->provider,
                array_merge($this->metadata, ['normalized' => true, 'processed' => true]),
                $newDuration
            );
        } catch (\Throwable $e) {
            @unlink($inputFile);
            if (file_exists($outputFile)) {
                @unlink($outputFile);
            }

            $newMeta = $this->metadata;
            $newMeta['normalized'] = false;
            $newMeta['processing_error'] = $e->getMessage();

            return new self(
                $this->audioContent,
                $this->format,
                $this->provider,
                $newMeta,
                $this->duration
            );
        }
    }

    private function parseWavDuration(string $content): ?float
    {
        if (strlen($content) < 44) {
            return null;
        }

        $header = unpack(
            'vNumChannels/VSampleRate/VByteRate',
            substr($content, 22, 12)
        );

        if (!$header || ($header['SampleRate'] ?? 0) === 0) {
            return null;
        }

        $dataSize = strlen($content) - 44;
        return round($dataSize / $header['ByteRate'], 2);
    }

    public function save(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
        file_put_contents($path, $this->audioContent);
    }

    public function toDataUri(): string
    {
        $mime = $this->format === 'wav' ? 'audio/wav' : 'audio/mpeg';
        return 'data:' . $mime . ';base64,' . base64_encode($this->audioContent);
    }
}
