<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Offline/local TTS synthesis using the Piper CLI (via Symfony Process).
 * Automatically strips SSML tags (Piper does not support them).
 * Returns WAV audio with RIFF-based duration extraction.
 * Throws on cloneVoice() as local voices are not dynamically clonable.
 *
 * Used as fallback by TTS\Manager when cloud providers are unavailable.
 */
class PiperProvider implements ProviderInterface
{
    private string $binaryPath;
    private string $modelPath;

    public function __construct(array $config = [])
    {
        $this->binaryPath = $config['binary'] ?? 'piper';
        $this->modelPath = $config['model'] ?? '';

        if (empty($this->modelPath) || !file_exists($this->modelPath)) {
            throw new RuntimeException('Piper model path is required and must point to an existing .onnx file.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = $this->stripSSML($text);
        $format = strtolower($options['format'] ?? 'wav');

        $command = [
            $this->binaryPath,
            '--model', $this->modelPath,
            '--output_file', '/dev/stdout',
        ];

        $process = $this->createProcess($command);
        $process->setInput($text);
        $exitCode = $process->run();

        if ($exitCode !== 0) {
            throw new RuntimeException(
                'Piper synthesis failed: ' . $process->getErrorOutput()
            );
        }

        $audio = $process->getOutput();

        if (strlen($audio) === 0) {
            $audio = $this->getSilentWav();
        }

        $duration = $this->extractDurationFromWav($audio);

        return new SynthesisResult($audio, $format, $duration);
    }

    private function stripSSML(string $text): string
    {
        return preg_replace('/<[^>]*>/', '', $text) ?? $text;
    }

    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    private function getSilentWav(): string
    {
        // Minimal valid 16-bit 22050Hz mono WAV (zero length data chunk)
        return pack(
            'A4VvA4A4VvvvVA4V',
            'RIFF',
            36,
            1,
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            22050,
            44100,
            2,
            16,
            'data',
            0
        );
    }

    private function extractDurationFromWav(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF') {
            return 3.0; // fallback duration
        }

        $dataSize = unpack('V', substr($data, 40, 4))[1] ?? 0;
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $bytesPerSecond = (int) ($sampleRate * $channels * ($bitsPerSample / 8));

        return $bytesPerSecond > 0 ? round($dataSize / $bytesPerSecond, 2) : 3.0;
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Piper Default (en_US-lessac-medium)',
                'language' => 'en-US',
                'gender' => 'female',
                'description' => 'High-quality local voice (fallback)',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning not supported by PiperProvider. Use ElevenLabs instead.');
    }
}
