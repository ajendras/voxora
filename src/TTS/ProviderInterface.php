<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\TTS\SynthesisResult;
use RuntimeException;

/**
 * ProviderInterface
 *
 * Contract that every TTS backend (built-in or custom) must implement.
 * The dedicated guide in README.md ("Implementing and registering custom TTS providers")
 * contains a full walk-through, a complete working example, registration patterns
 * (runtime + config-driven), best practices and integration notes.
 *
 * Implementing this interface automatically makes the provider available in:
 *  - Dashboard dropdown and voice selector
 *  - REST API (provider parameter)
 *  - BatchGenerator (CSV provider column)
 *  - Scheduler jobs
 *  - Manager::listVoices() and analytics
 *  - Caching, FFmpeg post-processing, webhooks and Piper fallback (all handled by Manager)
 */
interface ProviderInterface
{
    /**
     * Synthesize speech from text (or SSML).
     *
     * The Manager will call this method only on cache miss. It automatically:
     *  - records usage stats
     *  - stores the result in SQLite
     *  - applies FFmpeg normalization/conversion when options request it
     *  - dispatches webhooks
     *
     * @param string $text Text or SSML fragment. Providers that do not support SSML should strip tags.
     * @param string $voiceId Provider-specific voice identifier.
     * @param array<string, mixed> $options Optional settings (format, model, speed, normalize, target_loudness, ...).
     *        The key 'format' should be respected ('mp3' or 'wav').
     * @return SynthesisResult Binary audio content, format and duration (seconds).
     * @throws RuntimeException On any failure (network error, invalid voice, quota, etc.).
     *         The Manager will fallback to Piper when configured.
     */
    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult;

    /**
     * Return the list of voices this provider supports.
     *
     * Used by the dashboard, API endpoint /api/v1/voices, Manager::listVoices()
     * and analytics breakdown. The array shape must be consistent across providers.
     *
     * @return array<int, array<string, string>> Each voice must contain at least:
     *         'id' (string), 'name' (string). Recommended extra keys: 'language', 'gender', 'description'.
     */
    public function listVoices(): array;

    /**
     * Create a cloned voice from an audio sample.
     *
     * The Manager persists the returned voice_id in the voices table (is_cloned = 1)
     * so it becomes immediately selectable. Providers that do not support cloning
     * (e.g. Piper) must throw an exception with a clear message.
     *
     * @param string $name Human-readable name for the new voice.
     * @param string $samplePath Absolute path to a WAV or MP3 sample file.
     * @param array<string, mixed> $options Extra options (description, stability, similarity, ...).
     * @return string The provider-assigned voice identifier.
     * @throws RuntimeException When cloning is unsupported or the operation fails.
     */
    public function cloneVoice(string $name, string $samplePath, array $options = []): string;
}
