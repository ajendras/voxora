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
 * Uses ElevenLabs API for high-quality neural TTS and voice cloning.
 */
final class ElevenLabsProvider implements ProviderInterface
{
    private Client $client;
    private string $apiKey;

    public function __construct(string $apiKey, string $baseUri = 'https://api.elevenlabs.io/v1')
    {
        if (empty($apiKey) || str_starts_with($apiKey, 'sk-...')) {
            throw new InvalidArgumentException('Valid ElevenLabs API key is required.');
        }
        $this->apiKey = $apiKey;
        $this->client = new Client([
            'base_uri' => $baseUri,
            'headers' => [
                'xi-api-key' => $apiKey,
                'Accept' => 'audio/mpeg',
            ],
            'timeout' => 30,
        ]);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        $payload = [
            'text' => $text,
            'model_id' => 'eleven_monolingual_v1',
            'voice_settings' => [
                'stability' => $options['stability'] ?? 0.5,
                'similarity_boost' => $options['similarity_boost'] ?? 0.75,
            ],
        ];

        try {
            $response = $this->client->post("/text-to-speech/{$voiceId}", [
                'json' => $payload,
            ]);

            $audioContent = (string) $response->getBody();

            $estimatedDuration = max(1.0, strlen($audioContent) / 32000);

            return new SynthesisResult(
                audioContent: $audioContent,
                format: 'mp3',
                duration: $estimatedDuration,
                metadata: [
                    'provider' => 'elevenlabs',
                    'voice_id' => $voiceId,
                    'character_count' => strlen($text),
                ]
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('ElevenLabs API error: ' . $e->getMessage(), 0, $e);
        }
    }

    public function cloneVoice(string $name, string $samplePath): string
    {
        if (!file_exists($samplePath) || !is_readable($samplePath)) {
            throw new InvalidArgumentException("Sample file not found or not readable: {$samplePath}");
        }

        $mimeType = mime_content_type($samplePath);
        if (!in_array($mimeType, ['audio/mpeg', 'audio/wav', 'audio/x-wav'], true)) {
            throw new InvalidArgumentException('Sample must be MP3 or WAV audio file.');
        }

        try {
            $response = $this->client->post('/v1/voices/add', [
                'multipart' => [
                    [
                        'name' => 'name',
                        'contents' => $name,
                    ],
                    [
                        'name' => 'files',
                        'contents' => fopen($samplePath, 'r'),
                        'filename' => basename($samplePath),
                        'headers' => [
                            'Content-Type' => $mimeType,
                        ],
                    ],
                    [
                        'name' => 'description',
                        'contents' => 'Cloned via Voxora',
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (empty($body['voice_id'])) {
                throw new RuntimeException('No voice_id returned from cloning API');
            }

            return $body['voice_id'];
        } catch (GuzzleException $e) {
            throw new RuntimeException('Voice cloning failed: ' . $e->getMessage(), 0, $e);
        } catch (\JsonException $e) {
            throw new RuntimeException('Invalid response from cloning API');
        }
    }

    public function getName(): string
    {
        return 'elevenlabs';
    }
}
