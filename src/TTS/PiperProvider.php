<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use InvalidArgumentException;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local/offline TTS using the Piper CLI (via Symfony Process).
 * Outputs WAV; parses RIFF header for accurate duration.
 * Does not support voice cloning.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;

    public function __construct(array $config = [])
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';

        if (empty($this->model) || !file_exists($this->model)) {
            throw new InvalidArgumentException('Valid Piper model path is required.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $command = [
            $this->binary,
            '--model', $this->model,
            '--output_file', '-',
        ];

        $process = $this->createProcess($command);
        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audioContent = $process->getOutput();
        $duration = $this->extractWavDurationFromRiffHeader($audioContent);

        return new SynthesisResult(
            audioContent: $audioContent,
            format: 'wav',
            duration: $duration,
            metadata: [
                'provider' => 'piper',
                'voice' => basename($this->model, '.onnx'),
                'model_path' => $this->model,
            ]
        );
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'piper-default',
                'name' => 'Piper Default',
                'description' => 'Local Piper voice model (offline)',
                'provider' => 'piper',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, ?string $provider = null, ?int $userId = null): string
    {
        throw new InvalidArgumentException('Piper does not support voice cloning. Use ElevenLabs or OpenAI.');
    }

    /**
     * Factory for Process instances - extracted for testability.
     */
    protected function createProcess(array $command): Process
    {
        return new Process($command);
    }

    /**
     * Parse duration from a WAV RIFF header (PCM data).
     */
    private function extractWavDurationFromRiffHeader(string $data): float
    {
        if (strlen($data) < 44) {
            return 3.0; // safe fallback
        }

        // channels = offset 22 (uint16)
        $channels = unpack('v', substr($data, 22, 2))[1];
        // sample rate = offset 24 (uint32)
        $sampleRate = unpack('V', substr($data, 24, 4))[1];
        // byte rate = offset 28 (uint32)
        $byteRate = unpack('V', substr($data, 28, 4))[1];

        if ($byteRate > 0) {
            return strlen($data) / $byteRate;
        }

        // fallback using data chunk size (offset 40)
        if (strlen($data) > 44) {
            $dataSize = unpack('V', substr($data, 40, 4))[1];
            if ($channels > 0 && $sampleRate > 0) {
                return $dataSize / ($sampleRate * $channels * 2); // assume 16-bit
            }
        }

        return 3.0;
    }
}
