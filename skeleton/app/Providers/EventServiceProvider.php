<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register event listeners here.
        // Example:
        //   $eventBus = $this->container->make(\Fabriq\Events\EventBus::class);
        //   $eventBus->listen('user.created', [MyListener::class, 'handle']);
    }
}
