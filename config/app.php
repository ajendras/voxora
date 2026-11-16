<?php

// Configuration for Voxora.
// Copy this file to config/app.php and fill in your credentials.

return [
    'security' => [
        'encrypt_key' => 'your-32-char-secret-key-here-at-least-32bytes!!',
        'token_lifetime_days' => 90,
    ],
    'storage' => [
        'path' => __DIR__ . '/../data/voxora.db',
    ],
    'providers' => [
        'elevenlabs' => [
            'api_key' => 'your_elevenlabs_api_key_here',
            'base_uri' => 'https://api.elevenlabs.io/v1',
        ],
        'piper' => [
            'binary' => 'piper',
            'model' => __DIR__ . '/../models/en_US-lessac-medium.onnx',
        ],
        // Plugin system example - register your own provider at runtime:
        // 'openai' => [
        //     'class' => 'MyApp\\OpenAITTSProvider',
        //     'api_key' => 'sk-...',
        //     'model' => 'tts-1-hd',
        // ],
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
    'webhooks' => [
        // 'url' => 'https://example.com/webhook',
        // 'events' => ['generation.success'],
    ],
];
