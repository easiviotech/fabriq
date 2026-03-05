<?php

declare(strict_types=1);

/**
 * ORM configuration.
 *
 * Controls tenant database routing, connection pool limits,
 * model defaults, and migration paths.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Default Timestamps
    |--------------------------------------------------------------------------
    |
    | When true, Model classes with HasTimestamps will automatically
    | manage created_at and updated_at columns.
    |
    */
    'timestamps' => true,

    /*
    |--------------------------------------------------------------------------
    | Migration Path
    |--------------------------------------------------------------------------
    |
    | Directory where migration files are stored.
    |
    */
    'migration_path' => 'database/migrations',

    /*
    |--------------------------------------------------------------------------
    | Migrations Table
    |--------------------------------------------------------------------------
    |
    | Name of the table used to track which migrations have been applied.
    |
    */
    'migration_table' => 'migrations',

    /*
    |--------------------------------------------------------------------------
    | Tenant Database Routing
    |--------------------------------------------------------------------------
    |
    | Controls how per-tenant database routing works.
    |
    | Strategies:
    |   - shared:       All tenants share the same app DB (default)
    |   - same_server:  Separate DB per tenant on the same MySQL server
    |   - dedicated:    Separate MySQL server per tenant
    |
    */
    'tenant_routing' => [
        // Default strategy when tenant has no explicit config
        'default_strategy' => 'shared',

        // Maximum number of dedicated tenant pools kept alive (LRU eviction)
        'max_dedicated_pools' => 50,

        // Pool settings for dynamically created dedicated tenant pools
        'dedicated_pool' => [
            'max_size'       => 10,
            'borrow_timeout' => 3.0,
            'idle_timeout'   => 120.0,
        ],
    ],
];
