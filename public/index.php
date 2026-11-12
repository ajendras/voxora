<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Voxora\Kernel;
use Voxora\Http\Request;
use Voxora\Http\Router;
use Voxora\Http\DashboardController;
use Voxora\Http\ApiController;

$config = require __DIR__ . '/../config/app.php';
$kernel = new Kernel($config);
$kernel->boot();

$router = new Router();

// Dashboard routes
$router->add('GET', '/dashboard', [DashboardController::class, 'index']);
$router->add('GET', '/library', [DashboardController::class, 'library']);
$router->add('GET', '/login', [DashboardController::class, 'loginAction']);
$router->add('POST', '/login', [DashboardController::class, 'loginAction']);
$router->add('GET', '/logout', [DashboardController::class, 'logoutAction']);
$router->add('POST', '/generate', [DashboardController::class, 'generateAction']);

// API routes
$router->add('POST', '/api/v1/speech', [ApiController::class, 'speechAction']);
$router->add('GET', '/api/v1/history', [ApiController::class, 'historyAction']);
$router->add('POST', '/api/v1/tokens', [ApiController::class, 'issueTokenAction']);
$router->add('GET', '/api/v1/voices', [ApiController::class, 'listVoicesAction']);

// Voice cloning workflow
$router->add('POST', '/api/v1/clone-voice', [ApiController::class, 'cloneVoiceAction']);

$kernel->setRouter($router);

$request = Request::fromGlobals();
$response = $kernel->handle($request);
$response->send();
