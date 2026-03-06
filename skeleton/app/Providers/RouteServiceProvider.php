<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Http\Router;
use Fabriq\Http\Request;
use Fabriq\Http\Response;

class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->instance(Router::class, new Router());
    }

    public function boot(): void
    {
        $container = $this->app->container();
        $router = $container->make(Router::class);

        $routeFile = $this->app->routesPath('api.php');

        if (is_file($routeFile)) {
            $registrar = require $routeFile;
            $registrar($router, $container);
        }

        $this->app->addRoute(function (\Swoole\Http\Request $swooleReq, \Swoole\Http\Response $swooleRes) use ($router): bool {
            $method = strtoupper($swooleReq->server['request_method'] ?? 'GET');
            $path   = $swooleReq->server['request_uri'] ?? '/';

            $match = $router->match($method, $path);

            if ($match === null) {
                return false;
            }

            $request  = new Request($swooleReq);
            $response = new Response($swooleRes);

            ($match['handler'])($request, $response, $match['params']);

            return true;
        });
    }
}
