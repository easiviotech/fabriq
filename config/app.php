<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Lists the service providers that are registered at boot time
 * and general application metadata.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'name' => 'Fabriq',

    /*
    |--------------------------------------------------------------------------
    | Autoloaded Service Providers
    |--------------------------------------------------------------------------
    |
    | The service providers listed here will be automatically registered
    | during application bootstrap. They are loaded in order — register()
    | is called on each, then boot() is called on each.
    |
    */
    'providers' => [
        \App\Providers\AppServiceProvider::class,
        \App\Providers\AuthServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
        \App\Providers\EventServiceProvider::class,
        \App\Providers\RealtimeServiceProvider::class,
        \App\Providers\StreamingServiceProvider::class,
        \App\Providers\GamingServiceProvider::class,
    ],
];

