<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Offline/local TTS using the Piper CLI (via Symfony Process).
 * Always produces WAV. Duration is extracted from the RIFF/WAVE header.
 * Cloning is not supported. The processFactory allows easy mocking in tests.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;
    private $processFactory;

    public function __construct(array $config = [], ?callable $processFactory = null)
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';

        if (empty($this->model)) {
            throw new RuntimeException('Piper model path must be configured');
        }

        $this->processFactory = $processFactory ?? static fn (array $command): Process => new Process($command);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot synthesize empty text');
        }

        $command = [
            $this->binary,
            '--model', $this->model,
        ];

        $process = ($this->processFactory)($command);
        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . trim($process->getErrorOutput() ?: '(no output)'));
        }

        $audio = $process->getOutput();

        if ($audio === '' || $audio === false) {
            throw new RuntimeException('Piper produced no audio output');
        }

        $duration = $this->extractDurationFromWav($audio);

        return new SynthesisResult($audio, 'wav', $duration);
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Piper Default (en_US-lessac-medium)',
                'language' => 'en-US',
                'gender' => 'female',
                'description' => 'High-quality local voice (model-dependent)',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning not supported by PiperProvider. Use a cloud provider instead.');
    }

    /**
     * Parse RIFF/WAVE header to compute exact audio duration.
     */
    private function extractDurationFromWav(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            // Fallback for malformed output
            return round(strlen($data) / 44100, 2);
        }

        $byteRate = unpack('V', substr($data, 28, 4))[1] ?? 0;
        if ($byteRate <= 0) {
            return 3.0;
        }

        // Locate the 'data' chunk for precise size
        $pos = 12;
        while ($pos + 8 < strlen($data)) {
            $chunkId = substr($data, $pos, 4);
            $chunkSize = unpack('V', substr($data, $pos + 4, 4))[1] ?? 0;

            if ($chunkId === 'data') {
                return round($chunkSize / $byteRate, 3);
            }

            $pos += 8 + $chunkSize;
            if ($chunkSize % 2 === 1) {
                $pos++; // padding
            }
        }

        return round((strlen($data) - 44) / $byteRate, 3);
    }
}
