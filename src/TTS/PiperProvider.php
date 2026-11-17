<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local/offline TTS using the Piper engine via Symfony Process.
 * Outputs WAV audio. Does not support voice cloning (throws exception).
 * Consistent with ProviderInterface after API unification for options array.
 */
final class PiperProvider implements ProviderInterface
{
    private string $binaryPath;
    private string $modelPath;

    public function __construct(array $config = [])
    {
        $this->binaryPath = $config['binary'] ?? 'piper';
        $this->modelPath = $config['model'] ?? '';

        if ($this->modelPath === '') {
            throw new RuntimeException('Piper model path is required.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'piper_') . '.wav';

        $process = new Process([
            $this->binaryPath,
            '--model', $this->modelPath,
            '--output_file', $outputFile,
        ]);
        $process->setInput($text);
        $process->run();

        if (!$process->isSuccessful()) {
            @unlink($outputFile);
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        if (!file_exists($outputFile)) {
            throw new RuntimeException('Piper did not produce output file.');
        }

        $audioContent = file_get_contents($outputFile);
        @unlink($outputFile);

        if ($audioContent === false || $audioContent === '') {
            throw new RuntimeException('Failed to read Piper output.');
        }

        $duration = $this->extractWavDuration($audioContent);

        return new SynthesisResult(
            audioContent: $audioContent,
            format: 'wav',
            duration: $duration,
            metadata: [
                'provider' => 'piper',
                'voice_id' => $voiceId,
                'model' => basename($this->modelPath),
                'offline' => true,
            ]
        );
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning is not supported by the Piper provider. Please use a cloud provider such as ElevenLabs.');
    }

    /**
     * Extract duration from WAV RIFF header.
     */
    private function extractWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF') {
            return 3.0; // fallback default
        }

        $subchunk2Size = unpack('V', substr($data, 40, 4))[1] ?? 0;
        $byteRate = unpack('V', substr($data, 28, 4))[1] ?? 0;

        if ($byteRate > 0) {
            return round($subchunk2Size / $byteRate, 2);
        }

        return 3.0;
    }
}
