<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use Voxora\TTS\WebhookDispatcher;
use PHPUnit\Framework\MockObject\MockObject;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private WebhookDispatcher|MockObject $webhookDispatcher;

    protected function setUp(): void
    {
        $this->webhookDispatcher = $this->createMock(WebhookDispatcher::class);
        $config = [
            'binary' => 'piper',
            'model' => __DIR__ . '/fixtures/test.onnx',
        ];
        $this->provider = new PiperProvider($config, $this->webhookDispatcher);
    }

    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('mustRun')
            ->willReturnSelf();

        $mockProcess->expects($this->once())
            ->method('getOutput')
            ->willReturn($this->createValidWavAudio(3.25));

        // Inject mock process via reflection (matches existing test style for CI stability)
        $reflection = new \ReflectionClass($this->provider);
        $processProp = $reflection->getProperty('process');
        $processProp->setAccessible(true);
        $processProp->setValue($this->provider, $mockProcess);

        $result = $this->provider->synthesize('Hello from Piper', 'en_US-lessac-medium');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertGreaterThan(0.0, $result->duration);
        $this->assertEqualsWithDelta(3.25, $result->duration, 0.1);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by this provider');

        $this->provider->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    public function testListVoicesReturnsArray(): void
    {
        $voices = $this->provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
    }

    private function createValidWavAudio(float $durationSeconds): string
    {
        $sampleRate = 22050;
        $numChannels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $numChannels * (int) ($bitsPerSample / 8);
        $dataSize = (int) ($byteRate * $durationSeconds);
        $fileSize = 36 + $dataSize;

        $wav = 'RIFF';
        $wav .= pack('V', $fileSize);
        $wav .= 'WAVE';
        $wav .= 'fmt ';
        $wav .= pack('V', 16);
        $wav .= pack('v', 1);
        $wav .= pack('v', $numChannels);
        $wav .= pack('V', $sampleRate);
        $wav .= pack('V', $byteRate);
        $wav .= pack('v', $numChannels * ($bitsPerSample / 8));
        $wav .= pack('v', $bitsPerSample);
        $wav .= 'data';
        $wav .= pack('V', $dataSize);
        $wav .= str_repeat("\x00", $dataSize);

        return $wav;
    }
}
