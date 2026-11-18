<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use InvalidArgumentException;
use ReflectionMethod;

final class PiperProviderTest extends TestCase
{
    private string $dummyModel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dummyModel = sys_get_temp_dir() . '/piper-test-model-' . uniqid() . '.onnx';
        touch($this->dummyModel);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dummyModel)) {
            unlink($this->dummyModel);
        }
        parent::tearDown();
    }

    public function testCloneVoiceThrowsException(): void
    {
        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => $this->dummyModel,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Piper does not support voice cloning');

        $provider->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    public function testExtractWavDurationFromRiffHeader(): void
    {
        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => $this->dummyModel,
        ]);

        $method = new ReflectionMethod($provider, 'extractWavDurationFromRiffHeader');
        $method->setAccessible(true);

        // Synthetic WAV for exactly 5 seconds, 22050 Hz, mono, 16-bit
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $bytesPerSample = $bitsPerSample / 8;
        $duration = 5.0;
        $dataSize = (int) ($sampleRate * $channels * $bytesPerSample * $duration);

        $header = 'RIFF' .
            pack('V', 36 + $dataSize) .
            'WAVEfmt ' .
            pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $channels * $bytesPerSample, $channels * $bytesPerSample, $bitsPerSample) .
            'data' .
            pack('V', $dataSize);

        $wavData = $header . str_repeat("\0", $dataSize);

        $result = $method->invoke($provider, $wavData);

        $this->assertEqualsWithDelta(5.0, $result, 0.01);
    }

    public function testSynthesizeReturnsSynthesisResult(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([[
                'binary' => 'piper',
                'model' => $this->dummyModel,
            ]])
            ->onlyMethods(['createProcess'])
            ->getMock();

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('setInput')
            ->with('Hello from Piper');
        $mockProcess->expects($this->once())
            ->method('run');
        $mockProcess->method('isSuccessful')
            ->willReturn(true);
        $mockProcess->method('getOutput')
            ->willReturn($this->createTestWavData(2.5));
        $mockProcess->method('getErrorOutput')
            ->willReturn('');

        $provider->method('createProcess')
            ->willReturn($mockProcess);

        $result = $provider->synthesize('Hello from Piper', 'default-voice', ['format' => 'wav']);

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertEqualsWithDelta(2.5, $result->duration, 0.01);
        $this->assertArrayHasKey('provider', $result->metadata);
        $this->assertSame('piper', $result->metadata['provider']);
    }

    private function createTestWavData(float $duration = 3.0): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $dataSize = (int) ($sampleRate * $channels * 2 * $duration);

        $header = 'RIFF' .
            pack('V', 36 + $dataSize) .
            'WAVEfmt ' .
            pack('VvvVVvv', 16, 1, $channels, $sampleRate, $sampleRate * $channels * 2, 2, 16) .
            'data' .
            pack('V', $dataSize);

        return $header . str_repeat("\0", min(1024, $dataSize));
    }
}
