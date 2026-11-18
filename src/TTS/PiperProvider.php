<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;
use InvalidArgumentException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper binary via Symfony Process.
 * Outputs WAV, parses duration from RIFF header, supports SSML stripping.
 * Throws on cloneVoice as it's not supported locally.
 */
final class PiperProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (empty($config['model']) || !file_exists($config['model'])) {
            throw new InvalidArgumentException('Valid Piper model path is required.');
        }
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty for synthesis.');
        }

        $cleanText = $this->stripSSML($text);

        $binary = $this->config['binary'] ?? 'piper';
        $model = $this->config['model'];

        $process = new Process([
            $binary,
            '--model', $model,
            '--output_file', '/dev/stdout',
        ]);

        $process->setInput($cleanText . "\n");
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audioContent = $process->getOutput();

        if (empty($audioContent)) {
            throw new RuntimeException('Piper returned empty audio.');
        }

        $duration = $this->parseWavDuration($audioContent);

        return new SynthesisResult(
            audioContent: $audioContent,
            format: 'wav',
            duration: $duration,
            metadata: [
                'provider' => 'piper',
                'voice' => $voiceId ?: 'default',
                'ssml_stripped' => $text !== $cleanText,
            ]
        );
    }

    public function listVoices(): array
    {
        $modelName = basename($this->config['model'] ?? 'default', '.onnx');
        $friendlyName = ucfirst(str_replace('_', ' ', $modelName));

        return [
            [
                'id' => $modelName,
                'name' => $friendlyName ?: 'Piper Default',
                'description' => 'Local • High-quality offline voice',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by Piper (local TTS).');
    }

    private function stripSSML(string $text): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function parseWavDuration(string $data): float
    {
        if (strlen($data) < 44 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 3.0; // fallback
        }

        // Parse byte rate and data size
        $byteRate = unpack('V', substr($data, 28, 4))[1] ?? 0;
        $dataSizePos = strpos($data, 'data', 36);
        if ($byteRate > 0 && $dataSizePos !== false) {
            $dataSize = unpack('V', substr($data, $dataSizePos + 4, 4))[1] ?? 0;
            return round($dataSize / $byteRate, 2);
        }

        return 3.0;
    }
}
