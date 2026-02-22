<?php

declare(strict_types=1);

namespace Fabriq\Http;

use Swoole\Http\Response as SwooleResponse;

/**
 * Wrapper around Swoole\Http\Response with fluent helpers.
 *
 * Provides chainable methods for setting status, headers, and sending
 * JSON or plain text responses.
 */
final class Response
{
    private bool $sent = false;

    public function __construct(
        private readonly SwooleResponse $swoole,
    ) {}

    /**
     * Get the underlying Swoole response.
     */
    public function swoole(): SwooleResponse
    {
        return $this->swoole;
    }

    /**
     * Set HTTP status code.
     */
    public function status(int $code): self
    {
        $this->swoole->status($code);
        return $this;
    }

    /**
     * Set a response header.
     */
    public function header(string $name, string $value): self
    {
        $this->swoole->header($name, $value);
        return $this;
    }

    /**
     * Send a JSON response.
     *
     * @param mixed $data Data to JSON-encode
     * @param int $statusCode HTTP status (default 200)
     */
    public function json(mixed $data, int $statusCode = 200): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $this->swoole->status($statusCode);
        $this->swoole->header('Content-Type', 'application/json');
        $this->swoole->end(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Send an error JSON response.
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status (default 400)
     * @param array<string, mixed> $extra Additional fields
     */
    public function error(string $message, int $statusCode = 400, array $extra = []): void
    {
        $this->json(array_merge(['error' => $message], $extra), $statusCode);
    }

    /**
     * Send a plain text response.
     */
    public function text(string $body, int $statusCode = 200): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $this->swoole->status($statusCode);
        $this->swoole->header('Content-Type', 'text/plain');
        $this->swoole->end($body);
    }

    /**
     * Send an empty response with status code.
     */
    public function noContent(int $statusCode = 204): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $this->swoole->status($statusCode);
        $this->swoole->end('');
    }

    /**
     * Send a file response using Swoole's zero-copy sendfile.
     *
     * @param string $filePath Absolute path to the file
     * @param string $contentType MIME content type
     */
    public function sendFile(string $filePath, string $contentType, int $statusCode = 200): void
    {
        if ($this->sent) {
            return;
        }
        $this->sent = true;

        $this->swoole->status($statusCode);
        $this->swoole->header('Content-Type', $contentType);
        $this->swoole->sendfile($filePath);
    }

    /**
     * Check if the response has already been sent.
     */
    public function isSent(): bool
    {
        return $this->sent;
    }
}

