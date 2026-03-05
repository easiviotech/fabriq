<?php

declare(strict_types=1);

/**
 * Multi-tenancy configuration.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Resolver Chain
    |--------------------------------------------------------------------------
    |
    | Order in which tenant resolution strategies are attempted.
    | Supported: 'host', 'header', 'token'
    |
    */
    'resolver_chain' => ['host', 'header', 'token'],

    /*
    |--------------------------------------------------------------------------
    | Tenant Config Cache TTL
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => 300, // seconds
];
