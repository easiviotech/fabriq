<?php

declare(strict_types=1);

/**
 * Queue (Redis Streams) configuration.
 */
return [
    'stream_prefix' => 'sf:queue:',
    'consumer_group' => 'sf_workers',

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    */
    'retry' => [
        'max_attempts' => 3,
        'backoff' => [1, 5, 30], // seconds between retries
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queue
    |--------------------------------------------------------------------------
    */
    'dlq_prefix' => 'sf:dlq:',
];
