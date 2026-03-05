<?php

declare(strict_types=1);

/**
 * Static file serving & frontend build automation.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable Static File Serving
    |--------------------------------------------------------------------------
    |
    | When enabled, Fabriq serves frontend build files (HTML, JS, CSS, images)
    | directly through the Swoole HTTP server. Supports any frontend framework
    | (React, Vue, Svelte, Angular, etc.) — just drop the build output into
    | the configured document root.
    |
    */
    'enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Document Root
    |--------------------------------------------------------------------------
    |
    | Directory containing frontend build files, relative to the project root.
    |
    */
    'document_root' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When true, files are served from per-tenant subdirectories:
    |   public/{tenant-slug}/index.html
    |
    | Falls back to the default tenant directory when a tenant's directory
    | does not exist.
    |
    */
    'multi_tenancy' => true,

    /*
    |--------------------------------------------------------------------------
    | Default Tenant Directory
    |--------------------------------------------------------------------------
    |
    | Subdirectory used as fallback when the resolved tenant has no dedicated
    | build, or when tenant resolution fails (e.g. login/marketing pages).
    |
    */
    'default_tenant_dir' => '_default',

    /*
    |--------------------------------------------------------------------------
    | SPA Fallback
    |--------------------------------------------------------------------------
    |
    | When true, requests that don't match a static file are served the
    | index file instead. Required for client-side routing (React Router,
    | Vue Router, etc.).
    |
    */
    'spa_fallback' => true,

    /*
    |--------------------------------------------------------------------------
    | Index File
    |--------------------------------------------------------------------------
    |
    | The SPA entry point file name.
    |
    */
    'index' => 'index.html',

    /*
    |--------------------------------------------------------------------------
    | API Prefixes
    |--------------------------------------------------------------------------
    |
    | Request paths starting with any of these prefixes are never served as
    | static files — they are left to the API route handlers.
    |
    */
    'api_prefixes' => ['/api', '/health', '/metrics'],

    /*
    |--------------------------------------------------------------------------
    | Cache Max-Age
    |--------------------------------------------------------------------------
    |
    | Cache-Control max-age in seconds for non-fingerprinted static assets.
    | Fingerprinted files (containing a hash in the filename) automatically
    | receive immutable/1-year caching regardless of this setting.
    | HTML files always receive no-cache.
    |
    */
    'cache_max_age' => 86400,

    /*
    |--------------------------------------------------------------------------
    | CORS Headers
    |--------------------------------------------------------------------------
    |
    | When true, Access-Control-Allow-Origin: * is added to static responses.
    |
    */
    'cors' => false,

    /*
    |--------------------------------------------------------------------------
    | Domain Map
    |--------------------------------------------------------------------------
    |
    | Static mapping of custom domains to tenant slugs. Checked first on
    | every request for O(1) lookup speed — no database query required.
    |
    | For tenants NOT listed here, Fabriq falls back to the TenantResolver
    | (subdomain / X-Tenant header / JWT) and then to a database lookup
    | against the tenants.domain column.
    |
    | Format: 'custom-domain.com' => 'tenant-slug'
    |
    */
    'domain_map' => [
        // 'dashboard.acme.com' => 'acme',
        // 'app.globex.io'      => 'globex',
    ],

    /*
    |--------------------------------------------------------------------------
    | Build Automation
    |--------------------------------------------------------------------------
    |
    | Settings for the built-in CI/CD pipeline that clones tenant Git
    | repositories, runs the build command, and deploys the output.
    |
    */
    'build' => [
        'workspace'       => 'storage/builds',
        'default_command' => 'npm install && npm run build',
        'default_output'  => 'dist',
        'webhook_secret'  => '',
        'timeout'         => 300,
    ],
];