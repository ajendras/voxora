<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Automation\BatchGenerator;
use Voxora\Automation\Scheduler;
use Voxora\Http\ApiController;
use Voxora\Http\DashboardController;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Security\KeyManager;
use Voxora\Security\RateLimiter;
use Voxora\Storage\SqliteRepository;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\Manager;
use Voxora\TTS\OpenAIProvider;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\WebhookDispatcher;
use Predis\Client as RedisClient;

/**
 * Kernel
 *
 * Bootstraps all services, wires dependencies and routes HTTP requests
 * to the appropriate controller. Updated to inject SqliteRepository into KeyManager
 * for the token revocation list.
 */
final class Kernel
{
    private array $config;
    private SqliteRepository $repository;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private Scheduler $scheduler;
    private RateLimiter $rateLimiter;
    private WebhookDispatcher $webhookDispatcher;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function boot(): self
    {
        $storagePath = $this->config['storage']['path'] ?? 'data/voxora.db';
        $this->repository = new SqliteRepository($storagePath);

        $this->keyManager = new KeyManager($this->config, $this->repository);

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->config['webhook'] ?? []
        );

        $this->rateLimiter = new RateLimiter($this->config['rate_limit'] ?? [], $this->createRedisClient());

        $this->ttsManager = $this->createTtsManager();

        $this->scheduler = new Scheduler(
            $this->repository,
            new BatchGenerator($this->ttsManager, 'output', $this->webhookDispatcher),
            $this->config['scheduler']['log'] ?? 'data/scheduler.log'
        );

        return $this;
    }

    private function createRedisClient(): RedisClient
    {
        $redisConfig = $this->config['redis'] ?? [];
        return new RedisClient([
            'scheme' => 'tcp',
            'host' => $redisConfig['host'] ?? '127.0.0.1',
            'port' => $redisConfig['port'] ?? 6379,
            'password' => $redisConfig['password'] ?? null,
        ]);
    }

    private function createTtsManager(): Manager
    {
        $manager = new Manager($this->config, $this->repository, $this->webhookDispatcher);

        $providers = $this->config['providers'] ?? [];

        if (!empty($providers['eleven']['api_key'] ?? '')) {
            $manager->registerProvider('elevenlabs', new ElevenLabsProvider($providers['eleven']));
        }

        if (!empty($providers['openai']['api_key'] ?? '')) {
            $manager->registerProvider('openai', new OpenAIProvider($providers['openai']));
        }

        if (!empty($providers['piper']['model'] ?? '')) {
            $manager->registerProvider('piper', new PiperProvider($providers['piper']));
        }

        return $manager;
    }

    public function handle(Request $request): Response
    {
        $path = $request->getPath();
        $method = $request->getMethod();

        if (str_starts_with($path, '/api/v1/')) {
            $controller = new ApiController(
                $this->keyManager,
                $this->ttsManager,
                $this->repository,
                $this->config,
                $this->webhookDispatcher,
                $this->rateLimiter
            );

            return match (true) {
                $path === '/api/v1/speech' && $method === 'POST' => $controller->speechAction($request),
                $path === '/api/v1/history' && $method === 'GET' => $controller->historyAction($request),
                $path === '/api/v1/tokens' && $method === 'POST' => $controller->issueTokenAction($request),
                $path === '/api/v1/voices' && $method === 'GET' => $controller->listVoicesAction($request),
                $path === '/api/v1/library/export' && $method === 'GET' => $controller->exportLibraryAction($request),
                $path === '/api/v1/clone-voice' && $method === 'POST' => $controller->cloneVoiceAction($request),
                default => Response::json(['error' => 'Not found'], 404),
            };
        }

        $controller = new DashboardController(
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher
        );

        return match (true) {
            $path === '/login' => $controller->loginAction($request),
            $path === '/logout' => $controller->logoutAction($request),
            str_starts_with($path, '/dashboard') => $controller->indexAction($request),
            default => $controller->indexAction($request),
        };
    }

    public function getKeyManager(): KeyManager
    {
        return $this->keyManager;
    }

    public function getTtsManager(): Manager
    {
        return $this->ttsManager;
    }

    public function getScheduler(): Scheduler
    {
        return $this->scheduler;
    }

    public function getRepository(): SqliteRepository
    {
        return $this->repository;
    }
}
