<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Voxora\Kernel;
use Voxora\Http\Router;
use Voxora\Http\Request;
use Voxora\Http\Response;
use Voxora\Http\DashboardController;
use Voxora\Http\ApiController;

$config = require __DIR__ . '/../config/app.php';
$kernel = new Kernel($config);
$kernel->boot();

$router = new Router();

// Dashboard & auth routes
$router->get('/', fn(): Response => Response::redirect('/login'));
$router->get('/login', [DashboardController::class, 'loginAction']);
$router->post('/login', [DashboardController::class, 'loginAction']);
$router->get('/logout', [DashboardController::class, 'logoutAction']);
$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/library', [DashboardController::class, 'library']);
$router->post('/generate', [DashboardController::class, 'generateAction']);

// API routes (protected by SecurityMiddleware inside Kernel)
$router->post('/api/v1/speech', [ApiController::class, 'speechAction']);
$router->get('/api/v1/history', [ApiController::class, 'historyAction']);
$router->post('/api/v1/tokens', [ApiController::class, 'issueTokenAction']);
$router->get('/api/v1/voices', [ApiController::class, 'listVoicesAction']);

$kernel->setRouter($router);

$request = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
