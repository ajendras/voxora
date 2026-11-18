<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Http\ApiController;
use Voxora\Http\DashboardController;
use Voxora\Security\KeyManager;
use Voxora\Security\RateLimiter;
use Voxora\Storage\SqliteRepository;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\OpenAIProvider;
use Voxora\TTS\Manager;
use Voxora\TTS\ProviderInterface;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Automation\Scheduler;
use Predis\Client as RedisClient;
use RuntimeException;

/**
 * Kernel
 *
 * Bootstraps the entire application, wires all services together,
 * registers built-in TTS providers (including the new OpenAI one),
 * sets up SQLite schema, and provides access to core services.
 */
final class Kernel
{
    private array $config;
    private ?Manager $ttsManager = null;
    private ?KeyManager $keyManager = null;
    private ?SqliteRepository $repository = null;
    private ?RateLimiter $rateLimiter = null;
    private ?WebhookDispatcher $webhookDispatcher = null;
    private ?Scheduler $scheduler = null;
    private bool $booted = false;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->repository = new SqliteRepository($this->config['storage']['path'] ?? 'data/voxora.db');
        $this->repository->migrate();

        $this->keyManager = new KeyManager(
            $this->config['security']['encrypt_key'] ?? throw new RuntimeException('encrypt_key required'),
            $this->repository
        );

        $redis = $this->createRedisClient();

        $this->rateLimiter = new RateLimiter(
            $redis,
            $this->config['rate_limit'] ?? ['requests' => 100, 'window' => 60]
        );

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->config['webhooks'] ?? []
        );

        $this->ttsManager = new Manager(
            $this->repository,
            $this->config,
            $this->webhookDispatcher
        );

        $this->registerBuiltInProviders();

        $this->scheduler = new Scheduler(
            $this->repository,
            $this->ttsManager,
            $this->webhookDispatcher,
            $this->config['scheduler'] ?? []
        );

        $this->booted = true;

        return $this;
    }

    private function registerBuiltInProviders(): void
    {
        if (!empty($this->config['providers']['eleven']['api_key'] ?? '')) {
            $elevenConfig = $this->config['providers']['eleven'];
            $this->ttsManager->registerProvider(
                'elevenlabs',
                new ElevenLabsProvider($elevenConfig)
            );
        }

        if (!empty($this->config['providers']['piper']['model'] ?? '')) {
            $piperConfig = $this->config['providers']['piper'];
            $this->ttsManager->registerProvider(
                'piper',
                new PiperProvider($piperConfig)
            );
        }

        if (!empty($this->config['providers']['openai']['api_key'] ?? '')) {
            $openaiConfig = $this->config['providers']['openai'];
            $this->ttsManager->registerProvider(
                'openai',
                new OpenAIProvider($openaiConfig)
            );
        }

        foreach ($this->config['providers'] ?? [] as $name => $provConfig) {
            if (isset($provConfig['class']) && class_exists($provConfig['class'])) {
                $provider = new $provConfig['class']($provConfig);
                if ($provider instanceof ProviderInterface) {
                    $this->ttsManager->registerProvider($name, $provider);
                }
            }
        }
    }

    private function createRedisClient(): ?RedisClient
    {
        if (empty($this->config['redis']['host'])) {
            return null;
        }

        try {
            return new RedisClient([
                'host' => $this->config['redis']['host'],
                'port' => $this->config['redis']['port'] ?? 6379,
                'password' => $this->config['redis']['password'] ?? null,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getTtsManager(): Manager
    {
        if (!$this->booted || $this->ttsManager === null) {
            throw new RuntimeException('Kernel not booted');
        }

        return $this->ttsManager;
    }

    public function getKeyManager(): KeyManager
    {
        if ($this->keyManager === null) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->keyManager;
    }

    public function getRepository(): SqliteRepository
    {
        if ($this->repository === null) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->repository;
    }

    public function getRateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->rateLimiter;
    }

    public function getScheduler(): Scheduler
    {
        if ($this->scheduler === null) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->scheduler;
    }

    public function handle(Request $request): Response
    {
        // Front-controller routing would be implemented here or in public/index.php.
        // Omitted for brevity; controllers receive services via constructor.
        throw new RuntimeException('HTTP handling not implemented in this skeleton.');
    }
}
