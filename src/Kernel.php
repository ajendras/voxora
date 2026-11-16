<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Http\ApiController;
use Voxora\Http\DashboardController;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Security\KeyManager;
use Voxora\Security\RateLimiter;
use Voxora\Storage\SqliteRepository;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\Manager;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\ProviderInterface;
use Voxora\TTS\WebhookDispatcher;
use Predis\Client as RedisClient;
use RuntimeException;

/**
 * Kernel
 *
 * Bootstraps services, registers built-in TTS providers and exposes the
 * plugin system through the Manager. Additional providers can be registered
 * at runtime after boot() using $kernel->getTtsManager()->registerProvider().
 */
final class Kernel
{
    private array $config;
    private ?SqliteRepository $repository = null;
    private ?Manager $ttsManager = null;
    private ?KeyManager $keyManager = null;
    private ?RateLimiter $rateLimiter = null;
    private ?WebhookDispatcher $webhookDispatcher = null;
    private ?RedisClient $redis = null;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (empty($config['security']['encrypt_key'])) {
            throw new RuntimeException('security.encrypt_key is required');
        }
    }

    public function boot(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->getRepository();
    }

    public function getTtsManager(): Manager
    {
        if ($this->ttsManager === null) {
            $repo = $this->getRepository();
            $dispatcher = $this->getWebhookDispatcher();
            $this->ttsManager = new Manager($repo, $this->config, $dispatcher);

            // Register built-in providers (core of the plugin system)
            $elevenConfig = $this->config['providers']['elevenlabs'] ?? $this->config['providers']['eleven'] ?? [];
            $this->ttsManager->registerProvider('elevenlabs', new ElevenLabsProvider($elevenConfig));

            $piperConfig = $this->config['providers']['piper'] ?? [];
            $this->ttsManager->registerProvider('piper', new PiperProvider($piperConfig));

            // Auto-register any custom providers defined in config (plugin support)
            foreach ($this->config['providers'] ?? [] as $name => $providerConfig) {
                if (isset($providerConfig['class']) && class_exists($providerConfig['class'])) {
                    $instance = new $providerConfig['class']($providerConfig);
                    if ($instance instanceof ProviderInterface) {
                        $this->ttsManager->registerProvider($name, $instance);
                    }
                }
            }
        }

        return $this->ttsManager;
    }

    public function getKeyManager(): KeyManager
    {
        if ($this->keyManager === null) {
            $this->keyManager = new KeyManager(
                $this->config['security']['encrypt_key'],
                $this->getRepository(),
                $this->config['security']['token_lifetime_days'] ?? 90
            );
        }
        return $this->keyManager;
    }

    public function getRateLimiter(): RateLimiter
    {
        if ($this->rateLimiter === null) {
            $this->rateLimiter = new RateLimiter(
                $this->getRedis(),
                $this->config['rate_limit'] ?? ['requests' => 100, 'window' => 60]
            );
        }
        return $this->rateLimiter;
    }

    public function getRepository(): SqliteRepository
    {
        if ($this->repository === null) {
            $path = $this->config['storage']['path'] ?? __DIR__ . '/../data/voxora.db';
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->repository = new SqliteRepository($path);
            $this->repository->migrate();
        }
        return $this->repository;
    }

    private function getWebhookDispatcher(): WebhookDispatcher
    {
        if ($this->webhookDispatcher === null) {
            $this->webhookDispatcher = new WebhookDispatcher(
                $this->config['webhooks'] ?? []
            );
        }
        return $this->webhookDispatcher;
    }

    private function getRedis(): RedisClient
    {
        if ($this->redis === null) {
            $this->redis = new RedisClient([
                'host' => $this->config['redis']['host'] ?? '127.0.0.1',
                'port' => $this->config['redis']['port'] ?? 6379,
            ]);
            if (!empty($this->config['redis']['password'])) {
                $this->redis->auth($this->config['redis']['password']);
            }
        }
        return $this->redis;
    }

    public function handle(): void
    {
        $this->boot();

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $ttsManager = $this->getTtsManager();
        $repository = $this->getRepository();
        $config = $this->config;
        $webhook = $this->getWebhookDispatcher();
        $keyManager = $this->getKeyManager();
        $rateLimiter = $this->getRateLimiter();

        if (in_array($path, ['/login', '/'], true)) {
            (new DashboardController($ttsManager, $repository, $config, $webhook))->loginAction();
            return;
        }

        if ($path === '/dashboard') {
            (new DashboardController($ttsManager, $repository, $config, $webhook))->indexAction();
            return;
        }

        if ($path === '/dashboard/generate' && $method === 'POST') {
            echo (new DashboardController($ttsManager, $repository, $config, $webhook))->generateAction();
            return;
        }

        if ($path === '/library') {
            (new DashboardController($ttsManager, $repository, $config, $webhook))->libraryAction();
            return;
        }

        if ($path === '/logout') {
            (new DashboardController($ttsManager, $repository, $config, $webhook))->logoutAction();
            return;
        }

        if (str_starts_with($path, '/api/v1/')) {
            $api = new ApiController(
                $keyManager,
                $ttsManager,
                $repository,
                $config,
                $webhook,
                $rateLimiter
            );
            $request = new Request();
            $response = match ($path) {
                '/api/v1/speech' => $api->speechAction($request),
                '/api/v1/history' => $api->historyAction($request),
                '/api/v1/tokens' => $api->issueTokenAction($request),
                '/api/v1/voices' => $api->listVoicesAction($request),
                '/api/v1/clone-voice' => $api->cloneVoiceAction($request),
                default => Response::json(['error' => 'Not found'], 404),
            };
            $response->send();
            return;
        }

        http_response_code(404);
        echo 'Not Found';
    }
}
