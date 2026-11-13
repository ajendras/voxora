<?php

declare(strict_types=1);

namespace Voxora\TTS;

use Voxora\Storage\SqliteRepository;
use InvalidArgumentException;
use RuntimeException;
use DateTimeImmutable;

/**
 * Manager
 *
 * Central orchestrator for TTS synthesis. Routes to the correct provider,
 * performs Piper fallback on error, records usage and now automatically
 * applies FFmpeg post-processing (normalization, loudness, format conversion)
 * via SynthesisResult::process().
 */
final class Manager
{
    private SqliteRepository $repository;

    /** @var array<string, ProviderInterface> */
    private array $providerMap = [];

    public function __construct(SqliteRepository $repository, ProviderInterface ...$providers)
    {
        $this->repository = $repository;

        foreach ($providers as $provider) {
            if ($provider instanceof ElevenLabsProvider) {
                $this->providerMap['elevenlabs'] = $provider;
                $this->providerMap['eleven'] = $provider;
            } elseif ($provider instanceof PiperProvider) {
                $this->providerMap['piper'] = $provider;
            } else {
                $this->providerMap['default'] = $provider;
            }
        }
    }

    public function generate(string $text, string $voiceId, ?string $provider = null, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        if (trim($voiceId) === '') {
            throw new InvalidArgumentException('voiceId cannot be empty.');
        }

        $providerName = strtolower(trim($provider ?? 'elevenlabs'));

        $selectedProvider = $this->providerMap[$providerName] ?? reset($this->providerMap);
        if ($selectedProvider === null) {
            throw new RuntimeException('No TTS provider is configured.');
        }

        try {
            $result = $selectedProvider->synthesize($text, $voiceId);
        } catch (RuntimeException $e) {
            // Transparent fallback to local Piper when cloud provider fails
            if ($providerName !== 'piper' && isset($this->providerMap['piper'])) {
                $result = $this->providerMap['piper']->synthesize($text, $voiceId);
            } else {
                throw $e;
            }
        }

        // Apply FFmpeg normalization, loudness control and/or format conversion.
        // Defaults to normalization + MP3 output.
        return $result->process($options);
    }

    public function cloneVoice(string $name, string $samplePath, string $provider = 'elevenlabs', ?int $userId = null): string
    {
        $providerName = strtolower($provider);
        $selectedProvider = $this->providerMap[$providerName] ?? null;

        if ($selectedProvider === null) {
            throw new InvalidArgumentException("Provider '{$provider}' is not available.");
        }

        if ($selectedProvider instanceof PiperProvider) {
            throw new RuntimeException('Voice cloning is not supported by the Piper provider.');
        }

        $voiceId = $selectedProvider->cloneVoice($name, $samplePath);

        $this->repository->saveCustomVoice(
            $name,
            $voiceId,
            $providerName,
            $userId ?? 1
        );

        return $voiceId;
    }
}
