<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Http\DashboardController;
use Voxora\Http\ApiController;
use Voxora\Security\KeyManager;
use Voxora\TTS\Manager;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Storage\SqliteRepository;
use Voxora\Security\RateLimiter;
use Predis\Client as RedisClient;
use RuntimeException;

/**
 * Kernel
 *
 * Bootstraps the entire application, wires services together,
 * registers built-in providers, runs schema migrations and
 * provides getters for controllers and core services.
 * Now also loads UI configuration for theming.
 */
final class Kernel
{
    private array $config;
    private ?SqliteRepository $repository = null;
    private ?KeyManager $keyManager = null;
    private ?Manager $ttsManager = null;
    private ?WebhookDispatcher $webhookDispatcher = null;
    private ?RateLimiter $rateLimiter = null;
    private ?RedisClient $redis = null;
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

        $this->ensureDirectoriesExist();
        $this->repository = new SqliteRepository($this->config['storage']['path']);
        $this->repository->migrate();

        $this->redis = new RedisClient([
            'host' => $this->config['redis']['host'],
            'port' => $this->config['redis']['port'],
        ]);

        if (!empty($this->config['redis']['password'])) {
            $this->redis->auth($this->config['redis']['password']);
        }

        $this->keyManager = new KeyManager(
            $this->config['security']['encrypt_key'],
            $this->config['security']['token_lifetime_days'],
            $this->repository
        );

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->config['webhooks'] ?? []
        );

        $this->rateLimiter = new RateLimiter(
            $this->redis,
            $this->config['rate_limit']['requests'],
            $this->config['rate_limit']['window']
        );

        $this->ttsManager = new Manager(
            $this->repository,
            $this->webhookDispatcher,
            $this->config
        );

        // Register built-in providers
        $elevenConfig = $this->config['providers']['eleven'] ?? [];
        if (!empty($elevenConfig['api_key'])) {
            $this->ttsManager->registerProvider('elevenlabs', new ElevenLabsProvider($elevenConfig));
        }

        $piperConfig = $this->config['providers']['piper'] ?? [];
        if (!empty($piperConfig['model'])) {
            $this->ttsManager->registerProvider('piper', new PiperProvider($piperConfig));
        }

        // Auto-register any custom providers defined in config
        foreach ($this->config['providers'] as $name => $providerConfig) {
            if (isset($providerConfig['class']) && class_exists($providerConfig['class'])) {
                $instance = new $providerConfig['class']($providerConfig);
                $this->ttsManager->registerProvider($name, $instance);
            }
        }

        $this->booted = true;
        return $this;
    }

    private function ensureDirectoriesExist(): void
    {
        $dirs = [
            __DIR__ . '/../data',
            __DIR__ . '/../storage/audio',
            __DIR__ . '/../output',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Failed to create directory: {$dir}");
            }
        }
    }

    public function getTtsManager(): Manager
    {
        if (!$this->ttsManager) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->ttsManager;
    }

    public function getKeyManager(): KeyManager
    {
        if (!$this->keyManager) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->keyManager;
    }

    public function getRepository(): SqliteRepository
    {
        if (!$this->repository) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->repository;
    }

    public function getWebhookDispatcher(): WebhookDispatcher
    {
        if (!$this->webhookDispatcher) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->webhookDispatcher;
    }

    public function getRateLimiter(): RateLimiter
    {
        if (!$this->rateLimiter) {
            throw new RuntimeException('Kernel not booted');
        }
        return $this->rateLimiter;
    }

    public function getDashboardController(): DashboardController
    {
        if (!$this->booted) {
            $this->boot();
        }

        return new DashboardController(
            $this->getTtsManager(),
            $this->getRepository(),
            $this->config,
            $this->getWebhookDispatcher()
        );
    }

    public function getApiController(): ApiController
    {
        if (!$this->booted) {
            $this->boot();
        }

        return new ApiController(
            $this->getKeyManager(),
            $this->getTtsManager(),
            $this->getRepository(),
            $this->config,
            $this->getWebhookDispatcher(),
            $this->getRateLimiter()
        );
    }

    public function handleRequest(): void
    {
        if (!$this->booted) {
            $this->boot();
        }

        // Simple router - production apps should use a real router
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        session_start();

        $dashboard = $this->getDashboardController();
        $api = $this->getApiController();

        $response = match (true) {
            $path === '/' || $path === '/dashboard' => $dashboard->indexAction(new Http\Request()),
            $path === '/login' && $method === 'GET' => $dashboard->loginAction(new Http\Request()),
            $path === '/login' && $method === 'POST' => $dashboard->loginPostAction(new Http\Request()),
            $path === '/logout' => $dashboard->logoutAction(),
            str_starts_with($path, '/dashboard/generate') => $dashboard->generateAction(new Http\Request()),
            str_starts_with($path, '/library') => $dashboard->libraryAction(new Http\Request()),
            str_starts_with($path, '/api/v1/speech') => $api->speechAction(new Http\Request()),
            str_starts_with($path, '/api/v1/history') => $api->historyAction(new Http\Request()),
            str_starts_with($path, '/api/v1/tokens') => $api->issueTokenAction(new Http\Request()),
            str_starts_with($path, '/api/v1/voices') => $api->listVoicesAction(new Http\Request()),
            str_starts_with($path, '/api/v1/clone-voice') => $api->cloneVoiceAction(new Http\Request()),
            default => Http\Response::html('<h1 class="p-8 text-2xl">404 Not Found</h1>', 404),
        };

        $response->send();
    }
}
