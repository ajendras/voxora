<?php

declare(strict_types=1);

/**
 * Voxora Front Controller
 *
 * This file bootstraps the Kernel, registers routes for the dashboard (HTMX/Tailwind)
 * and the JSON REST API, then dispatches the incoming request and sends the response.
 * All static assets (CSS/JS) are served directly by the web server.
 */

namespace Voxora;

use Voxora\Http\Request;
use Voxora\Http\Router;
use Voxora\Http\Response;

require_once __DIR__ . '/../vendor/autoload.php';

// Ensure required directories exist
$dirs = [
    __DIR__ . '/../data',
    __DIR__ . '/../storage/audio',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Load configuration
$config = require __DIR__ . '/../config/app.php';

// Create and boot the kernel (loads config, providers, repository, middleware, etc.)
$kernel = new Kernel($config);
$kernel->boot();

// Create router and register all routes
$router = new Router();

$router->get('/', [Http\DashboardController::class, 'index']);
$router->get('/dashboard', [Http\DashboardController::class, 'index']);
$router->get('/library', [Http\DashboardController::class, 'library']);

$router->post('/generate', [Http\DashboardController::class, 'generateAction']);

$router->post('/api/v1/speech', [Http\ApiController::class, 'speechAction']);
$router->get('/api/v1/history', [Http\ApiController::class, 'historyAction']);
$router->post('/api/v1/tokens', [Http\ApiController::class, 'issueTokenAction']);
$router->get('/api/v1/voices', [Http\ApiController::class, 'listVoicesAction']);

// Register router with kernel
$kernel->setRouter($router);

// Create request from globals (includes support for HTMX headers)
$request = Request::createFromGlobals();

// Let the kernel handle the request (applies middleware, dispatches to controller, etc.)
$response = $kernel->handle($request);

// Send the response to the client
$response->send();
