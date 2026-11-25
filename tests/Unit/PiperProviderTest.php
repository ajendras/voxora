<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use RuntimeException;
use ReflectionClass;

/**
 * PiperProviderTest
 *
 * Validates WAV RIFF duration extraction (with mocked Process for CI),
 * cloneVoice exception, listVoices, SSML stripping, error paths, and
 * safe binary unpacking that works on PHP 8.3+.
 */
final class PiperProviderTest extends TestCase
{
    private array $config = [
        'binary' => 'piper',
        'model' => '/usr/share/piper/en_US-lessac-medium.onnx',
    ];

    public function testConstructAcceptsConfig(): void
    {
        $provider = new PiperProvider($this->config);
        $this->assertInstanceOf(PiperProvider::class, $provider);
    }

    public function testSynthesizeWithMockedProcess(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['createProcess'])
            ->getMock();

        $wavData = $this->createTestWav(5.0);

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('run')
            ->willReturn(0);
        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);
        $mockProcess->expects($this->once())
            ->method('getOutput')
            ->willReturn($wavData);

        $provider->expects($this->once())
            ->method('createProcess')
            ->willReturn($mockProcess);

        $result = $provider->synthesize('Test text for synthesis.', 'en-us-voice', []);

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertEqualsWithDelta(5.0, $result->duration, 0.1);
    }

    public function testListVoices(): void
    {
        $provider = new PiperProvider($this->config);
        $voices = $provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertCount(1, $voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
        $this->assertStringContainsString('Piper', $voices[0]['name']);
    }

    public function testCloneVoiceThrowsRuntimeException(): void
    {
        $provider = new PiperProvider($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider');

        $provider->cloneVoice('Test Voice', '/tmp/nonexistent.wav');
    }

    public function testCalculateWavDurationWithValidHeader(): void
    {
        $provider = new PiperProvider($this->config);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('calculateWavDuration');
        $method->setAccessible(true);

        $wav = $this->createTestWav(4.5);
        $duration = $method->invoke($provider, $wav);

        $this->assertEqualsWithDelta(4.5, $duration, 0.01);
    }

    public function testCalculateWavDurationWithShortDataReturnsZero(): void
    {
        $provider = new PiperProvider($this->config);
        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('calculateWavDuration');
        $method->setAccessible(true);

        $duration = $method->invoke($provider, 'RIFFshort');

        $this->assertSame(0.0, $duration);
    }

    public function testThrowsOnEmptyText(): void
    {
        $provider = new PiperProvider($this->config);

        $this->expectException(RuntimeException::class);

        $provider->synthesize('   ', 'voice1');
    }

    public function testThrowsOnProcessFailure(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['createProcess'])
            ->getMock();

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(1);
        $mockProcess->method('isSuccessful')->willReturn(false);
        $mockProcess->method('getErrorOutput')->willReturn('Command not found');

        $provider->method('createProcess')->willReturn($mockProcess);

        $this->expectException(RuntimeException::class);
        $provider->synthesize('hello', 'voice');
    }

    public function testStripsSSMLTagsBeforeSynthesis(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['createProcess'])
            ->getMock();

        $wavData = $this->createTestWav(1.0);
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(0);
        $mockProcess->method('isSuccessful')->willReturn(true);
        $mockProcess->method('getOutput')->willReturn($wavData);

        $provider->expects($this->once())
            ->method('createProcess')
            ->with('Hello world')
            ->willReturn($mockProcess);

        $result = $provider->synthesize('<speak>Hello <break time="500ms"/> world</speak>', 'v1', []);

        $this->assertInstanceOf(SynthesisResult::class, $result);
    }

    public function testUsesDefaultBinaryIfNotProvided(): void
    {
        $provider = new PiperProvider(['model' => '/tmp/test.onnx']);
        $reflection = new ReflectionClass($provider);
        $property = $reflection->getProperty('config');
        $property->setAccessible(true);
        $config = $property->getValue($provider);

        $this->assertSame('piper', $config['binary']);
    }

    public function testHandlesZeroByteAudioGracefully(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([$this->config])
            ->onlyMethods(['createProcess'])
            ->getMock();

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(0);
        $mockProcess->method('isSuccessful')->willReturn(true);
        $mockProcess->method('getOutput')->willReturn(str_repeat("\0", 100));

        $provider->method('createProcess')->willReturn($mockProcess);

        $result = $provider->synthesize('test zero output', 'v1');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertGreaterThan(0.0, $result->duration ?? 0.0);
    }

    private function createTestWav(float $duration): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $dataSize = (int) ($duration * $byteRate);
        $fileSize = 36 + $dataSize;

        $header = 'RIFF' . pack('V', $fileSize) . 'WAVEfmt ' .
            pack('V', 16) .
            pack('v', 1) .
            pack('v', $channels) .
            pack('V', $sampleRate) .
            pack('V', $byteRate) .
            pack('v', $channels * ($bitsPerSample / 8)) .
            pack('v', $bitsPerSample) .
            'data' .
            pack('V', $dataSize);

        $audioData = str_repeat("\x00", $dataSize);

        return $header . $audioData;
    }
}
