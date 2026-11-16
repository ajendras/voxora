<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * WebhookDispatcher
 *
 * Notifies external systems via HTTP POST when synthesis completes successfully.
 * Configured via config['webhook']['url']. Silently fails on network issues to
 * not block the main flow (fails-open design).
 */
final class WebhookDispatcher
{
    private ?Client $client = null;
    private ?string $webhookUrl;
    private ?LoggerInterface $logger;

    public function __construct(?string $webhookUrl = null, ?LoggerInterface $logger = null)
    {
        $this->webhookUrl = $webhookUrl;
        $this->logger = $logger;

        if ($this->webhookUrl !== null) {
            $this->client = new Client([
                'timeout' => 10,
                'http_version' => 2.0,
            ]);
        }
    }

    public function dispatchSuccess(SynthesisResult $result, array $context = []): void
    {
        if ($this->client === null || $this->webhookUrl === null) {
            return;
        }

        $payload = array_merge([
            'event' => 'synthesis.completed',
            'timestamp' => date('c'),
            'duration_seconds' => $result->duration,
            'format' => $result->format,
            'provider' => $result->metadata['provider'] ?? 'unknown',
            'text_preview' => substr($context['text'] ?? '', 0, 100),
        ], $context);

        try {
            $this->client->post($this->webhookUrl, [
                'json' => $payload,
                'headers' => [
                    'User-Agent' => 'Voxora-Webhook/1.0',
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger?->warning('Webhook delivery failed', [
                'url' => $this->webhookUrl,
                'error' => $e->getMessage(),
            ]);
            // fails open - does not throw
        }
    }
}
