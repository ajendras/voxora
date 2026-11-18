<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;
use InvalidArgumentException;

/**
 * OpenAIProvider
 *
 * Built-in provider for OpenAI TTS API (tts-1 and tts-1-hd models).
 * Supports the 6 standard voices, SSML by stripping tags (as the API is text-only),
 * and voice listing. Returns MP3 by default.
 */
final class OpenAIProvider implements ProviderInterface
{
    private Client $client;
    private string $model;

    /**
     * @param array<string, mixed> $config Must contain 'api_key'
     */
    public function __construct(array $config = [])
    {
        if (empty($config['api_key'])) {
            throw new InvalidArgumentException('OpenAI API key is required in config.');
        }

        $this->model = $config['model'] ?? 'tts-1';

        $this->client = new Client([
            'base_uri' => $config['base_uri'] ?? 'https://api.openai.com/v1/',
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public function synthesize(string $text, string $voiceId, array $options = []): SynthesisResult
    {
        if (trim($text) === '') {
            throw new InvalidArgumentException('Text cannot be empty.');
        }

        $cleanText = $this->stripSSML($text);

        if (strlen($cleanText) > 4096) {
            $cleanText = substr($cleanText, 0, 4096);
        }

        $payload = [
            'model' => $options['model'] ?? $this->model,
            'input' => $cleanText,
            'voice' => $voiceId,
        ];

        if (isset($options['speed'])) {
            $payload['speed'] = max(0.25, min(4.0, (float) $options['speed']));
        }

        $format = strtolower($options['format'] ?? 'mp3');
        if (!in_array($format, ['mp3', 'wav', 'opus'], true)) {
            $format = 'mp3';
        }
        $payload['response_format'] = $format;

        try {
            $response = $this->client->post('audio/speech', [
                'json' => $payload,
            ]);

            $audioContent = (string) $response->getBody();

            return new SynthesisResult(
                audioContent: $audioContent,
                format: $format,
                duration: null, // measured in SynthesisResult::process() or Manager
                metadata: [
                    'provider' => 'openai',
                    'model' => $payload['model'],
                    'voice' => $voiceId,
                    'ssml_stripped' => $text !== $cleanText,
                ]
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException('OpenAI TTS request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function listVoices(): array
    {
        return [
            [
                'id' => 'alloy',
                'name' => 'Alloy',
                'description' => 'OpenAI • Neutral and versatile',
            ],
            [
                'id' => 'echo',
                'name' => 'Echo',
                'description' => 'OpenAI • Clear and articulate',
            ],
            [
                'id' => 'fable',
                'name' => 'Fable',
                'description' => 'OpenAI • Narrative storyteller',
            ],
            [
                'id' => 'onyx',
                'name' => 'Onyx',
                'description' => 'OpenAI • Deep and authoritative',
            ],
            [
                'id' => 'nova',
                'name' => 'Nova',
                'description' => 'OpenAI • Energetic female',
            ],
            [
                'id' => 'shimmer',
                'name' => 'Shimmer',
                'description' => 'OpenAI • Bright and engaging',
            ],
        ];
    }

    public function cloneVoice(string $name, string $samplePath, ?int $userId = null): string
    {
        throw new RuntimeException('Voice cloning is not supported by the OpenAI TTS API.');
    }

    private function stripSSML(string $text): string
    {
        // Remove SSML tags but preserve the inner text content for compatibility
        // with the rich SSML editor in the dashboard.
        $text = preg_replace('/<\s*[^>]+>/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
