<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\WebhookDispatcher;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use InvalidArgumentException;
use RuntimeException;

final class PiperProviderTest extends TestCase
{
    private function getTestWavData(): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * (int)($bitsPerSample / 8);
        $duration = 2.0;
        $dataSize = (int) ($byteRate * $duration);
        $fileSize = 36 + $dataSize;

        $header = pack(
            'A4V A4 A4 V v v V V v v A4 V',
            'RIFF',
            $fileSize,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            $sampleRate,
            $byteRate,
            $channels * 2,
            $bitsPerSample,
            'data',
            $dataSize
        );

        $audioData = str_repeat("\x00\x00", (int)($dataSize / 2));

        return $header . $audioData;
    }

    public function testSynthesizeReturnsWavWithDuration(): void
    {
        $wavData = $this->getTestWavData();

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('mustRun');
        $mockProcess->method('getOutput')
            ->willReturn($wavData);

        $factory = fn (array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => __DIR__ . '/dummy-model.onnx',
            'process_factory' => $factory,
        ]);

        $result = $provider->synthesize('Hello from Piper', 'default-voice');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertGreaterThan(0.0, $result->duration);
        $this->assertArrayHasKey('provider', $result->metadata);
        $this->assertSame('piper', $result->metadata['provider']);
        $this->assertArrayHasKey('offline', $result->metadata);
        $this->assertTrue($result->metadata['offline']);
    }

    public function testWebhookDispatcherWithPiperResult(): void
    {
        // Use real dispatcher (no URL = no-op). This avoids the final-class mock error.
        $dispatcher = new WebhookDispatcher();

        $wavData = $this->getTestWavData();

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('mustRun');
        $mockProcess->method('getOutput')
            ->willReturn($wavData);

        $factory = fn (array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => __DIR__ . '/dummy-model.onnx',
            'process_factory' => $factory,
        ]);

        $result = $provider->synthesize('Test for webhook', 'voice123');

        // Dispatcher accepts Piper result without error
        $dispatcher->dispatchSuccess($result, [
            'provider' => 'piper',
            'text' => 'Test for webhook',
            'batch' => false,
        ]);

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertGreaterThan(0.0, $result->duration);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => __DIR__ . '/dummy-model.onnx',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning is not supported by the Piper local provider');

        $provider->cloneVoice('Test Voice', '/path/to/sample.wav');
    }

    public function testThrowsOnEmptyText(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $factory = fn (array $command): Process => $mockProcess;

        $provider = new PiperProvider([
            'binary' => 'piper',
            'model' => __DIR__ . '/dummy-model.onnx',
            'process_factory' => $factory,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Text cannot be empty for synthesis.');

        $provider->synthesize('', 'voice123');
    }
}
