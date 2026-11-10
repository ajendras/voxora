<?php

declare(strict_types=1);

/**
 * Voxora Application Configuration
 *
 * This file returns the main configuration array. In production you should
 * override sensitive values via environment variables.
 */

return [
    'name' => 'Voxora',
    'version' => '1.0.0',
    'base_url' => env('APP_URL', 'http://localhost:8080'),
    'debug' => env('APP_DEBUG', true),

    'providers' => [
        'eleven' => [
            'api_key' => env('ELEVENLABS_API_KEY', 'sk-...'),
            'base_uri' => 'https://api.elevenlabs.io/v1',
        ],
        'openai' => [
            'api_key' => env('OPENAI_API_KEY', ''),
        ],
        'azure' => [
            'api_key' => env('AZURE_TTS_API_KEY', ''),
            'region' => env('AZURE_TTS_REGION', 'eastus'),
        ],
    ],

    'security' => [
        'token_lifetime_days' => 90,
        'encrypt_key' => env('ENCRYPT_KEY', '32-byte-secret-for-sodium-123456'),
        'allowed_origins' => ['*'],
    ],

    'storage' => [
        'driver' => 'sqlite',
        'path' => __DIR__ . '/../data/voxora.db',
    ],

    'audio' => [
        'directory' => __DIR__ . '/../storage/audio',
        'public_url_prefix' => '/audio',
    ],

    'webhooks' => [
        'enabled' => false,
        'default_url' => '',
    ],

    'analytics' => [
        'retention_days' => 90,
    ],
];

/**
 * Simple env helper used by the config.
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return match (strtolower($value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}
