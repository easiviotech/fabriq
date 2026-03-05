<?php

declare(strict_types=1);

use Fabriq\Http\Router;
use Fabriq\Kernel\Container;
use App\Http\Controllers\HomeController;

return function (Router $router, Container $container): void {

    $home = new HomeController();

    /*
    |--------------------------------------------------------------------------
    | API Routes
    |--------------------------------------------------------------------------
    |
    | Register your application routes here. Each route receives a
    | Request and Response object plus any URI parameters.
    |
    | Route handler signature:
    |   fn(Request $request, Response $response, array $params): void
    |
    */

    $router->get('/api/welcome', [$home, 'index']);

    // Add your routes below:
    // $router->get('/api/users', [$userController, 'index']);
    // $router->post('/api/users', [$userController, 'store']);
};
