<?php

declare(strict_types=1);

/**
 * Default SwooleFabric configuration.
 *
 * Values can be overridden by environment-specific config files.
 */
return [
    'server' => [
        'host' => '0.0.0.0',
        'port' => 8000,
        'workers' => 2,
        'task_workers' => 2,
        'log_level' => 4, // SWOOLE_LOG_WARNING
    ],

    'database' => [
        'platform' => [
            'host' => 'mysql',
            'port' => 3306,
            'database' => 'sf_platform',
            'username' => 'swoolefabric',
            'password' => '', // Set via env or override config
            'charset' => 'utf8mb4',
            'pool' => [
                'max_size' => 20,
                'borrow_timeout' => 3.0, // seconds
                'idle_timeout' => 60.0, // seconds
            ],
        ],
        'app' => [
            'host' => 'mysql',
            'port' => 3306,
            'database' => 'sf_app',
            'username' => 'swoolefabric',
            'password' => '',
            'charset' => 'utf8mb4',
            'pool' => [
                'max_size' => 20,
                'borrow_timeout' => 3.0,
                'idle_timeout' => 60.0,
            ],
        ],
    ],

    'redis' => [
        'host' => 'redis',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'pool' => [
            'max_size' => 20,
            'borrow_timeout' => 3.0,
            'idle_timeout' => 60.0,
        ],
    ],

    'tenancy' => [
        'resolver_chain' => ['host', 'header', 'token'],
        'cache_ttl' => 300, // seconds
    ],

    'auth' => [
        'jwt' => [
            'secret' => '', // Set via env or override config
            'algorithm' => 'HS256',
            'ttl' => 3600,
        ],
    ],

    'rate_limit' => [
        'default' => [
            'max_requests' => 100,
            'window' => 60, // seconds
        ],
    ],

    'queue' => [
        'stream_prefix' => 'sf:queue:',
        'consumer_group' => 'sf_workers',
        'retry' => [
            'max_attempts' => 3,
            'backoff' => [1, 5, 30], // seconds
        ],
        'dlq_prefix' => 'sf:dlq:',
    ],

    'events' => [
        'stream_prefix' => 'sf:events:',
        'consumer_group' => 'sf_consumers',
    ],

    'observability' => [
        'log_level' => 'info', // debug, info, warning, error
    ],
];
