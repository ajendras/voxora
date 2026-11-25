<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Symfony\Component\Process\Process;
use RuntimeException;

/**
 * PiperProvider
 *
 * Local offline TTS using the Piper CLI binary (via Symfony Process).
 * Outputs WAV; duration extracted safely from RIFF header.
 * Mocked in unit tests for reliable CI runs across PHP versions.
 * Does not support voice cloning (throws as documented in ProviderInterface).
 */
final class PiperProvider implements ProviderInterface
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config + [
            'binary' => 'piper',
            'model' => '',
        ];
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $text = $this->stripSSML($text);
        if ('' === trim($text)) {
            throw new RuntimeException('Cannot synthesize empty text with Piper.');
        }

        $process = $this->createProcess($text);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Piper synthesis failed: ' . $process->getErrorOutput());
        }

        $audio = $process->getOutput();
        if ('' === $audio) {
            throw new RuntimeException('Piper returned empty audio.');
        }

        $duration = $this->calculateWavDuration($audio);

        return new SynthesisResult($audio, 'wav', $duration);
    }

    public function listVoices(): array
    {
        $modelName = basename($this->config['model'] ?? 'default.onnx', '.onnx');
        $modelName = str_replace(['_', '-'], ' ', ucwords($modelName));

        return [[
            'id' => 'piper-' . strtolower($modelName),
            'name' => trim($modelName) . ' (Piper)',
            'language' => 'en-US',
            'gender' => 'neutral',
            'description' => 'High-quality offline voice',
        ]];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning not supported by PiperProvider. Use ElevenLabs instead.');
    }

    protected function createProcess(string $text): Process
    {
        $cmd = [
            $this->config['binary'],
            '--model',
            $this->config['model'],
            '--output_file',
            '-',
        ];

        $process = new Process($cmd);
        $process->setInput($text);

        return $process;
    }

    private function stripSSML(string $text): string
    {
        return preg_replace('/<[^>]+>/', '', $text) ?? $text;
    }

    private function calculateWavDuration(string $data): float
    {
        if (strlen($data) < 44) {
            return 0.0;
        }

        if (substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WAVE') {
            return 0.0;
        }

        $pos = 12;
        $sampleRate = 22050;
        $numChannels = 1;
        $bitsPerSample = 16;
        $dataSize = 0;
        $maxPos = min(strlen($data), 1024);

        while ($pos + 8 < $maxPos) {
            $chunkId = substr($data, $pos, 4);
            $chunkSize = $this->unpackUint32($data, $pos + 4);

            if ($chunkId === 'fmt ') {
                $numChannels = $this->unpackUint16($data, $pos + 10);
                $sampleRate = $this->unpackUint32($data, $pos + 12);
                $bitsPerSample = $this->unpackUint16($data, $pos + 22);
            } elseif ($chunkId === 'data') {
                $dataSize = $this->unpackUint32($data, $pos + 4);
                break;
            }

            $pos += 8 + $chunkSize;
            if ($chunkSize % 2 === 1) {
                $pos++;
            }
        }

        if ($dataSize === 0 || $sampleRate === 0) {
            return strlen($data) / 32000.0;
        }

        $bytesPerSecond = $sampleRate * $numChannels * ($bitsPerSample / 8);

        return $bytesPerSecond > 0 ? $dataSize / $bytesPerSecond : 0.0;
    }

    private function unpackUint32(string $data, int $offset): int
    {
        if (strlen($data) < $offset + 4) {
            return 0;
        }

        $unpacked = unpack('V', substr($data, $offset, 4));

        return $unpacked[1] ?? 0;
    }

    private function unpackUint16(string $data, int $offset): int
    {
        if (strlen($data) < $offset + 2) {
            return 0;
        }

        $unpacked = unpack('v', substr($data, $offset, 2));

        return $unpacked[1] ?? 0;
    }
}
