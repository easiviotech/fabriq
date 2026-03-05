<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind application services into the container here.
        // Example:
        //   $this->container->singleton(MyService::class, fn () => new MyService());
    }

    public function boot(): void
    {
        // Run after all providers are registered.
        // Good place for event listeners, middleware registration, etc.
    }
}
