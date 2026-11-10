<?php

declare(strict_types=1);

/**
 * Voxora Batch Runner Example
 *
 * A self-contained CLI script demonstrating batch synthesis from a CSV file.
 * Uses the BatchGenerator together with a fully booted Kernel and TTS Manager.
 *
 * Usage:
 *   php examples/batch.php path/to/scripts.csv [custom-output-dir]
 *
 * CSV format (headers are case-insensitive):
 *   text,voice_id,provider,filename
 *
 * Example content:
 *   text,voice_id,provider,filename
 *   "Welcome to Voxora, the self-hosted TTS control plane.","21m00Tcm4TlvDq8ikWAM",elevenlabs,intro.mp3
 *   "Security and privacy matter for AI voice workloads.",AZnzlkDJ2myd1k8p7Q6X,,security.mp3
 */

namespace Voxora\Examples;

use Voxora\Automation\BatchGenerator;
use Voxora\Kernel;
use InvalidArgumentException;
use RuntimeException;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script must be executed from the command line.\n";
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

// Simple env() helper if config/app.php uses it and it is not already defined.
if (!function_exists('env')) {
    /**
     * Simple environment variable helper for the example.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }
}

if ($argc < 2) {
    echo "\033[36mVoxora Batch Runner\033[0m\n\n";
    echo "Usage:\n";
    echo "  php {$argv[0]} <csv-path> [output-directory]\n\n";
    echo "CSV columns (case-insensitive): text, voice_id (or voice), provider, filename\n\n";
    echo "Example CSV row:\n";
    echo "Hello from self-hosted AI TTS!,21m00Tcm4TlvDq8ikWAM,elevenlabs,welcome.mp3\n\n";
    echo "Set the ELEVENLABS_API_KEY environment variable before running.\n";
    exit(1);
}

$csvPath = $argv[1];
$outputDir = $argv[2] ?? dirname(__DIR__) . '/output/batch-' . date('Ymd-His');

if (!file_exists($csvPath) || !is_readable($csvPath)) {
    echo "\033[31mError: CSV file not found or not readable: {$csvPath}\033[0m\n";
    exit(1);
}

// Load configuration
$configPath = dirname(__DIR__) . '/config/app.php';
if (!file_exists($configPath)) {
    echo "\033[31mError: config/app.php not found. Please run from project root.\033[0m\n";
    exit(1);
}

$config = require $configPath;

// Ensure we have a real ElevenLabs key (config may contain placeholder)
$elevenKey = $config['providers']['eleven']['api_key'] ?? '';
if (empty($elevenKey) || str_starts_with($elevenKey, 'sk-...')) {
    $envKey = getenv('ELEVENLABS_API_KEY');
    if (empty($envKey)) {
        echo "\033[31mError: ELEVENLABS_API_KEY environment variable is required.\033[0m\n";
        echo "Export it before running this example:\n";
        echo "  export ELEVENLABS_API_KEY=your_real_key_here\n";
        exit(1);
    }
    $config['providers']['eleven']['api_key'] = $envKey;
}

// Also ensure storage and security keys are set
if (empty($config['security']['encrypt_key'] ?? '')) {
    $config['security']['encrypt_key'] = 'batch-example-32-byte-fallback-key-123';
}
if (empty($config['storage']['path'] ?? '')) {
    $config['storage']['path'] = dirname(__DIR__) . '/data/voxora.db';
}

echo "\033[36mVoxora Batch Runner\033[0m\n";
echo "CSV : {$csvPath}\n";
echo "Out : {$outputDir}\n\n";

$kernel = new Kernel($config);

try {
    $kernel->boot();
} catch (RuntimeException $e) {
    echo "\033[31mBootstrap error: {$e->getMessage()}\033[0m\n";
    exit(1);
}

$manager = $kernel->getTtsManager();
$batchGenerator = new BatchGenerator($manager, $outputDir);

$progress = static function (
    int $current,
    int $total,
    string $message,
    ?string $filePath = null
): void {
    if (str_contains($message, '✓') || str_contains($message, 'Saved')) {
        $color = "\033[32m";
        $icon = '✓';
    } elseif (str_contains($message, '✗') || str_contains($message, 'Skipped')) {
        $color = "\033[31m";
        $icon = '✗';
    } else {
        $color = "\033[34m";
        $icon = '→';
    }

    echo " {$color}{$icon}\033[0m {$message}\n";

    if ($filePath !== null) {
        echo "    \033[2m→ {$filePath}\033[0m\n";
    }
};

try {
    $report = $batchGenerator->run($csvPath, $progress);

    echo "\n\033[32mBatch completed successfully!\033[0m\n";
    echo $report->getSummary() . "\n\n";

    echo "\033[2mFull report:\033[0m\n";
    echo json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n";

    echo "\033[36mMP3 files have been written to:\033[0m {$outputDir}\n";
    echo "You can now integrate these files into your application or player.\n";
} catch (InvalidArgumentException | RuntimeException $e) {
    echo "\n\033[31mBatch failed: {$e->getMessage()}\033[0m\n";
    exit(1);
} catch (\Throwable $e) {
    echo "\n\033[31mUnexpected error: {$e->getMessage()}\033[0m\n";
    exit(1);
}
