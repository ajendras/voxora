<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\ProviderInterface;
use RuntimeException;
use ReflectionClass;

class PiperProviderTest extends TestCase
{
    private array $config = [
        'binary' => 'piper',
        'model' => '/tmp/test-model.onnx',
    ];

    public function testImplementsProviderInterface(): void
    {
        $provider = new PiperProvider($this->config);
        $this->assertInstanceOf(ProviderInterface::class, $provider);
    }

    public function testCloneVoiceThrowsException(): void
    {
        $provider = new PiperProvider($this->config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Voice cloning is not supported by PiperProvider');

        $provider->cloneVoice('Test Voice', '/tmp/sample.wav', 42);
    }

    public function testListVoicesReturnsArrayWithPiperVoices(): void
    {
        $provider = new PiperProvider($this->config);
        $voices = $provider->listVoices();

        $this->assertIsArray($voices);
        $this->assertNotEmpty($voices);
        $this->assertArrayHasKey('provider', $voices[0]);
        $this->assertEquals('piper', $voices[0]['provider']);
    }

    public function testParseWavDurationReturnsFloat(): void
    {
        $provider = new PiperProvider($this->config);

        // Minimal valid WAV header + dummy data (1 channel, 16kHz, 16-bit, ~3 seconds)
        $header = pack(
            'A4VA4A4VvvVVvA4V',
            'RIFF',
            36 + 48000,
            'WAVE',
            'fmt ',
            16,
            1,
            1,
            16000,
            32000,
            2,
            'data',
            48000
        );
        $fakeWav = $header . str_repeat("\0", 48000);

        $reflection = new ReflectionClass($provider);
        $method = $reflection->getMethod('parseWavDuration');
        $method->setAccessible(true);

        $duration = $method->invoke($provider, $fakeWav);

        $this->assertIsFloat($duration);
        $this->assertGreaterThan(2.9, $duration);
        $this->assertLessThan(3.1, $duration);
    }
}
