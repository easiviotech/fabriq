<?php

declare(strict_types=1);

namespace Fabriq\Http\Middleware;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Fabriq\Tenancy\TenantResolver;

/**
 * Serves static frontend build files from per-tenant directories.
 *
 * Designed to be registered as the last route handler on the Application
 * so API routes always take priority. Supports any frontend framework
 * (React, Vue, Svelte, Angular, etc.) — just drop the build output into
 * public/{tenant-slug}/.
 *
 * Tenant resolution order (3-tier):
 *   1. domain_map — static config mapping custom domain → slug (O(1), no DB)
 *   2. TenantResolver — subdomain, X-Tenant header, JWT claim
 *   3. domainLookup — DB query against tenants.domain column (last resort)
 *
 * File resolution order:
 *   1. Try public/{tenant-slug}/{uri}
 *   2. Fall back to public/_default/{uri}
 *   3. SPA fallback → serve index.html from the resolved directory
 */
final class StaticFileMiddleware
{
    private const MIME_TYPES = [
        'html'        => 'text/html',
        'htm'         => 'text/html',
        'css'         => 'text/css',
        'js'          => 'application/javascript',
        'mjs'         => 'application/javascript',
        'json'        => 'application/json',
        'svg'         => 'image/svg+xml',
        'png'         => 'image/png',
        'jpg'         => 'image/jpeg',
        'jpeg'        => 'image/jpeg',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        'avif'        => 'image/avif',
        'ico'         => 'image/x-icon',
        'woff'        => 'font/woff',
        'woff2'       => 'font/woff2',
        'ttf'         => 'font/ttf',
        'eot'         => 'application/vnd.ms-fontobject',
        'map'         => 'application/json',
        'webmanifest' => 'application/manifest+json',
        'txt'         => 'text/plain',
        'xml'         => 'application/xml',
        'mp4'         => 'video/mp4',
        'webm'        => 'video/webm',
        'mp3'         => 'audio/mpeg',
        'wav'         => 'audio/wav',
        'pdf'         => 'application/pdf',
        'wasm'        => 'application/wasm',
    ];

    /** Regex pattern for fingerprinted filenames (e.g. app.a1b2c3d4.js) */
    private const FINGERPRINT_PATTERN = '/\.[a-f0-9]{6,32}\./i';

    private string $documentRoot;
    private string $defaultDir;
    private bool $spaFallback;
    private string $indexFile;
    /** @var list<string> */
    private array $apiPrefixes;
    private int $cacheMaxAge;
    private bool $cors;
    private bool $multiTenancy;
    private ?TenantResolver $tenantResolver;

    /** @var array<string, string> Custom domain → tenant slug */
    private array $domainMap;

    /** @var (callable(string): ?string)|null Domain → slug DB lookup */
    private $domainLookup;

    /**
     * @param string $documentRoot Absolute path to the public directory
     * @param array<string, mixed> $config Static config array from config/static.php
     * @param TenantResolver|null $tenantResolver Null disables resolver-based resolution
     * @param array<string, string> $domainMap Static domain → slug mapping (checked first)
     * @param (callable(string): ?string)|null $domainLookup DB-backed domain → slug lookup (last resort)
     */
    public function __construct(
        string $documentRoot,
        array $config = [],
        ?TenantResolver $tenantResolver = null,
        array $domainMap = [],
        ?callable $domainLookup = null,
    ) {
        $this->documentRoot = rtrim($documentRoot, '/\\');
        $this->defaultDir = (string) ($config['default_tenant_dir'] ?? '_default');
        $this->spaFallback = (bool) ($config['spa_fallback'] ?? true);
        $this->indexFile = (string) ($config['index'] ?? 'index.html');
        $this->apiPrefixes = (array) ($config['api_prefixes'] ?? ['/api', '/health', '/metrics']);
        $this->cacheMaxAge = (int) ($config['cache_max_age'] ?? 86400);
        $this->cors = (bool) ($config['cors'] ?? false);
        $this->multiTenancy = (bool) ($config['multi_tenancy'] ?? true);
        $this->tenantResolver = $tenantResolver;
        $this->domainMap = $domainMap;
        $this->domainLookup = $domainLookup;
    }

