<?php

declare(strict_types=1);

/**
 * Database connection pool configuration.
 *
 * Fabriq uses coroutine-safe connection pools for MySQL.
 * Two pools are maintained: "platform" (shared) and "app" (tenant-scoped).
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Platform Database (shared across tenants)
    |--------------------------------------------------------------------------
    */
    'platform' => [
        'host' => 'mysql',
        'port' => 3306,
        'database' => 'sf_platform',
        'username' => 'fabriq',
        'password' => 'sfpass',
        'charset' => 'utf8mb4',
        'pool' => [
            'max_size' => 20,
            'borrow_timeout' => 3.0, // seconds
            'idle_timeout' => 60.0,  // seconds
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application Database (tenant-scoped queries)
    |--------------------------------------------------------------------------
    */
    'app' => [
        'host' => 'mysql',
        'port' => 3306,
        'database' => 'sf_app',
        'username' => 'fabriq',
        'password' => 'sfpass',
        'charset' => 'utf8mb4',
        'pool' => [
            'max_size' => 20,
            'borrow_timeout' => 3.0,
            'idle_timeout' => 60.0,
        ],
    ],
];
