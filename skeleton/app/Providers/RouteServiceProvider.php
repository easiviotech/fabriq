<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Http\Router;

class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $router = $this->container->make(Router::class);

        $routeFile = $this->app->routesPath('api.php');

        if (is_file($routeFile)) {
            $registrar = require $routeFile;
            $registrar($router, $this->container);
        }
    }
}
