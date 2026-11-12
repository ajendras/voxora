<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\TTS\SynthesisResult;

/**
 * ProviderInterface
 *
 * Common contract for all TTS backends. New providers must implement
 * both synthesis and voice cloning capabilities (Piper throws on clone).
 */
interface ProviderInterface
{
    /**
     * Synthesize text to speech.
     *
     * @param string $text The text to speak
     * @param string $voiceId Voice identifier (model for Piper, voice_id for ElevenLabs)
     * @param array<string, mixed> $options Additional provider-specific options (stability, similarity_boost etc)
     */
    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult;

    /**
     * Create a cloned voice from an audio sample file.
     * 
     * @param string $name Friendly name for the cloned voice
     * @param string $samplePath Path to audio sample (WAV/MP3 recommended, >30s ideal for ElevenLabs)
     * @return string The new voice identifier to use in synthesize()
     * @throws \InvalidArgumentException if sample is invalid
     * @throws \RuntimeException on API failure
     */
    public function cloneVoice(string $name, string $samplePath): string;

    /**
     * Returns a unique name for this provider (used for routing in Manager).
     */
    public function getName(): string;
}
