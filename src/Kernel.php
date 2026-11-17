<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\TTS\Manager;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\WebhookDispatcher;
use Voxora\Security\KeyManager;
use Voxora\Security\RateLimiter;
use Voxora\Storage\SqliteRepository;
use Voxora\Automation\BatchGenerator;
use Voxora\Automation\Scheduler;
use Predis\Client as RedisClient;
use RuntimeException;

/**
 * Kernel
 *
 * Bootstraps all services (Manager, KeyManager, Repository, RateLimiter,
 * Scheduler, WebhookDispatcher). Registers built-in and plugin providers.
 * Provides getters for controllers and the new Scheduler.
 */
final class Kernel
{
    private array $config;
    private Manager $ttsManager;
    private KeyManager $keyManager;
    private SqliteRepository $repository;
    private RateLimiter $rateLimiter;
    private WebhookDispatcher $webhookDispatcher;
    private Scheduler $scheduler;

    public function __construct(array $config)
    {
        $this->config = $config + [
            'storage' => ['path' => 'data/voxora.db'],
            'audio' => ['directory' => 'storage/audio', 'public_url_prefix' => '/audio'],
            'ffmpeg' => ['binary' => 'ffmpeg', 'normalize' => true, 'target_loudness' => -16.0],
            'rate_limit' => ['requests' => 100, 'window' => 60],
            'redis' => ['host' => '127.0.0.1', 'port' => 6379],
            'scheduler' => ['log' => 'data/scheduler.log'],
        ];
    }

    public function boot(): self
    {
        if (empty($this->config['security']['encrypt_key'])) {
            throw new RuntimeException('security.encrypt_key must be configured');
        }

        $this->repository = new SqliteRepository($this->config['storage']['path']);

        $this->keyManager = new KeyManager($this->config['security']['encrypt_key']);

        $redis = new RedisClient([
            'host' => $this->config['redis']['host'],
            'port' => $this->config['redis']['port'],
            'password' => $this->config['redis']['password'] ?? null,
        ]);

        $this->rateLimiter = new RateLimiter(
            $redis,
            (int) ($this->config['rate_limit']['requests'] ?? 100),
            (int) ($this->config['rate_limit']['window'] ?? 60)
        );

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->config['webhooks'] ?? []
        );

        $this->ttsManager = new Manager(
            $this->repository,
            $this->config,
            $this->webhookDispatcher
        );

        // Register core providers
        $elevenConfig = $this->config['providers']['eleven'] ?? $this->config['providers']['elevenlabs'] ?? [];
        if (!empty($elevenConfig['api_key'] ?? '')) {
            $this->ttsManager->registerProvider('elevenlabs', new ElevenLabsProvider($elevenConfig));
        }

        $piperConfig = $this->config['providers']['piper'] ?? [];
        $this->ttsManager->registerProvider('piper', new PiperProvider($piperConfig));

        // Auto-register plugin providers defined in config
        foreach ($this->config['providers'] ?? [] as $name => $prov) {
            if (isset($prov['class']) && class_exists($prov['class'])) {
                $instance = new $prov['class']($prov);
                $this->ttsManager->registerProvider($name, $instance);
            }
        }

        $this->scheduler = new Scheduler(
            $this->repository,
            new BatchGenerator(
                $this->ttsManager,
                $this->config['audio']['directory'] ?? 'output',
                $this->webhookDispatcher
            ),
            $this->webhookDispatcher,
            $this->config['scheduler']['log']
        );

        return $this;
    }

    public function getTtsManager(): Manager
    {
        return $this->ttsManager;
    }

    public function getKeyManager(): KeyManager
    {
        return $this->keyManager;
    }

    public function getRepository(): SqliteRepository
    {
        return $this->repository;
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }

    public function getWebhookDispatcher(): WebhookDispatcher
    {
        return $this->webhookDispatcher;
    }

    public function getScheduler(): Scheduler
    {
        return $this->scheduler;
    }

    /**
     * Simple front-controller router (real implementation may delegate to a dedicated Router).
     * Controllers receive fully wired dependencies.
     */
    public function handle(string $uri = '/', array $server = [], array $post = []): Response
    {
        $request = new Http\Request($uri, $server, $post); // Request wrapper used by controllers

        if (str_starts_with($uri, '/api/v1/')) {
            $controller = new Http\ApiController(
                $this->keyManager,
                $this->ttsManager,
                $this->repository,
                $this->config,
                $this->webhookDispatcher,
                $this->rateLimiter
            );

            $endpoint = trim(str_replace('/api/v1/', '', $uri), '/');

            return match ($endpoint) {
                'speech' => $controller->speechAction($request),
                'history' => $controller->historyAction($request),
                'tokens' => $controller->issueTokenAction($request),
                'voices' => $controller->listVoicesAction($request),
                'clone-voice' => $controller->cloneVoiceAction($request),
                default => new Http\Response(json_encode(['error' => 'Not found']), 404, ['Content-Type' => 'application/json']),
            };
        }

        $controller = new Http\DashboardController(
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher
        );

        if (in_array($uri, ['/', '/dashboard'], true)) {
            return $controller->indexAction($request);
        }

        if ($uri === '/login') {
            return $controller->loginAction($request);
        }

        return new Http\Response('Not Found', 404);
    }
}
