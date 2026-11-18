<?php

declare(strict_types=1);

namespace Voxora\TTS;

/**
 * ProviderInterface
 *
 * All TTS providers (ElevenLabs, Piper, OpenAI, and plugins) must implement
 * this contract. Added listVoices() as part of the OpenAI built-in support.
 */
interface ProviderInterface
{
    /**
     * Generate speech from text. The text may contain SSML markup.
     * Providers that do not support SSML should strip tags gracefully.
     */
    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult;

    /**
     * Return available voices. Used by Manager::listVoices(), dashboard
     * and API for dynamic voice selection per provider.
     *
     * @return list<array{id: string, name: string, description: string}>
     */
    public function listVoices(): array;

    /**
     * Clone a voice from an audio sample. Not all providers support this.
     */
    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string;
}
