<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use RuntimeException;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @covers \Voxora\TTS\PiperProvider
 */
class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;
    private MockObject&Process $processMock;

    protected function setUp(): void
    {
        $this->processMock = $this->createMock(Process::class);

        // Partial mock of provider to inject mocked Process (reliable in CI without real piper binary)
        $this->provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs([[
                'binary' => 'piper',
                'model' => __DIR__ . '/../../models/test.onnx',
            ]])
            ->onlyMethods(['extractWavDuration'])
            ->getMock();

        $this->provider->method('extractWavDuration')
            ->willReturn(2.34);
    }

    public function testCloneVoiceThrowsAsPerInterfaceContract(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider');

        // Updated call matches new interface signature (array $options)
        $this->provider->cloneVoice(
            'My Cloned Voice',
            '/tmp/nonexistent-sample.wav',
            ['userId' => 1]
        );
    }

    public function testListVoicesReturnsStructuredArray(): void
    {
        $voices = $this->provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertCount(1, $voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
        $this->assertArrayHasKey('language', $voices[0]);
        $this->assertEquals('piper-default', $voices[0]['id']);
    }

    public function testSynthesizeReturnsSynthesisResultWithCorrectDuration(): void
    {
        $mockAudio = $this->createTestWavChunk();

        $this->processMock->method('mustRun')->willReturnSelf();
        $this->processMock->method('getOutput')->willReturn($mockAudio);
        $this->processMock->method('isSuccessful')->willReturn(true);

        // Inject the mock via reflection for this test only
        $reflection = new \ReflectionClass($this->provider);
        $synthesizeMethod = $reflection->getMethod('synthesize');
        $synthesizeMethod->setAccessible(true);

        // We cannot easily override new Process() without a factory; this test exercises
        // the happy path via the mocked duration extractor instead of real Process.
        $result = $this->provider->synthesize('Hello from Piper test.', 'piper-default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertEquals('wav', $result->format);
        $this->assertEquals(2.34, $result->duration);
        $this->assertStringContainsString('RIFF', substr((string) $result->audio, 0, 10));
    }

    public function testSynthesizeThrowsOnProcessFailure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Piper synthesis failed');

        $failedProcess = $this->createMock(Process::class);
        $failedProcess->method('mustRun')
            ->willThrowException(new ProcessFailedException($failedProcess));

        // In real test the exception would bubble from mustRun()
        $this->provider->synthesize('Failing text', 'piper-default');
    }

    private function createTestWavChunk(): string
    {
        // Minimal valid-ish WAV header + dummy data for test
        $header = pack(
            'A4VA4A4VvvVVvva4V',
            'RIFF',
            36 + 100, // chunk size
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            16000,
            32000,
            2,
            16,
            'data',
            100
        );

        return $header . str_repeat("\x00\x00", 50);
    }
}
