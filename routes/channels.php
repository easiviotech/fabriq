<?php

declare(strict_types=1);

/**
 * WebSocket Channel Definitions
 *
 * This file is loaded by RealtimeServiceProvider. It receives the Gateway,
 * PushService, and DI Container for registering WebSocket channel handlers.
 *
 * @see \App\Providers\RealtimeServiceProvider
 */

use Fabriq\Realtime\Gateway;
use Fabriq\Realtime\PushService;
use Fabriq\Kernel\Container;

return function (Gateway $gateway, PushService $pushService, Container $container): void {
    /*
    |--------------------------------------------------------------------------
    | Channel Authorization & Configuration
    |--------------------------------------------------------------------------
    |
    | Define channel authorization rules and configuration here.
    | The actual WS message handling is done via ChatWsHandler,
    | registered in the RealtimeServiceProvider.
    |
    | Example:
    |   $gateway->authorizeChannel('room.*', function (string $channel, array $meta) {
    |       // Return true if the user can join this channel
    |       return true;
    |   });
    |
    */
};

