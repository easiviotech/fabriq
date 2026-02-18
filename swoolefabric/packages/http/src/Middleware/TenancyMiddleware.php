<?php

declare(strict_types=1);

namespace SwooleFabric\Http\Middleware;

use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Tenancy\TenantResolver;

/**
 * Tenancy middleware.
 *
 * Resolves the tenant from the request using TenantResolver.
 * Sets tenant_id on Context. Rejects with 400 if no tenant found.
 *
 * Skips paths that don't require tenancy (e.g. /health).
 */
final class TenancyMiddleware
{
    /** @var list<string> Paths that skip tenancy */
    private array $globalPaths = [
        '/health',
        '/metrics',
    ];

    public function __construct(
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * Add a global path that skips tenancy enforcement.
     */
    public function addGlobalPath(string $path): void
    {
        $this->globalPaths[] = $path;
    }

    public function __invoke(Request $request, Response $response, callable $next): void
    {
        // Skip tenancy for global paths
        $uri = $request->uri();
        foreach ($this->globalPaths as $path) {
            if ($uri === $path || str_starts_with($uri, $path . '/')) {
                $next();
                return;
            }
        }

        try {
            $tenant = $this->resolver->resolve($request->swoole());

            Context::setTenantId($tenant->id);
            Context::setExtra('tenant', $tenant->toArray());

            $next();
        } catch (\Throwable $e) {
            $response->error('Tenant resolution failed: ' . $e->getMessage(), 400);
        }
    }
}

