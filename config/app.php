<?php

declare(strict_types=1);

/**
 * Voxora Application Configuration
 *
 * Copy this file (or the example) and customize the values for your environment.
 * All TTS, security, storage, audio, FFmpeg and webhook settings live here.
 */

return [
    'security' => [
        'encrypt_key' => 'change-this-to-a-long-random-secret-min-32-chars!!',
        'token_lifetime_days' => 90,
    ],
    'storage' => [
        'path' => __DIR__ . '/../data/voxora.db',
    ],
    'providers' => [
        'eleven' => [
            'api_key' => 'sk-...',
            'base_uri' => 'https://api.elevenlabs.io/v1',
        ],
        'piper' => [
            'binary' => 'piper',
            'model' => __DIR__ . '/../models/en_US-lessac-medium.onnx',
        ],
    ],
    'audio' => [
        'directory' => __DIR__ . '/../storage/audio',
        'public_url_prefix' => '/audio',
    ],
    'ffmpeg' => [
        'binary' => 'ffmpeg',
        'normalize' => true,
        'target_loudness' => -16.0,
    ],
    'webhooks' => [
        'enabled' => false,
        'urls' => [
            // 'https://your-service.example.com/webhook/voxora',
        ],
        'timeout' => 5,
        'secret' => null, // HMAC-SHA256 signing secret (optional but recommended)
    ],
];
