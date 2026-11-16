<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Http\ApiController;
use Voxora\Http\DashboardController;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Http\Router;
use Voxora\Security\KeyManager;
use Voxora\Security\RateLimiter;
use Voxora\Storage\SqliteRepository;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\Manager;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\WebhookDispatcher;
use Predis\Client as PredisClient;
use RuntimeException;

/**
 * Kernel
 *
 * Central application bootstrapper and request dispatcher.
 * Wires KeyManager, TTS Manager (with Piper fallback), Repository, WebhookDispatcher,
 * RateLimiter (Redis) and the controllers together.
 * Handles both HTMX dashboard routes and JSON API routes.
 */
final class Kernel
{
    private array $config;
    private Router $router;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private WebhookDispatcher $webhookDispatcher;
    private RateLimiter $rateLimiter;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function boot(): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $storagePath = $this->config['storage']['path'] ?? 'data/voxora.db';
        $this->repository = new SqliteRepository($storagePath);
        $this->repository->ensureSchema();

        $encryptKey = $this->config['security']['encrypt_key'] ?? null;
        if ($encryptKey === null || strlen($encryptKey) < 16) {
            throw new RuntimeException('security.encrypt_key is required and must be sufficiently long');
        }

        $this->keyManager = new KeyManager(
            $encryptKey,
            $this->config['security']['token_lifetime_days'] ?? 90
        );

        $elevenConfig = $this->config['providers']['eleven'] ?? [];
        $piperConfig = $this->config['providers']['piper'] ?? [];

        $elevenProvider = new ElevenLabsProvider($elevenConfig);
        $piperProvider = new PiperProvider($piperConfig);

        $this->ttsManager = new Manager(
            $elevenProvider,
            $piperProvider,
            $this->repository,
            $this->config
        );

        $this->webhookDispatcher = new WebhookDispatcher(
            $this->config['webhooks'] ?? []
        );

        // Redis-backed rate limiter
        $redisConfig = $this->config['redis'] ?? [
            'host' => '127.0.0.1',
            'port' => 6379,
        ];
        $predisOptions = [
            'scheme' => 'tcp',
            'host' => $redisConfig['host'],
            'port' => (int) $redisConfig['port'],
        ];
        if (isset($redisConfig['password'])) {
            $predisOptions['password'] = $redisConfig['password'];
        }
        $client = new PredisClient($predisOptions);

        $rateConfig = $this->config['rate_limit'] ?? [
            'requests' => 100,
            'window' => 60,
        ];
        $this->rateLimiter = new RateLimiter(
            $client,
            (int) $rateConfig['requests'],
            (int) $rateConfig['window']
        );

        $this->router = new Router();

        $dashboardController = new DashboardController(
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );

        $apiController = new ApiController(
            $this->keyManager,
            $this->ttsManager,
            $this->repository,
            $this->config,
            $this->webhookDispatcher,
            $this->rateLimiter
        );

        // Register routes
        $this->router->get('/login', fn() => $dashboardController->loginAction());
        $this->router->post('/login', fn() => $dashboardController->loginAction());
        $this->router->get('/dashboard', fn() => $dashboardController->indexAction());
        $this->router->post('/dashboard/generate', fn() => $dashboardController->generateAction());
        $this->router->get('/library', fn() => $dashboardController->libraryAction());
        $this->router->get('/logout', fn() => $dashboardController->logoutAction());

        $this->router->post('/api/v1/speech', fn(Request $r) => $apiController->speechAction($r));
        $this->router->get('/api/v1/history', fn(Request $r) => $apiController->historyAction($r));
        $this->router->post('/api/v1/tokens', fn(Request $r) => $apiController->issueTokenAction($r));
        $this->router->get('/api/v1/voices', fn(Request $r) => $apiController->listVoicesAction($r));
        $this->router->post('/api/v1/clone-voice', fn(Request $r) => $apiController->cloneVoiceAction($r));

        return $this;
    }

    public function handle(): void
    {
        $request = Request::fromGlobals();
        $response = $this->router->dispatch($request);
        $response->send();
    }

    public function getTtsManager(): Manager
    {
        return $this->ttsManager;
    }

    public function getKeyManager(): KeyManager
    {
        return $this->keyManager;
    }

    public function getRateLimiter(): RateLimiter
    {
        return $this->rateLimiter;
    }
}
