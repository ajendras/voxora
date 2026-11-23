<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper CLI binary. Outputs WAV by default.
 * Automatically used as fallback by Manager on cloud provider errors.
 * Does not support voice cloning (throws as per ProviderInterface contract).
 * Duration is extracted from the RIFF header for accuracy.
 */
final class PiperProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'binary' => 'piper',
        ], $config);

        if (empty($this->config['model'])) {
            throw new RuntimeException('Piper model path must be configured in providers.piper.model');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Cannot synthesize empty text with Piper.');
        }

        $format = strtolower($options['format'] ?? 'wav');
        $binary = $this->config['binary'];
        $model = $this->config['model'];

        $command = [
            $binary,
            '-m', $model,
            '--output_file', '/dev/stdout',
        ];

        $process = new Process($command);
        $process->setInput($text);

        try {
            $process->mustRun();
            $audioData = $process->getOutput();

            if ($audioData === '' || $audioData === false) {
                throw new RuntimeException('Piper returned empty audio output.');
            }

            $duration = $this->extractWavDuration($audioData);

            // Manager will handle any requested post-processing (format conversion, normalization)
            return new SynthesisResult($audioData, 'wav', $duration);
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(
                'Piper synthesis failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function listVoices(): array
    {
        $modelName = basename($this->config['model'] ?? 'unknown', '.onnx');

        return [
            [
                'id' => 'piper-default',
                'name' => 'Piper Local Voice',
                'language' => 'en-US',
                'gender' => 'neutral',
                'description' => "Local Piper model: {$modelName} (offline)",
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning not supported by PiperProvider. Use ElevenLabs instead.');
    }

    /**
     * Extract exact duration from WAV RIFF header.
     * Falls back to rough estimate for non-standard or truncated data.
     */
    private function extractWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            // Fallback estimate (16kHz 16-bit mono)
            return strlen($data) / 32000.0;
        }

        $header = unpack(
            'vAudioFormat/vNumChannels/VSampleRate/VByteRate/vBlockAlign/vBitsPerSample',
            substr($data, 20, 16)
        );

        if (!$header || ($header['ByteRate'] ?? 0) === 0) {
            return strlen($data) / 32000.0;
        }

        // Audio data size is total size minus header (44 bytes typical)
        $audioSize = strlen($data) - 44;
        return round($audioSize / $header['ByteRate'], 3);
    }
}
