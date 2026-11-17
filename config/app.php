<?php

declare(strict_types=1);

return [
    'security' => [
        'encrypt_key' => 'change-this-to-a-32-byte-random-hex-string-in-production',
        'token_lifetime_days' => 90,
    ],
    'storage' => [
        'path' => __DIR__ . '/../data/voxora.db',
    ],
    'providers' => [
        'eleven' => [
            'api_key' => getenv('ELEVENLABS_API_KEY') ?: 'demo-key-replace-in-production',
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
    'rate_limit' => [
        'requests' => 100,
        'window' => 60,
    ],
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => null,
    ],
    'scheduler' => [
        'log' => __DIR__ . '/../data/scheduler.log',
    ],
    'ui' => [
        'default_theme' => 'system',
    ],
    'webhooks' => [
        'enabled' => false,
        'url' => null,
        'secret' => null,
    ],
];
