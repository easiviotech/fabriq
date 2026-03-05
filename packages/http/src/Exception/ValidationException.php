<?php

declare(strict_types=1);

namespace Fabriq\Http\Exception;

class ValidationException extends HttpException
{
    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors Field-level validation errors
     */
    public function __construct(array $errors = [], string $message = 'Validation failed', ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct(422, $message, $previous);
    }

    /**
     * @return array<string, string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
