<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use InvalidArgumentException;
use RuntimeException;

/**
 * PiperProvider
 *
 * Provides offline text-to-speech using the Piper neural TTS engine via Symfony Process.
 * Outputs WAV format. Duration is automatically calculated from the WAV RIFF header.
 * Voice cloning is not supported (throws exception). Process factory is injectable
 * for reliable unit testing without requiring a real piper binary.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;
    /** @var callable(array<string>): Process */
    private $processFactory;

    public function __construct(array $config = [])
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';
        $this->processFactory = $config['process_factory'] ?? static fn (array $command): Process => new Process($command);

        if ($this->model === '') {
            throw new RuntimeException('Piper model path must be provided in configuration.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty for synthesis.');
        }

        $command = [
            $this->binary,
            '--model', $this->model,
            '-f', '-', // output WAV to stdout
        ];

        $process = ($this->processFactory)($command);
        $process->setInput($text);

        try {
            $process->mustRun();
            $audioContent = $process->getOutput();

            if ($audioContent === '' || strlen($audioContent) < 44) {
                throw new RuntimeException('Piper produced no valid audio output.');
            }

            $duration = $this->calculateDurationFromWav($audioContent);

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
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'Failed to synthesize speech with Piper: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper local provider.');
    }

    private function calculateDurationFromWav(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 0.0;
        }

        $pos = 12;
        $dataSize = 0;
        $byteRate = 0;

        while ($pos + 8 < strlen($data)) {
            $chunkId = substr($data, $pos, 4);
            $chunkSize = unpack('V', substr($data, $pos + 4, 4))[1] ?? 0;

            if ($chunkId === 'fmt ') {
                // byte rate is at offset 16 inside the fmt chunk (after 4-byte fmt id + 4-byte chunk size)
                $byteRate = unpack('V', substr($data, $pos + 16, 4))[1] ?? 0;
            } elseif ($chunkId === 'data') {
                $dataSize = $chunkSize;
                break;
            }

            $pos += 8 + $chunkSize;
            if ($chunkSize % 2 === 1) {
                $pos++; // padding to even boundary
            }
        }

        if ($byteRate > 0 && $dataSize > 0) {
            return round($dataSize / $byteRate, 3);
        }

        // fallback for common Piper params (22050 Hz, 16-bit mono)
        return round(strlen($data) / 44100.0, 3);
    }
}
