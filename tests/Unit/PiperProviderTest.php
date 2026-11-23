<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;

class PiperProviderTest extends TestCase
{
    public function testCloneVoiceThrowsAsPerInterfaceContract(): void
    {
        $provider = new PiperProvider([
            'binary' => 'echo',
            'model' => '/dev/null',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider');

        $provider->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    public function testListVoicesReturnsStructuredArray(): void
    {
        $provider = new PiperProvider([
            'binary' => 'echo',
            'model' => '/dev/null',
        ]);

        $voices = $provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertCount(1, $voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
        $this->assertArrayHasKey('language', $voices[0]);
    }

    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('run');
        $mockProcess->method('isSuccessful')
            ->willReturn(true);
        $mockProcess->method('getOutput')
            ->willReturn($this->createTestWavData());

        $factory = static fn (array $cmd): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => '/dev/null',
        ], $factory);

        $result = $provider->synthesize('Hello from Piper', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertGreaterThan(0.0, $result->duration);
        $this->assertEquals('wav', $result->format);
    }

    public function testSynthesizeThrowsOnProcessFailure(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run');
        $mockProcess->method('isSuccessful')->willReturn(false);
        $mockProcess->method('getErrorOutput')->willReturn('Model not found');

        $factory = static fn (array $cmd): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => '/dev/null',
        ], $factory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Piper synthesis failed');

        $provider->synthesize('Hello', 'default');
    }

    private function createTestWavData(): string
    {
        $header = 'RIFF';
        $header .= pack('V', 36);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', 1);
        $header .= pack('V', 16000);
        $header .= pack('V', 32000);
        $header .= pack('v', 2);
        $header .= pack('v', 16);
        $header .= 'data';
        $header .= pack('V', 0);

        return $header . str_repeat("\0", 100);
    }
}
