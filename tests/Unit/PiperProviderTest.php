<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\MockObject\MockObject;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private MockObject $processMock;

    protected function setUp(): void
    {
        $this->processMock = $this->createMock(Process::class);

        $processFactory = fn(): Process => $this->processMock;

        $config = [
            'binary' => 'piper',
            'model' => __DIR__ . '/../../models/en_US-lessac-medium.onnx',
        ];

        $this->provider = new PiperProvider($config, $processFactory);
    }

    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration(): void
    {
        $this->processMock->expects($this->once())
            ->method('run')
            ->willReturn(0);

        $this->processMock->expects($this->once())
            ->method('getOutput')
            ->willReturn($this->createTestWav(3.75));

        $result = $this->provider->synthesize('Hello world from Piper.', 'en_US-lessac-medium');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertEquals(3.75, $result->duration);
        $this->assertEquals('wav', $result->format);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by this provider');

        $this->provider->cloneVoice('My Voice', '/tmp/sample.wav');
    }

    public function testListVoicesReturnsArray(): void
    {
        $voices = $this->provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
    }

    private function createTestWav(float $duration): string
    {
        $sampleRate = 22050;
        $numChannels = 1;
        $bitsPerSample = 16;
        $byteRate = (int) ($sampleRate * $numChannels * $bitsPerSample / 8);
        $dataSize = (int) ($duration * $byteRate);
        $fileSize = 36 + $dataSize;

        $header = 'RIFF' .
            pack('V', $fileSize) .
            'WAVEfmt ' .
            pack('V', 16) .
            pack('v', 1) .
            pack('v', $numChannels) .
            pack('V', $sampleRate) .
            pack('V', $byteRate) .
            pack('v', $numChannels * $bitsPerSample / 8) .
            pack('v', $bitsPerSample) .
            'data' .
            pack('V', $dataSize);

        return $header . str_repeat("\0", min(256, $dataSize));
    }
}
