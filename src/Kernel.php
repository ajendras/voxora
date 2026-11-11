<?php

declare(strict_types=1);

namespace Voxora;

use Voxora\Http\ApiController;
use Voxora\Http\DashboardController;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Http\Router;
use Voxora\Http\SecurityMiddleware;
use Voxora\Security\KeyManager;
use Voxora\Storage\SqliteRepository;
use Voxora\TTS\ElevenLabsProvider;
use Voxora\TTS\Manager;
use Voxora\TTS\PiperProvider;
use Voxora\TTS\ProviderInterface;
use RuntimeException;

/**
 * Kernel
 *
 * Central application bootstrapper and request dispatcher.
 * Wires KeyManager, TTS Manager (with Piper fallback), Repository and SecurityMiddleware together.
 * Handles both HTMX dashboard routes and JSON API routes.
 */
final class Kernel
{
    private array $config;
    private Router $router;
    private KeyManager $keyManager;
    private Manager $ttsManager;
    private SqliteRepository $repository;
    private SecurityMiddleware $securityMiddleware;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (empty($config['security']['encrypt_key'] ?? '')) {
            throw new RuntimeException('security.encrypt_key must be configured.');
        }

        if (empty($config['storage']['path'] ?? '')) {
            throw new RuntimeException('storage.path must be configured.');
        }
    }

    /**
     * Boot all core services and ensure storage is ready.
     */
    public function boot(): void
    {
        // Ensure database is initialized
        $this->repository = new SqliteRepository($this->config['storage']['path']);
        $this->repository->initialize();

        // Security: token management
        $this->keyManager = new KeyManager(
            $this->config['security']['encrypt_key'],
            (int) ($this->config['security']['token_lifetime_days'] ?? 90)
        );

        // TTS engine with available providers (Piper added as local fallback)
        $providers = $this->createTtsProviders();
        $this->ttsManager = new Manager($this->repository, ...$providers);

        // Middleware for API token validation
        $this->securityMiddleware = new SecurityMiddleware($this->keyManager);
    }

    /**
     * Instantiate configured TTS providers from the config.
     *
     * @return ProviderInterface[]
     */
    private function createTtsProviders(): array
    {
        $providers = [];
        $cfg = $this->config['providers'] ?? [];

        // ElevenLabs (primary cloud provider)
        if (!empty($cfg['eleven']['api_key']) && $cfg['eleven']['api_key'] !== 'sk-...') {
            $providers[] = new ElevenLabsProvider(
                $cfg['eleven']['api_key'],
                $cfg['eleven']['base_uri'] ?? 'https://api.elevenlabs.io/v1'
            );
        }

        // Piper local fallback (only if model is present)
        $piperCfg = $cfg['piper'] ?? [];
        if (!empty($piperCfg['model']) && file_exists($piperCfg['model'])) {
            $providers[] = new PiperProvider(
                $piperCfg['binary'] ?? 'piper',
                $piperCfg['model']
            );
        }

        if ($providers === []) {
            // In production this would be a soft failure with UI warning.
            // For now we require at least one provider.
            throw new RuntimeException(
                'No TTS providers are configured. Please set providers.eleven.api_key or providers.piper.model.'
            );
        }

        return $providers;
    }

    public function setRouter(Router $router): void
    {
        $this->router = $router;
    }

    /**
     * Process an incoming request through middleware and routing.
     */
    public function handle(Request $request): Response
    {
        if (!isset($this->router)) {
            throw new RuntimeException('Router must be set with setRouter() before handling requests.');
        }

        // Apply security middleware (validates tokens on /api/* routes)
        $this->securityMiddleware->process($request);

        $method = $request->getMethod();
        $path = $request->getPath();

        $handler = $this->router->resolve($method, $path);

        if ($handler === null) {
            return Response::json(['error' => 'Not Found'], 404);
        }

        [$controllerClass, $actionMethod] = $handler;

        $controller = $this->instantiateController($controllerClass);

        return $controller->{$actionMethod}($request);
    }

    /**
     * Create controller instance with required dependencies injected.
     */
    private function instantiateController(string $class): object
    {
        return match ($class) {
            DashboardController::class => new DashboardController(
                $this->ttsManager,
                $this->repository,
                $this->config
            ),
            ApiController::class => new ApiController(
                $this->keyManager,
                $this->ttsManager,
                $this->repository,
                $this->config
            ),
            default => new $class(),
        };
    }

    /**
     * Expose services for testing and the batch example script.
     */
    public function getKeyManager(): KeyManager
    {
        return $this->keyManager;
    }

    public function getTtsManager(): Manager
    {
        return $this->ttsManager;
    }

    public function getRepository(): SqliteRepository
    {
        return $this->repository;
    }
}
