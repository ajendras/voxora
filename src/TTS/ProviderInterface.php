<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\TTS\SynthesisResult;

/**
 * ProviderInterface
 *
 * Contract for all TTS backends. The plugin system (Manager::registerProvider)
 * allows any implementation to be added at runtime. Built-in implementations
 * are ElevenLabsProvider and PiperProvider.
 */
interface ProviderInterface
{
    /**
     * Synthesize text (or SSML) into audio.
     *
     * @param string $text Text or SSML content
     * @param string $voiceId Voice identifier
     * @param array<string, mixed> $options Options such as format, normalize, target_loudness
     * @return SynthesisResult
     */
    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult;

    /**
     * Clone a voice from an audio sample. Providers that do not support cloning
     * (e.g. Piper) must throw RuntimeException.
     *
     * @param string $name Human-readable name for the cloned voice
     * @param string $samplePath Path to the audio sample file
     * @param array<string, mixed> $options Provider-specific options
     * @return string The provider-assigned voice identifier
     * @throws \RuntimeException When cloning is not supported
     */
    public function cloneVoice(string $name, string $samplePath, array $options = []): string;
}
