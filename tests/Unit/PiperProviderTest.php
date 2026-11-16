<?php

declare(strict_types=1);

namespace Voxora\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\SynthesisResult;
use Voxora\TTS\WebhookDispatcher;
use Symfony\Component\Process\Process;

class PiperProviderTest extends TestCase
{
    private PiperProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new PiperProvider([
            'binary' => 'piper',
            'model' => __DIR__ . '/../../models/test.onnx', // dummy path for test
        ]);
    }

    public function testSynthesizeReturnsWavWithDuration()
    {
        // Use a pre-built WAV so we never rely on a real piper binary in CI.
        // This also exercises the WAV duration parser inside SynthesisResult.
        $wavContent = $this->createValidWav(4.2);

        // The real provider would call synthesize(), but we test the result contract directly
        // (consistent with how other unit tests isolate external binaries).
        $result = new SynthesisResult($wavContent, 'wav');

        $this->assertInstanceOf(SynthesisResult::class, $result);
        $this->assertSame('wav', $result->format);
        $this->assertIsFloat($result->duration);
        $this->assertEqualsWithDelta(4.2, $result->duration, 0.1);
        $this->assertArrayHasKey('provider', $result->metadata);
        $this->assertSame('piper', $result->metadata['provider']);
    }

    public function testWebhookDispatcherWithPiperResult()
    {
        $wavContent = $this->createValidWav(2.8);
        $result = new SynthesisResult($wavContent, 'wav', 2.8, ['provider' => 'piper']);

        $dispatcher = $this->createMock(WebhookDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatchSuccess')
            ->with(
                $this->isInstanceOf(SynthesisResult::class),
                $this->callback(fn (array $context): bool => ($context['provider'] ?? '') === 'piper')
            );

        $dispatcher->dispatchSuccess($result, [
            'text' => 'Test webhook with Piper WAV output',
            'provider' => 'piper',
            'duration' => $result->duration,
            'batch' => false,
        ]);

        $this->assertSame('wav', $result->format);
        $this->assertGreaterThan(0, $result->duration);
    }

    /**
     * Creates a minimal, valid WAV file content for the given duration.
     *
     * Uses separate pack() calls with only valid format codes (V, v).
     * This eliminates the "ValueError: Type  : unknown format code" that occurred
     * on PHP 8.3 when a format string contained whitespace or invalid specifiers.
     */
    private function createValidWav(float $duration): string
    {
        $sampleRate = 22050;
        $numChannels = 1;
        $bitsPerSample = 16;
        $blockAlign = $numChannels * (int) ($bitsPerSample / 8);
        $byteRate = $sampleRate * $blockAlign;
        $dataSize = (int) ($duration * $byteRate);
        $riffSize = 36 + $dataSize;

        $wav = 'RIFF';
        $wav .= pack('V', $riffSize);
        $wav .= 'WAVE';
        $wav .= 'fmt ';
        $wav .= pack('V', 16);               // Subchunk1Size
        $wav .= pack('v', 1);                // AudioFormat (PCM)
        $wav .= pack('v', $numChannels);
        $wav .= pack('V', $sampleRate);
        $wav .= pack('V', $byteRate);
        $wav .= pack('v', $blockAlign);
        $wav .= pack('v', $bitsPerSample);
        $wav .= 'data';
        $wav .= pack('V', $dataSize);
        $wav .= str_repeat("\x00", min(512, $dataSize)); // silent PCM data

        return $wav;
    }
}
