<?php

declare(strict_types=1);

/**
 * Authentication & authorization configuration.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | JWT Configuration
    |--------------------------------------------------------------------------
    */
    'jwt' => [
        'secret' => '', // Set via env or override config
        'algorithm' => 'HS256',
        'ttl' => 3600, // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Roles & Permissions
    |--------------------------------------------------------------------------
    |
    | Define RBAC roles and their allowed permissions.
    | Format: 'role_name' => ['resource:action', ...]
    |
    */
    'roles' => [
        'admin' => ['*:*'],
        'member' => [
            'rooms:create',
            'rooms:read',
            'rooms:join',
            'messages:create',
            'messages:read',
        ],
        'viewer' => [
            'rooms:read',
            'messages:read',
        ],
    ],
];
