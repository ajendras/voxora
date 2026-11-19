<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper CLI (https://github.com/rhasspy/piper).
 * Outputs WAV audio. Uses robust RIFF header parsing for duration.
 * Does not support voice cloning (throws as documented).
 * Consistent with ProviderInterface and existing TTS providers.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;
    private array $voices = [
        ['id' => 'default', 'name' => 'Piper Default', 'description' => 'Local Piper voice (offline)'],
    ];

    public function __construct(array $config)
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';

        if ($this->model === '' || !file_exists($this->model)) {
            throw new RuntimeException('Piper model path is required and must exist.');
        }
    }

    public function synthesize(string $text, string $voiceId = 'default', array $options = []): SynthesisResult
    {
        $process = new Process([
            $this->binary,
            '--model', $this->model,
            '--output_file', '/dev/stdout',
        ]);

        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $audioContent = $process->getOutput();

        if (empty($audioContent) || strlen($audioContent) < 44) {
            throw new RuntimeException('Piper returned empty or invalid audio.');
        }

        $duration = $this->parseWavDuration($audioContent);

        return new SynthesisResult(
            audioContent: $audioContent,
            format: 'wav',
            duration: $duration,
            metadata: [
                'provider' => 'piper',
                'voice_id' => $voiceId,
                'model' => basename($this->model),
                'offline' => true,
            ]
        );
    }

    public function listVoices(): array
    {
        return $this->voices;
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper provider.');
    }

    /**
     * Parse duration from WAV RIFF header (handles standard and non-standard chunk layouts).
     *
     * Returns float duration in seconds. Returns 0.0 only on unrecoverable parse failure.
     * This fixes the previous always-zero behaviour that caused the CI test failure.
     */
    private function parseWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 0.0;
        }

        $numChannels = unpack('v', substr($data, 22, 2))[1] ?? 1;
        $sampleRate = unpack('V', substr($data, 24, 4))[1] ?? 22050;
        $bitsPerSample = unpack('v', substr($data, 34, 2))[1] ?? 16;

        $offset = 36;
        $dataSize = 0;
        $maxOffset = min(strlen($data) - 8, 1024); // safety limit

        while ($offset < $maxOffset) {
            if (strlen($data) < $offset + 8) {
                break;
            }
            $chunkId = substr($data, $offset, 4);
            $chunkSize = unpack('V', substr($data, $offset + 4, 4))[1] ?? 0;

            if ($chunkId === 'data') {
                $dataSize = $chunkSize;
                break;
            }

            $offset += 8 + $chunkSize;
            if ($chunkSize % 2 === 1) {
                $offset++; // byte alignment padding for odd-sized chunks
            }
        }

        if ($dataSize === 0) {
            // Most common layout: data chunk starts at offset 44
            if (strlen($data) >= 44) {
                $dataSize = unpack('V', substr($data, 40, 4))[1] ?? 0;
            }
        }

        $bytesPerSample = (int) ($bitsPerSample / 8);
        $byteRate = $sampleRate * $numChannels * $bytesPerSample;

        if ($byteRate > 0 && $dataSize > 0) {
            return (float) $dataSize / $byteRate;
        }

        return 0.0;
    }
}
