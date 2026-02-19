<?php

declare(strict_types=1);

/**
 * API Route Definitions
 *
 * This file is loaded by RouteServiceProvider. It receives the Router
 * instance and the DI Container, and registers all HTTP API routes.
 *
 * Route handler signature: fn(Request $request, Response $response, array $params): void
 *
 * @see \App\Providers\RouteServiceProvider
 */

use Fabriq\Http\Router;
use Fabriq\Kernel\Container;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\MessageController;
use App\Repositories\ChatRepository;
use Fabriq\Security\JwtAuthenticator;

return function (Router $router, Container $container): void {

    // Resolve controllers from the container
    /** @var ChatRepository $repo */
    $repo = $container->make(ChatRepository::class);
    /** @var JwtAuthenticator $jwt */
    $jwt = $container->make(JwtAuthenticator::class);

    $tenantController = new TenantController($repo);
    $authController = new AuthController($jwt);
    $userController = new UserController($repo);
    $roomController = new RoomController($repo);
    $messageController = new MessageController($repo);

    // Store controllers in container for later access (e.g., EventServiceProvider)
    $container->instance(MessageController::class, $messageController);

    /*
    |--------------------------------------------------------------------------
    | Platform Routes (no tenant context required)
    |--------------------------------------------------------------------------
    */
    $router->post('/api/tenants', [$tenantController, 'store']);

    /*
    |--------------------------------------------------------------------------
    | Auth Routes
    |--------------------------------------------------------------------------
    */
    $router->post('/api/users', [$userController, 'store']);
    $router->post('/api/auth/token', [$authController, 'issueToken']);

    /*
    |--------------------------------------------------------------------------
    | Room Routes (tenant-scoped)
    |--------------------------------------------------------------------------
    */
    $router->post('/api/rooms', [$roomController, 'store']);
    $router->get('/api/rooms', [$roomController, 'index']);
    $router->post('/api/rooms/{roomId}/join', [$roomController, 'join']);

    /*
    |--------------------------------------------------------------------------
    | Message Routes (tenant-scoped)
    |--------------------------------------------------------------------------
    */
    $router->post('/api/rooms/{roomId}/messages', [$messageController, 'store']);
    $router->get('/api/rooms/{roomId}/messages', [$messageController, 'index']);
};

