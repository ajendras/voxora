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
 * Local offline TTS using the Piper CLI binary.
 * Voice cloning is not supported (cloud-only feature).
 */
final class PiperProvider implements ProviderInterface
{
    private string $binaryPath;
    private string $modelPath;

    public function __construct(string $binaryPath = 'piper', string $modelPath)
    {
        $this->binaryPath = $binaryPath;
        $this->modelPath = $modelPath;
        if (!file_exists($this->modelPath)) {
            throw new InvalidArgumentException("Piper model not found: {$this->modelPath}");
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $process = new Process([
            $this->binaryPath,
            '--model',
            $this->modelPath,
            '--output_file',
            '/dev/stdout',
        ]);
        $process->setInput($text);

        try {
            $process->mustRun();
            $audioContent = $process->getOutput();

            $duration = $this->estimateDuration($text);

            return new SynthesisResult(
                audioContent: $audioContent,
                format: 'wav',
                duration: $duration,
                metadata: [
                    'provider' => 'piper',
                    'voice' => basename($this->modelPath, '.onnx'),
                    'local' => true,
                ]
            );
        } catch (ProcessFailedException $e) {
            throw new RuntimeException('Piper synthesis failed: ' . $e->getMessage());
        }
    }

    private function estimateDuration(string $text): float
    {
        $wordCount = str_word_count($text);
        return round($wordCount / 2.5, 1) ?: 3.0;
    }

    public function cloneVoice(string $name, string $samplePath): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper local provider. Use ElevenLabs.');
    }

    public function getName(): string
    {
        return 'piper';
    }
}
