<?php

declare(strict_types=1);

/**
 * Swoole server configuration.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Server Host & Port
    |--------------------------------------------------------------------------
    */
    'host' => '0.0.0.0',
    'port' => 8000,

    /*
    |--------------------------------------------------------------------------
    | Worker Configuration
    |--------------------------------------------------------------------------
    */
    'workers' => 2,
    'task_workers' => 2,

    /*
    |--------------------------------------------------------------------------
    | UDP Configuration
    |--------------------------------------------------------------------------
    | Enable a UDP listener on a separate port for low-latency game state
    | and media signaling. The UDP port is added to the same Swoole process
    | via addListener() — no extra server needed.
    */
    'udp_enabled' => false,
    'udp_port' => 8001,

    /*
    |--------------------------------------------------------------------------
    | Swoole Log Level
    |--------------------------------------------------------------------------
    | 0 = DEBUG, 1 = TRACE, 2 = INFO, 3 = NOTICE, 4 = WARNING, 5 = ERROR
    */
    'log_level' => 4, // SWOOLE_LOG_WARNING
];