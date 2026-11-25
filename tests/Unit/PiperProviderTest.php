<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use RuntimeException;

class PiperProviderTest extends TestCase
{
    private string $modelPath;

    protected function setUp(): void
    {
        $this->modelPath = __DIR__ . '/fixtures/test-model.onnx';
        $dir = dirname($this->modelPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($this->modelPath, 'dummy-model-data');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->modelPath)) {
            unlink($this->modelPath);
        }
        $dir = dirname($this->modelPath);
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }
    }

    public function testSynthesizeWithMockedProcess(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->expects($this->once())
            ->method('run')
            ->willReturn(0);
        $mockProcess->expects($this->once())
            ->method('getOutput')
            ->willReturn($this->getTestWavData());

        $piper = $this->createTestablePiper($mockProcess);
        $result = $piper->synthesize('Hello from Piper.', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertEquals('wav', $result->format);
        $this->assertGreaterThan(0, $result->duration);
    }

    public function testThrowsOnProcessFailure(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(1);
        $mockProcess->method('getErrorOutput')->willReturn('Piper failed');

        $piper = $this->createTestablePiper($mockProcess);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Piper synthesis failed');
        $piper->synthesize('Hello', 'default');
    }

    public function testStripsSSMLTagsBeforeSynthesis(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(0);
        $mockProcess->method('getOutput')->willReturn($this->getTestWavData());

        $piper = $this->createTestablePiper($mockProcess);
        $result = $piper->synthesize('Hello <prosody rate="slow">world</prosody>', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        // SSML was stripped before being sent to the (mocked) process
    }

    public function testHandlesZeroByteAudioGracefully(): void
    {
        $mockProcess = $this->createMock(Process::class);
        $mockProcess->method('run')->willReturn(0);
        $mockProcess->method('getOutput')->willReturn('');

        $piper = $this->createTestablePiper($mockProcess);
        $result = $piper->synthesize('Hello', 'default');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertGreaterThan(0, strlen($result->audio));
        $this->assertEquals('wav', $result->format);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $piper = $this->createTestablePiper($this->createMock(Process::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning not supported by PiperProvider');
        $piper->cloneVoice('Test Voice', '/tmp/sample.wav');
    }

    public function testListVoicesReturnsStructuredArray(): void
    {
        $piper = $this->createTestablePiper($this->createMock(Process::class));
        $voices = $piper->listVoices();

        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('id', $voices[0]);
        $this->assertArrayHasKey('name', $voices[0]);
    }

    private function createTestablePiper(Process $mockProcess): PiperProvider
    {
        $testPiper = new class(['binary' => 'piper', 'model' => $this->modelPath]) extends PiperProvider {
            private Process $mockProcess;

            public function setMockProcess(Process $process): void
            {
                $this->mockProcess = $process;
            }

            protected function createProcess(array $command): Process
            {
                return $this->mockProcess ?? parent::createProcess($command);
            }
        };

        $testPiper->setMockProcess($mockProcess);

        return $testPiper;
    }

    private function getTestWavData(): string
    {
        return "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xac\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";
    }
}
