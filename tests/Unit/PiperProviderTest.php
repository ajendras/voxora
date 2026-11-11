<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use InvalidArgumentException;
use ReflectionMethod;

class PiperProviderTest extends TestCase
{
    public function testConstructorRequiresModelPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PiperProvider('piper', '');
    }

    public function testWavDurationCalculation(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs(['piper', __DIR__ . '/nonexistent.onnx'])
            ->getMock();

        $method = new ReflectionMethod($provider, 'getWavDuration');
        $method->setAccessible(true);

        // Construct a minimal valid WAV for 2 seconds at 22050Hz, 1 channel, 16-bit
        $sampleRate = 22050;
        $channels = 1;
        $bits = 16;
        $seconds = 2.0;
        $byteRate = $sampleRate * $channels * ($bits / 8);
        $dataSize = (int) ($byteRate * $seconds);

        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVEfmt ' .
            pack('V', 16) . pack('v', 1) . pack('v', $channels) .
            pack('V', $sampleRate) . pack('V', $byteRate) .
            pack('v', $channels * ($bits / 8)) . pack('v', $bits) .
            'data' . pack('V', $dataSize);

        $fakeAudio = $header . str_repeat("\x00", $dataSize);

        $duration = $method->invoke($provider, $fakeAudio);
        $this->assertEqualsWithDelta(2.0, $duration, 0.01);
    }

    public function testFallbackDurationOnInvalidWav(): void
    {
        $provider = $this->getMockBuilder(PiperProvider::class)
            ->setConstructorArgs(['piper', __DIR__ . '/nonexistent.onnx'])
            ->getMock();

        $method = new ReflectionMethod($provider, 'getWavDuration');
        $method->setAccessible(true);

        $duration = $method->invoke($provider, 'not-a-wav-file-content-at-all');
        $this->assertGreaterThan(0.4, $duration);
    }
}
