<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper CLI (no longer final to support test doubles).
 * Accepts optional process factory for testability.
 */
class PiperProvider implements ProviderInterface
{
    private array $config;

    /** @var callable(array<string>): Process */
    private $processFactory;

    public function __construct(array $config = [], ?callable $processFactory = null)
    {
        $this->config = array_merge([
            'binary' => 'piper',
            'model' => '',
        ], $config);

        $this->processFactory = $processFactory ?? static fn (array $cmd): Process => new Process($cmd);

        if (empty($this->config['model'])) {
            throw new RuntimeException('Piper model path must be provided in config');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $format = strtolower($options['format'] ?? 'wav');

        $cmd = [
            $this->config['binary'],
            '--model', $this->config['model'],
            '--output_file', '-',
        ];

        $process = ($this->processFactory)($cmd);
        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audio = $process->getOutput();
        $duration = $this->calculateWavDuration($audio);

        return new SynthesisResult($audio, $format, $duration);
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Piper Local Voice',
                'language' => 'en-US',
                'gender' => 'neutral',
                'description' => 'High-quality offline voice via Piper',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning not supported by PiperProvider. Use ElevenLabs instead.');
    }

    private function calculateWavDuration(string $audioData): float
    {
        if (strlen($audioData) < 44 || substr($audioData, 0, 4) !== 'RIFF') {
            return max(0.1, strlen($audioData) / 32000); // rough fallback for 16kHz mono
        }

        $byteRate = unpack('V', substr($audioData, 28, 4))[1] ?? 0;
        if ($byteRate > 0) {
            return strlen($audioData) / $byteRate;
        }

        return 3.0;
    }
}
