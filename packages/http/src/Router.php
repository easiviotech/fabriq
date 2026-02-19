<?php

declare(strict_types=1);

namespace Fabriq\Http;

/**
 * Simple HTTP router — matches method + path to handlers.
 *
 * Supports static paths and basic parameter extraction (e.g. /users/{id}).
 */
final class Router
{
    /** @var array<string, array<string, array{handler: callable, params: list<string>}>> */
    private array $routes = [];

    /** @var array<string, array{pattern: string, handler: callable, params: list<string>}> */
    private array $dynamicRoutes = [];

    /**
     * Register a route.
     *
     * @param string   $method  HTTP method (GET, POST, PUT, DELETE, PATCH)
     * @param string   $path    Path pattern, e.g. /api/users/{id}
     * @param callable $handler fn(Request, Response, array $params): void
     */
    public function addRoute(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);

        // Check for dynamic segments
        if (str_contains($path, '{')) {
            $paramNames = [];
            $pattern = preg_replace_callback('/\{(\w+)\}/', function ($matches) use (&$paramNames) {
                $paramNames[] = $matches[1];
                return '([^/]+)';
            }, $path);

            $this->dynamicRoutes[] = [
                'method' => $method,
                'pattern' => '#^' . $pattern . '$#',
                'handler' => $handler,
                'params' => $paramNames,
            ];
        }
        else {
            $this->routes[$method][$path] = [
                'handler' => $handler,
                'params' => [],
            ];
        }
    }

    // ── Shorthand Methods ────────────────────────────────────────────

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Match a request to a route.
     *
     * @return array{handler: callable, params: array<string, string>}|null
     */
    public function match (string $method, string $path): ?array
    {
        $method = strtoupper($method);

        // 1. Try static routes first
        if (isset($this->routes[$method][$path])) {
            return [
                'handler' => $this->routes[$method][$path]['handler'],
                'params' => [],
            ];
        }

        // 2. Try dynamic routes
        foreach ($this->dynamicRoutes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches); // Remove full match
                $params = array_combine($route['params'], $matches);
                return [
                    'handler' => $route['handler'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    /**
     * Check if any route exists for the path (any method) — useful for 405 responses.
     */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $methodRoutes) {
            if (isset($methodRoutes[$path])) {
                return true;
            }
        }

        foreach ($this->dynamicRoutes as $route) {
            if (preg_match($route['pattern'], $path)) {
                return true;
            }
        }

        return false;
    }
}
