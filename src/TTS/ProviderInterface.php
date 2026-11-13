<?php

declare(strict_types=1);

namespace Voxora\TTS;

/**
 * ProviderInterface
 *
 * Common contract for all TTS backends (ElevenLabs, Piper, future plugins).
 * The returned SynthesisResult can be further processed with FFmpeg via
 * $result->process(['normalize' => true, 'target_loudness' => -16.0, 'format' => 'mp3']).
 */
interface ProviderInterface
{
    /**
     * Synthesize speech. ElevenLabs returns MP3; Piper returns WAV.
     * Post-processing (normalization, conversion) is handled by Manager.
     */
    public function synthesize(string $text, string $voiceId): SynthesisResult;

    /**
     * Clone a voice from an audio sample. Piper throws; ElevenLabs calls /v1/voices/add.
     */
    public function cloneVoice(string $name, string $samplePath): string;
}
