<?php

declare(strict_types=1);

namespace Fabriq\Http;

/**
 * Sequential middleware pipeline.
 *
 * Each middleware is a callable: fn(Request, Response, callable $next): void
 * The $next callable invokes the next middleware in the chain (or the final handler).
 */
final class MiddlewareChain
{
    /** @var list<callable(Request, Response, callable): void> */
    private array $middlewares = [];

    /**
     * Add a middleware to the chain.
     *
     * @param callable(Request, Response, callable): void $middleware
     */
    public function add(callable $middleware): self
    {
        $this->middlewares[] = $middleware;
        return $this;
    }

    /**
     * Wrap a final handler with all registered middlewares.
     *
     * @param callable(Request, Response): void $handler The innermost handler
     * @return callable(Request, Response): void          The wrapped handler
     */
    public function wrap(callable $handler): callable
    {
        $chain = $handler;

        // Build from inside out (last middleware wraps closest to handler)
        foreach (array_reverse($this->middlewares) as $middleware) {
            $next = $chain;
            $chain = function (Request $request, Response $response) use ($middleware, $next) {
                $middleware($request, $response, function () use ($next, $request, $response) {
                        $next($request, $response);
                    }
                    );
                };
        }

        return $chain;
    }
}
