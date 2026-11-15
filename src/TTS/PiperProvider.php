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
 * Local offline TTS using the Piper CLI (via Symfony Process).
 * Returns WAV audio. Integrates with SynthesisResult for optional
 * post-processing (normalization, format conversion). Throws on
 * cloneVoice since it is not supported locally.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binary;
    private string $model;
    private $processFactory = null;

    public function __construct(array $config = [])
    {
        $this->binary = $config['binary'] ?? 'piper';
        $this->model = $config['model'] ?? '';

        if ($this->model === '') {
            throw new RuntimeException('Model path is required for PiperProvider.');
        }
    }

    /**
     * For unit tests only: inject a factory that can return a mocked Process.
     *
     * @param callable(array<string,mixed>): Process $factory
     */
    public function setProcessFactory(callable $factory): void
    {
        $this->processFactory = $factory;
    }

    private function createProcess(array $command): Process
    {
        if ($this->processFactory !== null) {
            return ($this->processFactory)($command);
        }

        return new Process($command);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty for synthesis.');
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'piper_out_') . '.wav';
        $command = [
            $this->binary,
            '--model', $this->model,
            '--output_file', $tempFile,
        ];

        $process = $this->createProcess($command);
        $process->setInput($text);

        try {
            $process->mustRun();

            $audioContent = file_get_contents($tempFile);
            @unlink($tempFile);

            if ($audioContent === false || $audioContent === '') {
                throw new RuntimeException('Piper produced no output.');
            }

            $result = new SynthesisResult(
                $audioContent,
                'wav',
                'piper',
                [
                    'voice_id' => $voiceId,
                    'model' => basename($this->model),
                ],
                null
            );

            if (!empty($options['normalize']) || ($options['format'] ?? 'wav') !== 'wav') {
                $processOpts = [
                    'normalize' => $options['normalize'] ?? true,
                    'target_loudness' => $options['target_loudness'] ?? -16.0,
                    'format' => $options['format'] ?? 'wav',
                    'ffmpeg_binary' => $options['ffmpeg_binary'] ?? 'ffmpeg',
                ];
                $result = $result->process($processOpts);
            }

            return $result;
        } catch (ProcessFailedException $e) {
            @unlink($tempFile);
            throw new RuntimeException('Piper synthesis failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper provider. Use ElevenLabs instead.');
    }
}
