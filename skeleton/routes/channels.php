<?php

declare(strict_types=1);

use Fabriq\Realtime\Gateway;
use Fabriq\Realtime\PushService;
use Fabriq\Kernel\Container;

return function (Gateway $gateway, PushService $pushService, Container $container): void {
    /*
    |--------------------------------------------------------------------------
    | WebSocket Channel Definitions
    |--------------------------------------------------------------------------
    |
    | Define channel authorization rules and WebSocket handlers here.
    |
    | Example:
    |   $gateway->authorizeChannel('room.*', function (string $channel, array $meta) {
    |       return true;
    |   });
    |
    */
};
