<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;
use InvalidArgumentException;

/**
 * PiperProvider
 *
 * Local/offline TTS using the Piper binary via Symfony Process component.
 * Outputs WAV audio. Used automatically as fallback by TTS\Manager.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binaryPath;
    private string $modelPath;

    /**
     * @param string $binaryPath Path or name of the piper executable (must be in PATH or absolute)
     * @param string $modelPath Absolute path to the .onnx voice model
     */
    public function __construct(string $binaryPath = 'piper', string $modelPath = '')
    {
        $this->binaryPath = $binaryPath;

        if ($modelPath === '') {
            throw new InvalidArgumentException('Piper model path must be provided.');
        }

        $this->modelPath = $modelPath;
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty for synthesis.');
        }

        if (!file_exists($this->modelPath)) {
            throw new RuntimeException(
                'Piper voice model not found at: ' . $this->modelPath .
                '. Download from https://huggingface.co/rhasspy/piper-voices'
            );
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'voxora_piper_');
        $wavFile = $tempFile . '.wav';
        unlink($tempFile);

        try {
            $process = new Process([
                $this->binaryPath,
                '--model', $this->modelPath,
                '--output_file', $wavFile,
            ]);

            $process->setInput($text);
            $process->setTimeout(30);
            $process->mustRun();

            if (!file_exists($wavFile) || filesize($wavFile) < 100) {
                throw new RuntimeException('Piper produced no valid output.');
            }

            $audioContent = file_get_contents($wavFile);
            unlink($wavFile);

            $duration = $this->getWavDuration($audioContent);

            return new SynthesisResult(
                audioContent: $audioContent,
                format: 'wav',
                duration: round($duration, 2),
                metadata: [
                    'provider' => 'piper',
                    'voice_id' => $voiceId ?: 'local',
                    'model' => basename($this->modelPath),
                    'offline' => true,
                ]
            );
        } catch (ProcessFailedException $e) {
            if (isset($wavFile) && file_exists($wavFile)) {
                unlink($wavFile);
            }
            throw new RuntimeException('Piper process failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if (isset($wavFile) && file_exists($wavFile)) {
                unlink($wavFile);
            }
            throw new RuntimeException('Failed to synthesize with Piper: ' . $e->getMessage(), 0, $e);
        }
    }

    private function getWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return max(0.5, strlen($data) / 16000.0);
        }

        $numChannels = unpack('v', substr($data, 22, 2))[1];
        $sampleRate = unpack('V', substr($data, 24, 4))[1];
        $bitsPerSample = unpack('v', substr($data, 34, 2))[1];
        $byteRate = $sampleRate * $numChannels * ($bitsPerSample / 8);

        if ($byteRate <= 0) {
            return 3.0;
        }

        $dataSize = unpack('V', substr($data, 40, 4))[1] ?? (strlen($data) - 44);
        return $dataSize / $byteRate;
    }
}
