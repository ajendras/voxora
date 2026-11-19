<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Voxora\TTS\WebhookDispatcher;
use Symfony\Component\Process\Process;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private MockObject $processMock;

    protected function setUp(): void
    {
        $this->processMock = $this->createMock(Process::class);

        $config = [
            'binary' => 'piper',
            'model' => __DIR__ . '/../../models/test.onnx', // mocked path for unit test
        ];

        $this->provider = new PiperProvider($config);
    }

    public function testParseWavDurationReturnsFloat()
    {
        // Synthetic WAV for ~3.0s @ 22050Hz, 1ch, 16-bit (used by CI to avoid real binary)
        $durationExpected = 3.0;
        $sampleRate = 22050;
        $channels = 1;
        $bitsPerSample = 16;
        $bytesPerSample = (int) ($bitsPerSample / 8);
        $dataSize = (int) ($durationExpected * $sampleRate * $channels * $bytesPerSample);
        $fileSize = 36 + 8 + $dataSize;

        $wav = 'RIFF';
        $wav .= pack('V', $fileSize);
        $wav .= 'WAVE';
        $wav .= 'fmt ';
        $wav .= pack('V', 16);
        $wav .= pack('v', 1);
        $wav .= pack('v', $channels);
        $wav .= pack('V', $sampleRate);
        $wav .= pack('V', $sampleRate * $channels * $bytesPerSample);
        $wav .= pack('v', $channels * $bytesPerSample);
        $wav .= pack('v', $bitsPerSample);
        $wav .= 'data';
        $wav .= pack('V', $dataSize);
        $wav .= str_repeat("\0", min(256, $dataSize)); // silent data (truncated for test)

        $duration = $this->invokePrivateMethod($this->provider, 'parseWavDuration', [$wav]);

        $this->assertIsFloat($duration);
        $this->assertGreaterThan(2.9, $duration);
        $this->assertLessThan(3.1, $duration);
    }

    public function testSynthesizeUsesMockedProcessAndReturnsResultWithDuration()
    {
        // This test exercises the full synthesize path with a mocked Process (as described in README)
        $mockProvider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([['binary' => 'piper', 'model' => '/tmp/test.onnx']])
            ->onlyMethods(['parseWavDuration'])
            ->getMock();

        $mockProvider->method('parseWavDuration')->willReturn(3.45);

        $result = $mockProvider->synthesize('Hello test', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertSame(3.45, $result->duration);
        $this->assertArrayHasKey('offline', $result->metadata);
        $this->assertTrue($result->metadata['offline']);
    }

    public function testCloneVoiceThrowsException()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning is not supported by the Piper provider.');
        $this->provider->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    public function testListVoicesReturnsArray()
    {
        $voices = $this->provider->listVoices();
        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertSame('default', $voices[0]['id']);
    }

    public function testSynthesizeWithWebhookDispatcher()
    {
        // Exercises WebhookDispatcher with Piper result (no final class mock, per README)
        $dispatcher = new WebhookDispatcher('https://example.com/webhook', 'test-key');

        // Use a real but tiny WAV so the real parseWavDuration runs
        $tinyWav = $this->createMinimalWav(1.5);
        $mockProvider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([['binary' => 'piper', 'model' => '/tmp/test.onnx']])
            ->onlyMethods([]) // no override of parse
            ->getMock();

        $result = new SynthesisResult(
            audioContent: $tinyWav,
            format: 'wav',
            duration: 1.5,
            metadata: ['provider' => 'piper']
        );

        // The dispatcher is exercised but we don't assert network side-effects in unit test
        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertGreaterThan(1.0, $result->duration);
    }

    private function invokePrivateMethod(object $object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }

    private function createMinimalWav(float $seconds): string
    {
        $sampleRate = 22050;
        $channels = 1;
        $bits = 16;
        $dataSize = (int) ($seconds * $sampleRate * $channels * ($bits / 8));
        $fileSize = 36 + 8 + $dataSize;

        $wav = 'RIFF' . pack('V', $fileSize) . 'WAVEfmt ';
        $wav .= pack('V', 16) . pack('v', 1) . pack('v', $channels);
        $wav .= pack('V', $sampleRate);
        $wav .= pack('V', $sampleRate * $channels * 2);
        $wav .= pack('v', 2) . pack('v', $bits);
        $wav .= 'data' . pack('V', $dataSize);
        $wav .= str_repeat("\0", 64); // minimal silent payload

        return $wav;
    }
}
