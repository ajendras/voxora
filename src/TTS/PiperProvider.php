<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Offline TTS using the Piper CLI via Symfony Process. Outputs WAV.
 * Duration is extracted from the RIFF header. Supports testability
 * via a 'process_factory' callable in config (used by unit tests).
 * cloneVoice() is not supported for this local provider.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;
    private ?WebhookDispatcher $webhookDispatcher;
    private $processFactory;

    public function __construct(array $config = [])
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';

        if (empty($this->model) || !file_exists($this->model)) {
            throw new RuntimeException('Piper model path is required and must exist.');
        }

        $this->processFactory = $config['process_factory'] ?? fn(array $command): Process => new Process($command);
        $this->webhookDispatcher = $config['webhook_dispatcher'] ?? null;
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $command = [
            $this->binary,
            '--model',
            $this->model,
            '-f',
            '-',
        ];

        $process = ($this->processFactory)($command);
        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audioContent = $process->getOutput();

        if ($audioContent === false || $audioContent === '') {
            throw new RuntimeException('Piper returned no audio data.');
        }

        $duration = $this->parseWavDuration($audioContent);

        $result = new SynthesisResult(
            audioContent: $audioContent,
            format: 'wav',
            duration: $duration,
            metadata: [
                'provider' => 'piper',
                'voice_id' => $voiceId,
                'model' => basename($this->model),
            ]
        );

        if ($this->webhookDispatcher !== null) {
            $this->webhookDispatcher->dispatchSuccess($result, [
                'text' => $text,
                'voice_id' => $voiceId,
                'provider' => 'piper',
                'duration' => $duration,
            ]);
        }

        return $result;
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Piper Default (en_US-lessac-medium)',
                'provider' => 'piper',
                'description' => 'Local offline voice',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper provider.');
    }

    private function parseWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 3.0; // fallback for malformed output
        }

        $numChannels = unpack('v', substr($data, 22, 2))[1];
        $sampleRate = unpack('V', substr($data, 24, 4))[1];
        $bitsPerSample = unpack('v', substr($data, 34, 2))[1];
        $dataSize = unpack('V', substr($data, 40, 4))[1];

        $bytesPerSample = (int) ($bitsPerSample / 8);
        $byteRate = $sampleRate * $numChannels * $bytesPerSample;

        if ($byteRate > 0) {
            return round($dataSize / $byteRate, 2);
        }

        return 3.0;
    }
}
