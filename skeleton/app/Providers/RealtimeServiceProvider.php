<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;

class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Register WebSocket handlers and channel definitions here.
        // Example:
        //   $gateway = $this->container->make(\Fabriq\Realtime\Gateway::class);
        //   $gateway->onMessage(new MyWsHandler());

        $channelsFile = $this->app->routesPath('channels.php');

        if (is_file($channelsFile)) {
            $registrar = require $channelsFile;
            $registrar(
                $this->container->make(\Fabriq\Realtime\Gateway::class),
                $this->container->make(\Fabriq\Realtime\PushService::class),
                $this->container,
            );
        }
    }
}
