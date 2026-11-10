<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use RuntimeException;

/**
 * ElevenLabsProvider
 *
 * Concrete implementation of ProviderInterface for the ElevenLabs TTS API.
 * Constructs authenticated requests to the text-to-speech endpoint, maps
 * stability/similarity_boost settings, and returns a standardized SynthesisResult.
 */
final class ElevenLabsProvider implements ProviderInterface
{
    private Client $client;
    private string $apiKey;

    /**
     * @param string $apiKey ElevenLabs API key (must be valid, not placeholder)
     * @param string $baseUri Base URI for the API (allows overrides for testing)
     */
    public function __construct(string $apiKey, string $baseUri = 'https://api.elevenlabs.io/v1')
    {
        if (empty($apiKey) || str_starts_with($apiKey, 'sk-...')) {
            throw new InvalidArgumentException('A valid ElevenLabs API key must be provided.');
        }

        $this->apiKey = $apiKey;

        $this->client = new Client([
            'base_uri' => rtrim($baseUri, '/') . '/',
            'timeout' => 30.0,
            'http_errors' => false,
            'headers' => [
                'xi-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'audio/mpeg',
                'User-Agent' => 'Voxora/1.0',
            ],
        ]);
    }

    public function synthesize(string $text, array $voice, array $options = []): SynthesisResult
    {
        $text = trim($text);
        if ($text === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        $voiceId = trim($voice['id'] ?? '');
        if ($voiceId === '') {
            throw new InvalidArgumentException('Voice descriptor must contain a valid "id".');
        }

        $model = $options['model'] ?? 'eleven_monolingual_v1';
        $stability = max(0.0, min(1.0, (float) ($options['stability'] ?? 0.5)));
        $similarityBoost = max(0.0, min(1.0, (float) ($options['similarity_boost'] ?? 0.75)));

        $payload = [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => [
                'stability' => $stability,
                'similarity_boost' => $similarityBoost,
                'style' => 0.0,
                'use_speaker_boost' => true,
            ],
        ];

        try {
            $response = $this->client->post("text-to-speech/{$voiceId}", [
                'json' => $payload,
            ]);

            if ($response->getStatusCode() !== 200) {
                $body = (string) $response->getBody();
                throw new RuntimeException("ElevenLabs API error ({$response->getStatusCode()}): {$body}");
            }

            $audioContent = (string) $response->getBody();
            if ($audioContent === '') {
                throw new RuntimeException('Empty audio response received from ElevenLabs.');
            }

            return new SynthesisResult(
                $audioContent,
                'mp3',
                null,
                [
                    'provider' => 'elevenlabs',
                    'voice_id' => $voiceId,
                    'model' => $model,
                    'stability' => $stability,
                    'similarity_boost' => $similarityBoost,
                    'character_count' => mb_strlen($text),
                ]
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('HTTP request to ElevenLabs failed: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($e instanceof RuntimeException) {
                throw $e;
            }
            throw new RuntimeException('Failed to synthesize with ElevenLabs: ' . $e->getMessage(), 0, $e);
        }
    }
}
