<?php

declare(strict_types=1);

namespace SwooleFabric\Http;

use Swoole\Http\Request as SwooleRequest;

/**
 * Wrapper around Swoole\Http\Request with convenience helpers.
 *
 * Provides typed access to headers, JSON body, query params, and route params.
 */
final class Request
{
    /** @var array<string, string> Route params extracted by Router */
    private array $routeParams = [];

    public function __construct(
        private readonly SwooleRequest $swoole,
    ) {}

    /**
     * Get the underlying Swoole request.
     */
    public function swoole(): SwooleRequest
    {
        return $this->swoole;
    }

    /**
     * HTTP method (GET, POST, PUT, DELETE, PATCH).
     */
    public function method(): string
    {
        return strtoupper($this->swoole->server['request_method'] ?? 'GET');
    }

    /**
     * Request URI path.
     */
    public function uri(): string
    {
        return $this->swoole->server['request_uri'] ?? '/';
    }

    /**
     * Get a request header (case-insensitive).
     */
    public function header(string $name, ?string $default = null): ?string
    {
        $name = strtolower($name);
        return $this->swoole->header[$name] ?? $default;
    }

    /**
     * Get all headers.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->swoole->header ?? [];
    }

    /**
     * Get a query parameter.
     */
    public function query(string $key, ?string $default = null): ?string
    {
        return isset($this->swoole->get[$key]) ? (string) $this->swoole->get[$key] : $default;
    }

    /**
     * Get all query parameters.
     *
     * @return array<string, string>
     */
    public function queryAll(): array
    {
        return $this->swoole->get ?? [];
    }

    /**
     * Parse JSON body and return as associative array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $body = $this->swoole->rawContent();
        if ($body === false || $body === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get a value from JSON body.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->json()[$key] ?? $default;
    }

    /**
     * Get raw body string.
     */
    public function rawBody(): string
    {
        $body = $this->swoole->rawContent();
        return ($body === false) ? '' : $body;
    }

    /**
     * Set route parameters (called by Router after match).
     *
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * Get a route parameter.
     */
    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * Get all route parameters.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->routeParams;
    }

    /**
     * Get the Bearer token from Authorization header.
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth === null) {
            return null;
        }

        if (str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    /**
     * Get remote IP address.
     */
    public function ip(): string
    {
        return $this->swoole->server['remote_addr'] ?? '0.0.0.0';
    }
}

