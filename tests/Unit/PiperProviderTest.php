<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Voxora\TTS\WebhookDispatcher;
use Symfony\Component\Process\Process;
use RuntimeException;
use ReflectionMethod;

final class PiperProviderTest extends TestCase
{
    private string $tempModelPath;

    protected function setUp(): void
    {
        $this->tempModelPath = tempnam(sys_get_temp_dir(), 'test_piper_model_') . '.onnx';
        touch($this->tempModelPath);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempModelPath)) {
            unlink($this->tempModelPath);
        }
    }

    private function createFakeWavHeader(int $sampleRate, int $channels, int $bits, int $dataBytes): string
    {
        $byteRate = $sampleRate * $channels * (int) ($bits / 8);
        $blockAlign = $channels * (int) ($bits / 8);
        $fileSize = 36 + $dataBytes;

        $header = 'RIFF';
        $header .= pack('V', $fileSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);
        $header .= pack('v', 1);
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', $byteRate);
        $header .= pack('v', $blockAlign);
        $header .= pack('v', $bits);
        $header .= 'data';
        $header .= pack('V', $dataBytes);
        $header .= str_repeat("\0", min(256, $dataBytes));

        return $header;
    }

    public function testParseWavDurationReturnsFloat(): void
    {
        $provider = new PiperProvider(['model' => $this->tempModelPath]);

        $method = new ReflectionMethod(PiperProvider::class, 'parseWavDuration');
        $method->setAccessible(true);

        $wav = $this->createFakeWavHeader(22050, 1, 16, 88200); // ~2s
        $duration = $method->invoke($provider, $wav);

        $this->assertIsFloat($duration);
        $this->assertEqualsWithDelta(2.0, $duration, 0.01);
    }

    public function testSynthesizeUsesMockedProcessAndReturnsResultWithDuration(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('setInput')
            ->with($this->isType('string'));
        $mockProcess->expects($this->once())
            ->method('run');
        $mockProcess->method('isSuccessful')
            ->willReturn(true);
        $mockProcess->method('getOutput')
            ->willReturn($this->createFakeWavHeader(22050, 1, 16, 88200));

        $factory = fn(array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'model' => $this->tempModelPath,
            'process_factory' => $factory,
        ]);

        $result = $provider->synthesize('Hello from Piper test.', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertEqualsWithDelta(2.0, $result->duration ?? 0.0, 0.01);
        $this->assertArrayHasKey('provider', $result->metadata);
        $this->assertSame('piper', $result->metadata['provider']);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $provider = new PiperProvider(['model' => $this->tempModelPath]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning is not supported by the Piper provider.');

        $provider->cloneVoice('Test Voice', '/tmp/nonexistent.wav');
    }

    public function testListVoicesReturnsArray(): void
    {
        $provider = new PiperProvider(['model' => $this->tempModelPath]);
        $voices = $provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
        $this->assertSame('piper', $voices[0]['provider']);
    }

    public function testSynthesizeWithWebhookDispatcher(): void
    {
        $mockDispatcher = $this->createMock(WebhookDispatcher::class);
        $mockDispatcher->expects($this->once())
            ->method('dispatchSuccess');

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('setInput');
        $mockProcess->expects($this->once())
            ->method('run');
        $mockProcess->method('isSuccessful')
            ->willReturn(true);
        $mockProcess->method('getOutput')
            ->willReturn($this->createFakeWavHeader(22050, 1, 16, 44100));

        $factory = fn(array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'model' => $this->tempModelPath,
            'process_factory' => $factory,
            'webhook_dispatcher' => $mockDispatcher,
        ]);

        $result = $provider->synthesize('Webhook test text.', 'default-voice');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertGreaterThan(0.0, $result->duration ?? 0.0);
    }
}
