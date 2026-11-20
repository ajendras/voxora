<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use RuntimeException;

/**
 * WebhookDispatcher
 *
 * Dispatches success notifications to a configured webhook URL.
 * Used by Manager, ApiController, BatchGenerator and PiperProvider.
 * Fails open on any error (network, config, JSON, etc).
 */
class WebhookDispatcher
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * Notify external system of a successful synthesis.
     */
    public function dispatchSuccess(SynthesisResult $result, array $context = []): void
    {
        $url = $this->config['webhook_url'] ?? $this->config['webhook']['url'] ?? null;
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return;
        }

        $payload = [
            'event' => 'synthesis.success',
            'timestamp' => (new \DateTimeImmutable())->format('c'),
            'result' => [
                'duration' => $result->duration ?? null,
                'format' => $result->format ?? 'mp3',
                'metadata' => $result->metadata ?? [],
            ],
            'context' => array_merge([
                'text_preview' => substr($context['text'] ?? '', 0, 80),
                'provider' => $context['provider'] ?? 'unknown',
                'voice_id' => $context['voice_id'] ?? '',
                'user_id' => $context['user_id'] ?? null,
                'batch' => $context['batch'] ?? false,
                'scheduled' => $context['scheduled'] ?? false,
            ], $context),
        ];

        try {
            $client = new Client([
                'timeout' => 5,
                'connect_timeout' => 2,
            ]);

            $client->post($url, [
                'json' => $payload,
                'headers' => [
                    'User-Agent' => 'Voxora-Webhook/1.0',
                ],
            ]);
        } catch (\Throwable $e) {
            // fail open - never throw from a webhook
        }
    }
}
