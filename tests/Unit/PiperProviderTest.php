<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use RuntimeException;

final class PiperProviderTest extends TestCase
{
    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration(): void
    {
        $fakeWav = $this->createFakeWavWithDuration(4.75);
        $mockProcess = $this->createMock(Process::class);

        $mockProcess->expects($this->once())
            ->method('setInput')
            ->with('Hello from Piper test');

        $mockProcess->expects($this->once())
            ->method('run');

        $mockProcess->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);

        $mockProcess->expects($this->once())
            ->method('getOutput')
            ->willReturn($fakeWav);

        $factory = static fn (array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => '/tmp/test.onnx',
        ], $factory);

        $result = $provider->synthesize('Hello from Piper test', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertSame(4.75, $result->duration);
        $this->assertSame($fakeWav, $result->audio);
    }

    public function testListVoicesReturnsExpectedStructure(): void
    {
        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => '/tmp/test.onnx',
        ]);

        $voices = $provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertCount(1, $voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
        $this->assertSame('default', $voices[0]['id']);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => '/tmp/test.onnx',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider');

        $provider->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    private function createFakeWavWithDuration(float $duration): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $dataSize = (int) ($duration * $byteRate);
        $fileSize = 36 + 8 + $dataSize;

        $header = 'RIFF';
        $header .= pack('V', $fileSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $channels * $bitsPerSample / 8);
        $header .= pack('v', $bitsPerSample);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        $data = str_repeat("\x00", $dataSize);

        return $header . $data;
    }
}
