<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use RuntimeException;

/**
 * OpenAIProvider
 *
 * Official OpenAI TTS support. Automatically strips SSML and now respects
 * the 'speed' parameter passed through TTS\Manager::normalizeOptions().
 */
final class OpenAIProvider implements ProviderInterface
{
    private Client $client;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $apiKey = $config['api_key'] ?? '';
        if (empty($apiKey)) {
            throw new InvalidArgumentException('OpenAI API key is required in config.');
        }

        $this->client = new Client([
            'base_uri' => $config['base_uri'] ?? 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        $cleanText = $this->stripSsml($text);
        $model = $options['model'] ?? $this->config['model'] ?? 'tts-1';
        $speed = $options['speed'] ?? 1.0;
        $format = strtolower($options['format'] ?? 'mp3');

        try {
            $payload = [
                'model' => $model,
                'input' => $cleanText,
                'voice' => $voiceId,
                'speed' => $speed,
            ];

            $accept = $format === 'wav' ? 'audio/wav' : 'audio/mpeg';

            $response = $this->client->post('audio/speech', [
                'json' => $payload,
                'headers' => [
                    'Accept' => $accept,
                ],
            ]);

            $audio = (string) $response->getBody();
            $duration = $this->estimateDuration($audio, $format);

            return new SynthesisResult($audio, $format, $duration);
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI TTS failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function stripSsml(string $text): string
    {
        return preg_replace('/<[^>]*>/', '', $text) ?? $text;
    }

    public function listVoices(): array
    {
        return [
            ['id' => 'alloy', 'name' => 'Alloy', 'language' => 'en', 'gender' => 'neutral'],
            ['id' => 'echo', 'name' => 'Echo', 'language' => 'en', 'gender' => 'male'],
            ['id' => 'fable', 'name' => 'Fable', 'language' => 'en', 'gender' => 'male'],
            ['id' => 'onyx', 'name' => 'Onyx', 'language' => 'en', 'gender' => 'male'],
            ['id' => 'nova', 'name' => 'Nova', 'language' => 'en', 'gender' => 'female'],
            ['id' => 'shimmer', 'name' => 'Shimmer', 'language' => 'en', 'gender' => 'female'],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, array $options = []): string
    {
        throw new RuntimeException('Voice cloning is not supported by OpenAIProvider. Use ElevenLabs.');
    }

    private function estimateDuration(string $audioContent, string $format): float
    {
        $bytesPerSecond = ($format === 'mp3') ? 16000 : 32000;
        return strlen($audioContent) / $bytesPerSecond;
    }
}
