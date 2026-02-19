<?php

declare(strict_types=1);

namespace Fabriq\Http\Middleware;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Kernel\Context;
use Fabriq\Security\RateLimiter;

/**
 * Rate limiting middleware.
 *
 * Uses a Redis-based sliding window limiter keyed by tenant_id + client IP.
 * Returns 429 Too Many Requests when the limit is exceeded.
 */
final class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly int $maxRequests = 100,
        private readonly int $windowSeconds = 60,
    ) {}

    public function __invoke(Request $request, Response $response, callable $next): void
    {
        $tenantId = Context::tenantId() ?? 'global';
        $ip = $request->ip();
        $key = "rl:{$tenantId}:{$ip}";

        $result = $this->limiter->attempt($key, $this->maxRequests, $this->windowSeconds);

        // Set rate limit headers
        $response->header('X-RateLimit-Limit', (string) $this->maxRequests);
        $response->header('X-RateLimit-Remaining', (string) $result['remaining']);

        if (!$result['allowed']) {
            $response->header('Retry-After', (string) $result['retry_after']);
            $response->error('Too Many Requests', 429, [
                'retry_after' => $result['retry_after'],
            ]);
            return;
        }

        $next();
    }
}

