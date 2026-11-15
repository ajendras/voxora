<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use Voxora\TTS\WebhookDispatcher;
use RuntimeException;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private string $modelPath;

    protected function setUp(): void
    {
        $this->modelPath = __DIR__ . '/fixtures/test-model.onnx';
        $this->provider = new PiperProvider([
            'binary' => 'echo',
            'model' => $this->modelPath,
        ]);
    }

    public function testSynthesizeReturnsWavWithDuration()
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('setInput')
            ->with('Hello from Voxora');
        $mockProcess->expects($this->once())
            ->method('mustRun');

        $dummyWav = $this->createDummyWav(2.85);

        $this->provider->setProcessFactory(function (array $command) use ($mockProcess, $dummyWav) {
            foreach ($command as $i => $arg) {
                if ($arg === '--output_file' && isset($command[$i + 1])) {
                    file_put_contents($command[$i + 1], $dummyWav);
                    break;
                }
            }
            return $mockProcess;
        });

        $result = $this->provider->synthesize('Hello from Voxora', 'test-voice-123');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertSame('piper', $result->provider);
        $this->assertGreaterThan(0.0, $result->duration);
        $this->assertArrayHasKey('voice_id', $result->metadata);
    }

    public function testCloneVoiceThrowsException()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning is not supported by the Piper provider');

        $this->provider->cloneVoice('My Cloned Voice', '/tmp/sample.wav');
    }

    public function testWebhookDispatcherWithPiperResult()
    {
        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatchSuccess')
            ->with(
                $this->isInstanceOf(SynthesisResult::class),
                $this->callback(fn(array $ctx): bool => ($ctx['provider'] ?? '') === 'piper')
            );

        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('setInput')->willReturn(null);
        $mockProcess->method('mustRun')->willReturn(null);

        $dummyWav = $this->createDummyWav(1.2);
        $this->provider->setProcessFactory(fn(): Process => $mockProcess);

        $result = $this->provider->synthesize('Webhook test text', 'v0-test');

        $dispatcher->dispatchSuccess($result, [
            'provider' => 'piper',
            'text' => 'Webhook test text',
            'audio_url' => '/audio/test.wav',
        ]);

        $this->assertSame('wav', $result->format);
    }

    public function testSynthesisResultProcessGracefulDegradation()
    {
        $dummyWav = $this->createDummyWav(4.2);
        $original = new SynthesisResult(
            $dummyWav,
            'wav',
            'piper',
            ['voice_id' => 'test-voice'],
            4.2
        );

        $processed = $original->process([
            'normalize' => true,
            'target_loudness' => -16.0,
            'format' => 'mp3',
            'ffmpeg_binary' => '/nonexistent/ffmpeg-that-will-fail',
        ]);

        $this->assertInstanceOf(SynthesisResult::class, $processed);
        $this->assertFalse($processed->metadata['normalized'] ?? true);
        $this->assertArrayHasKey('processing_error', $processed->metadata);
        $this->assertEqualsWithDelta(4.2, $processed->duration ?? 0.0, 0.1);
        $this->assertSame('wav', $processed->format);
    }

    private function createDummyWav(float $durationSeconds): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $dataSize = (int) ($byteRate * $durationSeconds);
        $fileSize = 36 + $dataSize;

        $header = pack(
            'A4Vv2Vv2Vv2A4V',
            'RIFF',
            $fileSize,
            1,
            16,
            1,
            $channels,
            $sampleRate,
            $byteRate,
            $channels * ($bitsPerSample / 8),
            $bitsPerSample,
            'data',
            $dataSize
        );

        $audioData = str_repeat("\x00", $dataSize);

        return $header . $audioData;
    }
}
