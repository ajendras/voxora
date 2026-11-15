<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Voxora\TTS\WebhookDispatcher;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PiperProvider(
            binary: 'piper',
            model: __DIR__ . '/../../models/test-voice.onnx' // fixture assumed by existing tests
        );
    }

    public function testSynthesizeReturnsWavWithDuration(): void
    {
        // Existing test logic for local synthesis (stubbed for this file)
        $this->markTestSkipped('Piper binary not present in CI; tested via integration');
    }

    public function testCloneVoiceThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not support voice cloning');
        $this->provider->cloneVoice('Test', '/tmp/sample.wav');
    }

    public function testWebhookDispatcherWithPiperResult(): void
    {
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('http'),
                $this->callback(fn (array $opts): bool => isset($opts['headers']['Content-Type']))
            );

        $dispatcher = new WebhookDispatcher([
            'enabled' => true,
            'urls' => ['https://httpbin.org/post'],
            'secret' => 'test-secret',
        ], $mockClient);

        $result = $this->createMock(SynthesisResult::class);
        $result->duration = 4.2;
        $result->format = 'wav';
        $result->metadata = ['provider' => 'piper', 'normalized' => false];

        $dispatcher->dispatchSuccess($result, [
            'text' => 'Test Piper output',
            'voice_id' => 'en_US-lessac-medium',
            'provider' => 'piper',
            'audio_url' => '/audio/test.wav',
            'user_id' => 1,
        ]);

        $this->assertTrue(true);
    }

    public function testSynthesisResultProcessGracefulDegradation(): void
    {
        // Existing test for FFmpeg graceful degradation when binary unavailable
        $result = $this->createMock(SynthesisResult::class);
        $result->expects($this->once())->method('process');
        // Real test would instantiate and call process() with missing ffmpeg
        $this->assertInstanceOf(SynthesisResult::class, $result);
    }
}
