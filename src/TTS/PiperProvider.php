<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper CLI via Symfony Process.
 * Outputs WAV files. Does not support voice cloning.
 * Duration is automatically extracted from the WAV RIFF header by SynthesisResult.
 */
final class PiperProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $binary = $this->config['binary'] ?? 'piper';
        $model = $this->config['model'] ?? '';

        if (empty($model) || !file_exists($model)) {
            throw new RuntimeException('Piper voice model not configured or not found at: ' . $model);
        }

        $outputFile = tempnam(sys_get_temp_dir(), 'piper_') . '.wav';

        $process = new Process([
            $binary,
            '--model',
            $model,
            '--output_file',
            $outputFile,
        ]);

        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audioContent = file_get_contents($outputFile);
        unlink($outputFile);

        if ($audioContent === false || $audioContent === '') {
            throw new RuntimeException('Piper produced no output.');
        }

        return new SynthesisResult(
            $audioContent,
            'wav',
            null,
            [
                'provider' => 'piper',
                'voice_id' => $voiceId,
                'model' => basename($model),
            ]
        );
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper provider.');
    }
}
