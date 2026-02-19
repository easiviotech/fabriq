<?php

declare(strict_types=1);

namespace Fabriq\Http\Middleware;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Kernel\Context;
use Fabriq\Security\PolicyEngine;

/**
 * Policy enforcement middleware.
 *
 * Checks RBAC + ABAC policies for the current route.
 * Requires actor_id and roles to be set on Context (by AuthMiddleware).
 *
 * Routes must declare required resource:action via route metadata.
 */
final class PolicyMiddleware
{
    /** @var array<string, array{resource: string, action: string}> path → policy */
    private array $routePolicies = [];

    public function __construct(
        private readonly PolicyEngine $engine,
    ) {}

    /**
     * Define a policy requirement for a route pattern.
     *
     * @param string $method HTTP method
     * @param string $path   Route path pattern
     * @param string $resource Policy resource
     * @param string $action   Policy action
     */
    public function protect(string $method, string $path, string $resource, string $action): void
    {
        $key = strtoupper($method) . ':' . $path;
        $this->routePolicies[$key] = ['resource' => $resource, 'action' => $action];
    }

    public function __invoke(Request $request, Response $response, callable $next): void
    {
        $method = $request->method();
        $uri = $request->uri();
        $key = "{$method}:{$uri}";

        // Check if this route has a policy requirement
        $policy = $this->routePolicies[$key] ?? $this->findDynamicPolicy($method, $uri);

        if ($policy === null) {
            // No policy defined for this route — allow through
            $next();
            return;
        }

        $actorId = Context::actorId() ?? '';
        $roles = Context::getExtra('roles', []);

        if (!is_array($roles)) {
            $roles = [];
        }

        $context = [
            'tenant_id' => Context::tenantId(),
            'ip' => $request->ip(),
            'method' => $method,
            'path' => $uri,
        ];

        $allowed = $this->engine->evaluate(
            $actorId,
            $roles,
            $policy['resource'],
            $policy['action'],
            $context,
        );

        if (!$allowed) {
            $response->error('Forbidden', 403, [
                'resource' => $policy['resource'],
                'action' => $policy['action'],
            ]);
            return;
        }

        $next();
    }

    /**
     * Try to match a dynamic route policy (prefix-based).
     *
     * @return array{resource: string, action: string}|null
     */
    private function findDynamicPolicy(string $method, string $uri): ?array
    {
        foreach ($this->routePolicies as $pattern => $policy) {
            [$pMethod, $pPath] = explode(':', $pattern, 2);
            if ($pMethod !== $method) {
                continue;
            }

            // Simple prefix match for parameterized routes
            $prefix = explode('{', $pPath)[0];
            if ($prefix !== $pPath && str_starts_with($uri, $prefix)) {
                return $policy;
            }
        }

        return null;
    }
}

