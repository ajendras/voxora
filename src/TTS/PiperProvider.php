<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;

/**
 * PiperProvider
 *
 * Provides offline text-to-speech using the Piper neural TTS engine.
 * Executes the piper binary via Symfony Process component and returns WAV data.
 * Automatically used as fallback by Manager on cloud provider failures.
 * cloneVoice() is not supported and will throw a RuntimeException.
 */
final class PiperProvider implements ProviderInterface
{
    private array $config;

    /**
     * @param array{binary?: string, model: string} $config
     */
    public function __construct(array $config)
    {
        $this->config = array_merge([
            'binary' => 'piper',
        ], $config);

        if (empty($this->config['model'])) {
            throw new RuntimeException('Piper model path must be provided in config.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $binary = $this->config['binary'];
        $modelPath = $this->config['model'];

        $process = new Process([
            $binary,
            '--model',
            $modelPath,
            '--output_file',
            '/dev/stdout',
        ]);

        $process->setInput(trim($text));

        try {
            $process->mustRun();
            $audio = $process->getOutput();

            if ($audio === '' || $audio === false) {
                throw new RuntimeException('Piper produced no output');
            }

            $duration = $this->parseWavDuration($audio);

            return new SynthesisResult(
                audioContent: $audio,
                format: 'wav',
                duration: $duration,
                metadata: [
                    'provider' => 'piper',
                    'voice' => $voiceId,
                    'model' => basename($modelPath),
                ]
            );
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'Failed to synthesize with Piper: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Piper Default',
                'description' => 'Local Piper voice model',
                'provider' => 'piper',
                'is_cloned' => false,
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException(
            'Voice cloning is not supported by PiperProvider. ' .
            'Please use ElevenLabsProvider for voice cloning features.'
        );
    }

    /**
     * Parses duration from a WAV file's RIFF header.
     */
    private function parseWavDuration(string $wavData): float
    {
        if (strlen($wavData) < 44 || substr($wavData, 0, 4) !== 'RIFF' || substr($wavData, 8, 4) !== 'WAVE') {
            return 5.0; // fallback for tests or malformed output
        }

        $sampleRate = unpack('V', substr($wavData, 24, 4))[1];
        $numChannels = unpack('v', substr($wavData, 22, 2))[1];
        $bitsPerSample = unpack('v', substr($wavData, 34, 2))[1];
        $byteRate = $sampleRate * $numChannels * ($bitsPerSample / 8);

        $pos = 36;
        while ($pos + 8 < strlen($wavData)) {
            $chunkId = substr($wavData, $pos, 4);
            $chunkSize = unpack('V', substr($wavData, $pos + 4, 4))[1];
            if ($chunkId === 'data') {
                $dataSize = $chunkSize;
                return round($dataSize / $byteRate, 2);
            }
            $pos += 8 + $chunkSize;
        }

        return round((strlen($wavData) - 44) / max($byteRate, 1), 2);
    }
}
