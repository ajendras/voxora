<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use RuntimeException;
use ReflectionProperty;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'binary' => 'piper',
            'model' => __DIR__ . '/../../models/en_US-lessac-medium.onnx',
        ];
        $this->provider = new PiperProvider($this->config);
    }

    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration()
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('run')
            ->willReturn(0);
        $mockProcess->expects($this->once())
            ->method('getOutput')
            ->willReturn($this->createTestWavData(4.8));
        $mockProcess->expects($this->once())
            ->method('getErrorOutput')
            ->willReturn('');

        $provider = new PiperProvider($this->config);
        $this->injectProcessFactory($provider, fn() => $mockProcess);

        $result = $provider->synthesize('Hello from Piper test.', 'en_US-lessac-medium');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertEquals('wav', $result->format);
        $this->assertEqualsWithDelta(4.8, $result->duration, 0.1);
    }

    public function testCloneVoiceThrowsException()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider. Use ElevenLabs instead.');

        $this->provider->cloneVoice('Test Voice', '/tmp/nonexistent.wav');
    }

    public function testListVoicesReturnsArrayWithExpectedStructure()
    {
        $voices = $this->provider->listVoices();
        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
    }

    private function createTestWavData(float $durationSeconds): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $dataSize = (int) ($durationSeconds * $byteRate);
        $fileSize = 36 + $dataSize;

        $header = 'RIFF';
        $header .= pack('V', $fileSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $channels * ($bitsPerSample / 8));
        $header .= pack('v', $bitsPerSample);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        return $header . str_repeat("\x00", $dataSize);
    }

    private function injectProcessFactory(PiperProvider $provider, callable $factory): void
    {
        $reflection = new ReflectionProperty(PiperProvider::class, 'processFactory');
        $reflection->setAccessible(true);
        $reflection->setValue($provider, $factory);
    }
}
