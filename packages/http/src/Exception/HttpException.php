<?php

declare(strict_types=1);

namespace Fabriq\Http\Exception;

/**
 * Base HTTP exception with status code and optional headers.
 *
 * Throw from controllers or middleware to produce the correct
 * HTTP status code automatically via the ErrorHandler.
 */
class HttpException extends \RuntimeException
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $message = '',
        ?\Throwable $previous = null,
        array $headers = [],
    ) {
        $this->headers = $headers;
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