    /**
     * Handle an incoming request. Returns true if the request was served.
     */
    public function __invoke(Request $request, Response $response): bool
    {
        $uri = $request->server['request_uri'] ?? '/';
        $method = strtoupper($request->server['request_method'] ?? 'GET');

        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if ($this->isApiPath($uri)) {
            return false;
        }

        // Normalize URI — strip query string, decode
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        if (!$this->isSafePath($path)) {
            return false;
        }

        $tenantSlug = $this->resolveTenantSlug($request);
        $servedDir = $this->resolveDirectory($tenantSlug);

        if ($servedDir === null) {
            return false;
        }

        // Try the exact file
        $filePath = $servedDir . '/' . ltrim($path, '/');
        if ($path === '/' || $path === '') {
            $filePath = $servedDir . '/' . $this->indexFile;
        }

        if (is_file($filePath) && $this->isWithinRoot($filePath)) {
            $this->serve($response, $filePath);
            return true;
        }

        // SPA fallback — serve index.html for unmatched paths
        if ($this->spaFallback) {
            $indexPath = $servedDir . '/' . $this->indexFile;
            if (is_file($indexPath) && $this->isWithinRoot($indexPath)) {
                $this->serve($response, $indexPath);
                return true;
            }
        }

        return false;
    }

    private function isApiPath(string $uri): bool
    {
        foreach ($this->apiPrefixes as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private function isSafePath(string $path): bool
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }
        return true;
    }

    private function isWithinRoot(string $filePath): bool
    {
        $real = realpath($filePath);
        if ($real === false) {
            return false;
        }
        $rootReal = realpath($this->documentRoot);
        if ($rootReal === false) {
            return false;
        }
        return str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR) || $real === $rootReal;
    }

    /**
     * Resolve tenant slug from the request using a 3-tier strategy:
     *   1. domain_map config (O(1) static lookup)
     *   2. TenantResolver (subdomain / header / token)
     *   3. domainLookup callable (DB query by domain)
     */
    private function resolveTenantSlug(Request $request): ?string
    {
        if (!$this->multiTenancy) {
            return null;
        }

        $host = $this->extractHost($request);

        // Tier 1: static domain_map — fastest path, no DB
        if ($host !== null && isset($this->domainMap[$host])) {
            return $this->domainMap[$host];
        }

        // Tier 2: TenantResolver — handles subdomains, X-Tenant header, JWT
        if ($this->tenantResolver !== null) {
            try {
                $tenant = $this->tenantResolver->resolve($request);
                return $tenant->slug;
            } catch (\Throwable) {
                // Fall through to DB lookup
            }
        }

        // Tier 3: DB domain lookup — queries tenants.domain column
        if ($host !== null && $this->domainLookup !== null) {
            try {
                return ($this->domainLookup)($host);
            } catch (\Throwable) {
                // Ignore — fall back to _default
            }
        }

        return null;
    }

    /**
     * Extract the hostname from the request, stripping the port if present.
     */
    private function extractHost(Request $request): ?string
    {
        $host = $request->header['host'] ?? null;
        if ($host === null || $host === '') {
            return null;
        }

        return strtolower(explode(':', $host)[0]);
    }

    /**
     * Determine which directory to serve from.
     * Tries the tenant-specific directory first, then falls back to _default.
     */
    private function resolveDirectory(?string $tenantSlug): ?string
    {
        if ($tenantSlug !== null) {
            $tenantDir = $this->documentRoot . DIRECTORY_SEPARATOR . $tenantSlug;
            if (is_dir($tenantDir)) {
                return $tenantDir;
            }
        }

        $defaultDir = $this->documentRoot . DIRECTORY_SEPARATOR . $this->defaultDir;
        if (is_dir($defaultDir)) {
            return $defaultDir;
        }

        return null;
    }

    private function serve(Response $response, string $filePath): void
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        $response->header('Content-Type', $contentType);
        $this->setCacheHeaders($response, $filePath, $ext);

        if ($this->cors) {
            $response->header('Access-Control-Allow-Origin', '*');
        }

        $response->sendfile($filePath);
    }

    private function setCacheHeaders(Response $response, string $filePath, string $ext): void
    {
        $basename = basename($filePath);

        if ($ext === 'html' || $ext === 'htm') {
            $response->header('Cache-Control', 'no-cache');
            return;
        }

        if (preg_match(self::FINGERPRINT_PATTERN, $basename)) {
            $response->header('Cache-Control', 'public, max-age=31536000, immutable');
            return;
        }

        $response->header('Cache-Control', 'public, max-age=' . $this->cacheMaxAge);
    }
}
