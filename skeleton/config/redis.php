<?php

declare(strict_types=1);

/**
 * Redis connection pool configuration.
 *
 * Used for queues (Redis Streams), events, pub/sub, rate limiting,
 * and idempotency checks.
 */
return [
    'host' => 'redis',
    'port' => 6379,
    'password' => '',
    'database' => 0,
    'pool' => [
        'max_size' => 20,
        'borrow_timeout' => 3.0,
        'idle_timeout' => 60.0,
    ],
];
