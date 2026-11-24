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
 * Uses ElevenLabs API for high-quality neural TTS. Now fully supports
 * provider-specific parameters (stability, similarity, style, speed) that
 * are normalized by TTS\Manager before reaching synthesize().
 */
final class ElevenLabsProvider implements ProviderInterface
{
    private Client $client;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $apiKey = $config['api_key'] ?? '';
        if (empty($apiKey)) {
            throw new InvalidArgumentException('ElevenLabs API key is required.');
        }

        $this->client = new Client([
            'base_uri' => $config['base_uri'] ?? 'https://api.elevenlabs.io/v1/',
            'headers' => [
                'xi-api-key' => $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $format = strtolower($options['format'] ?? 'mp3');
        $modelId = $options['model'] ?? 'eleven_monolingual_v1';

        $stability = $options['stability'] ?? 0.5;
        $similarityBoost = $options['similarity_boost'] ?? $options['similarity'] ?? 0.75;
        $style = $options['style'] ?? 0.0;
        $speed = $options['speed'] ?? 1.0;

        $voiceSettings = [
            'stability' => $stability,
            'similarity_boost' => $similarityBoost,
            'style' => $style,
            'speed' => $speed,
        ];

        try {
            $queryParams = [
                'output_format' => $format === 'wav' ? 'pcm_16000' : 'mp3_44100_128',
            ];

            $response = $this->client->request('POST', "text-to-speech/{$voiceId}", [
                'json' => [
                    'text' => $text,
                    'model_id' => $modelId,
                    'voice_settings' => $voiceSettings,
                ],
                'query' => $queryParams,
            ]);

            $audioContent = (string) $response->getBody();
            $duration = $this->estimateDuration($audioContent, $format);

            return new SynthesisResult($audioContent, $format, $duration);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                'ElevenLabs API request failed: ' . $e->getMessage(),
                0,
                $e
            );
        } catch (\JsonException $e) {
            throw new RuntimeException('Failed to parse ElevenLabs response', 0, $e);
        }
    }

    public function listVoices(): array
    {
        try {
            $response = $this->client->get('voices');
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            $voices = [];
            foreach ($data['voices'] ?? [] as $voice) {
                $voices[] = [
                    'id' => $voice['voice_id'],
                    'name' => $voice['name'],
                    'language' => $voice['language'] ?? 'en',
                    'gender' => $voice['gender'] ?? 'unknown',
                    'description' => $voice['description'] ?? '',
                    'is_cloned' => ($voice['category'] ?? '') === 'cloned',
                ];
            }
            return $voices;
        } catch (\Throwable $e) {
            return [
                [
                    'id' => '21m00Tcm4TlvDq8ikWAM',
                    'name' => 'Rachel',
                    'language' => 'en',
                    'description' => 'Default voice (fallback)',
                ],
                [
                    'id' => 'AZnzlk1XvdvUeBnXmlld',
                    'name' => 'Domi',
                    'language' => 'en',
                    'description' => 'Default voice (fallback)',
                ],
            ];
        }
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        if (!file_exists($samplePath)) {
            throw new RuntimeException("Sample file not found: {$samplePath}");
        }

        try {
            $response = $this->client->post('voices/add', [
                'multipart' => [
                    [
                        'name' => 'name',
                        'contents' => $name,
                    ],
                    [
                        'name' => 'files',
                        'contents' => fopen($samplePath, 'r'),
                        'filename' => basename($samplePath),
                    ],
                    [
                        'name' => 'description',
                        'contents' => $options['description'] ?? 'Cloned via Voxora',
                    ],
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return $data['voice_id'] ?? throw new RuntimeException('No voice_id returned from API');
        } catch (GuzzleException $e) {
            throw new RuntimeException('Voice cloning failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function estimateDuration(string $audio, string $format): float
    {
        if (str_contains($format, 'wav') || str_contains($format, 'pcm')) {
            return strlen($audio) / (16000 * 2);
        }
        return strlen($audio) / 32000;
    }
}
