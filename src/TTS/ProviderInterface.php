<?php

declare(strict_types=1);

namespace Voxora\TTS;

use RuntimeException;

/**
 * ProviderInterface
 *
 * Contract for all TTS backends. Implement this to add new providers via the
 * plugin system. The TTS\Manager routes calls, applies caching, post-processing,
 * fallback and usage tracking automatically.
 */
interface ProviderInterface
{
    /**
     * Generate speech audio for the given text and voice.
     *
     * SSML support is provider-dependent. ElevenLabs and Piper accept limited SSML;
     * OpenAIProvider automatically strips tags.
     *
     * Provider-specific parameters are supported through the $options array and
     * forwarded/normalized by Manager::generate() and Manager::normalizeOptions():
     *  - stability (float 0-1): voice stability (ElevenLabs)
     *  - similarity (float 0-1): similarity boost (ElevenLabs, mapped to similarity_boost)
     *  - style (float 0-1): speaking style/exaggeration (ElevenLabs)
     *  - speed (float): speech rate. OpenAI 0.25-4.0, ElevenLabs ~0.5-2.0
     * Other common options:
     *  - format: 'mp3' or 'wav'
     *  - model: backend model identifier
     *  - normalize: bool - whether SynthesisResult should run FFmpeg loudnorm
     *  - target_loudness: float - LUFS target for normalization
     *
     * @param string $text Input text (or SSML)
     * @param string $voiceId Voice identifier
     * @param array $options Provider-specific and common options
     * @return SynthesisResult Audio bytes, format and duration
     * @throws RuntimeException If the provider fails (triggers fallback if configured)
     */
    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult;

    /**
     * Return list of available voices.
     *
     * Each voice must contain at least 'id' and 'name'. Additional recommended keys:
     * 'language', 'gender', 'description', 'is_cloned'.
     */
    public function listVoices(): array;

    /**
     * Create a cloned voice from an audio sample.
     *
     * @param string $name Name for the new voice
     * @param string $samplePath Path to audio sample file (WAV/MP3)
     * @param array $options Additional options (e.g. description, labels)
     * @return string The new voice ID assigned by the provider
     * @throws RuntimeException if cloning is not supported by this provider
     */
    public function cloneVoice(string $name, string $samplePath, array $options = []): string;
}
