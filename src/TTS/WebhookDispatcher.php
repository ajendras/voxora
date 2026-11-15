<?php

declare(strict_types=1);

namespace Voxora\TTS;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use DateTimeImmutable;

/**
 * WebhookDispatcher
 *
 * Handles firing configured webhooks on successful TTS generation.
 * Best-effort delivery with configurable timeout, multiple endpoints,
 * JSON payload and optional HMAC-SHA256 signature (X-Voxora-Signature header).
 */
final class WebhookDispatcher
{
    private array $config;
    private Client $client;

    /**
     * @param array $config Webhook configuration from config/app.php
     * @param Client|null $client Optional Guzzle client (for testing)
     */
    public function __construct(array $config = [], ?Client $client = null)
    {
        $this->config = $config;
        $this->client = $client ?? new Client([
            'timeout' => (int) ($config['timeout'] ?? 5),
            'http_errors' => false,
        ]);
    }

    /**
     * Dispatch a "generation.success" event.
     *
     * @param SynthesisResult $result
     * @param array $context Extra context (text, voice_id, audio_url, user_id, ...)
     */
    public function dispatchSuccess(SynthesisResult $result, array $context = []): void
    {
        if (!($this->config['enabled'] ?? false)) {
            return;
        }

        $urls = array_filter((array) ($this->config['urls'] ?? []));
        if ($urls === []) {
            return;
        }

        $payload = array_merge([
            'event' => 'generation.success',
            'occurred_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'text' => $context['text'] ?? '',
            'voice_id' => $context['voice_id'] ?? '',
            'provider' => $context['provider'] ?? 'unknown',
            'duration_seconds' => $result->duration ?? null,
            'format' => $result->format ?? 'mp3',
            'audio_url' => $context['audio_url'] ?? null,
            'user_id' => $context['user_id'] ?? null,
            'metadata' => $result->metadata ?? [],
            'batch' => $context['batch'] ?? false,
        ], $context['extra'] ?? []);

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret = $this->config['secret'] ?? null;
        $signature = $secret ? hash_hmac('sha256', $body, (string) $secret) : null;

        foreach ($urls as $url) {
            if (!is_string($url) || trim($url) === '' || !str_starts_with(trim($url), 'http')) {
                continue;
            }

            try {
                $headers = [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Voxora/1.0',
                ];

                if ($signature !== null) {
                    $headers['X-Voxora-Signature'] = $signature;
                }

                $this->client->request('POST', $url, [
                    'headers' => $headers,
                    'body' => $body,
                ]);
            } catch (GuzzleException | \Throwable $e) {
                error_log(sprintf(
                    'Voxora webhook delivery to %s failed: %s',
                    $url,
                    $e->getMessage()
                ));
                // Best-effort only — never fail the original generation
            }
        }
    }
}
