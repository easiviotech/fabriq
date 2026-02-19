<?php

declare(strict_types=1);

namespace Fabriq\Http\Middleware;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Kernel\Context;
use Fabriq\Security\JwtAuthenticator;
use Fabriq\Security\ApiKeyAuthenticator;

/**
 * Authentication middleware.
 *
 * Extracts credentials from the Authorization header (JWT or API key),
 * authenticates, and sets actor_id + related claims on Context.
 *
 * Skips paths that don't require auth (e.g. /health, /api/tenants bootstrap).
 */
final class AuthMiddleware
{
    /** @var list<string> Paths that skip authentication */
    private array $publicPaths = [
        '/health',
        '/metrics',
    ];

    public function __construct(
        private readonly JwtAuthenticator $jwt,
        private readonly ApiKeyAuthenticator $apiKey,
    ) {}

    /**
     * Add a public path that skips auth.
     */
    public function addPublicPath(string $path): void
    {
        $this->publicPaths[] = $path;
    }

    public function __invoke(Request $request, Response $response, callable $next): void
    {
        // Skip auth for public paths
        $uri = $request->uri();
        foreach ($this->publicPaths as $path) {
            if ($uri === $path || str_starts_with($uri, $path . '/')) {
                $next();
                return;
            }
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            $response->error('Authentication required', 401);
            return;
        }

        // Try JWT first
        $claims = $this->jwt->decode($token);
        if ($claims !== null) {
            Context::setActorId((string) ($claims['sub'] ?? $claims['actor_id'] ?? ''));

            // Store tenant_id from token for TenantResolver's 'token' strategy
            if (isset($claims['tenant_id'])) {
                Context::setExtra('token_tenant_id', (string) $claims['tenant_id']);
            }

            // Store roles for PolicyMiddleware
            if (isset($claims['roles'])) {
                Context::setExtra('roles', (array) $claims['roles']);
            }

            $next();
            return;
        }

        // Try API key
        $apiKeyResult = $this->apiKey->authenticate($token);
        if ($apiKeyResult !== null) {
            Context::setActorId('apikey:' . ($apiKeyResult['id'] ?? 'unknown'));

            if (isset($apiKeyResult['tenant_id'])) {
                Context::setExtra('token_tenant_id', (string) $apiKeyResult['tenant_id']);
                Context::setTenantId((string) $apiKeyResult['tenant_id']);
            }

            if (isset($apiKeyResult['scopes'])) {
                Context::setExtra('scopes', $apiKeyResult['scopes']);
            }

            $next();
            return;
        }

        $response->error('Invalid credentials', 401);
    }
}

