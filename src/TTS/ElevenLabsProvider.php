<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use InvalidArgumentException;

/**
 * ElevenLabsProvider
 *
 * Official provider for ElevenLabs TTS with full SSML support (passes through),
 * dynamic voice listing from their /voices API, and voice cloning support.
 */
final class ElevenLabsProvider implements ProviderInterface
{
    private Client $client;
    private string $modelId;

    public function __construct(array $config = [])
    {
        if (empty($config['api_key'])) {
            throw new InvalidArgumentException('ElevenLabs API key is required.');
        }

        $this->modelId = $config['model'] ?? 'eleven_monolingual_v1';

        $this->client = new Client([
            'base_uri' => $config['base_uri'] ?? 'https://api.elevenlabs.io/v1/',
            'headers' => [
                'xi-api-key' => $config['api_key'],
                'Accept' => 'audio/mpeg',
            ],
            'timeout' => 60,
        ]);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        $format = $options['format'] ?? 'mp3';

        try {
            $response = $this->client->post("text-to-speech/{$voiceId}", [
                'json' => [
                    'text' => $text,  // SSML is supported by ElevenLabs, passed as-is
                    'model_id' => $options['model'] ?? $this->modelId,
                    'voice_settings' => $options['voice_settings'] ?? [
                        'stability' => 0.5,
                        'similarity_boost' => 0.75,
                    ],
                ],
                'query' => [
                    'output_format' => $format === 'wav' ? 'pcm_16000' : 'mp3_44100',
                ],
            ]);

            $audioContent = (string) $response->getBody();

            return new SynthesisResult(
                audioContent: $audioContent,
                format: $format,
                duration: null, // resolved by post-processing or header
                metadata: [
                    'provider' => 'elevenlabs',
                    'voice' => $voiceId,
                    'model' => $this->modelId,
                ]
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('ElevenLabs API error: ' . $e->getMessage(), 0, $e);
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
                    'description' => ($voice['category'] ?? 'premade') . ' • ' . ($voice['description'] ?? 'High quality'),
                ];
            }

            return $voices;
        } catch (\Throwable $e) {
            // Fallback to demo voices if API fails
            return [
                ['id' => '21m00Tcm4TlvDq8ikWAM', 'name' => 'Rachel', 'description' => 'American • Warm & versatile'],
                ['id' => 'AZnzlkDJ2myd1k8p7Q6X', 'name' => 'Domi', 'description' => 'American • Upbeat & clear'],
            ];
        }
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        if (!file_exists($samplePath)) {
            throw new InvalidArgumentException('Sample file does not exist.');
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
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return $data['voice_id'] ?? throw new RuntimeException('No voice_id in response');
        } catch (GuzzleException $e) {
            throw new RuntimeException('Voice cloning failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
