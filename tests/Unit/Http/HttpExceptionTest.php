<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use Fabriq\Http\Exception\ForbiddenException;
use Fabriq\Http\Exception\HttpException;
use Fabriq\Http\Exception\NotFoundException;
use Fabriq\Http\Exception\UnauthorizedException;
use Fabriq\Http\Exception\ValidationException;
use PHPUnit\Framework\TestCase;

final class HttpExceptionTest extends TestCase
{
    public function testHttpExceptionStatusCodeAndMessage(): void
    {
        $e = new HttpException(503, 'Service down');

        $this->assertSame(503, $e->getStatusCode());
        $this->assertSame('Service down', $e->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testHttpExceptionHeaders(): void
    {
        $headers = ['Retry-After' => '120', 'X-RateLimit-Reset' => '1700000000'];
        $e = new HttpException(429, 'Too many requests', null, $headers);

        $this->assertSame($headers, $e->getHeaders());
        $this->assertSame(429, $e->getStatusCode());
    }

    public function testHttpExceptionDefaultHeaders(): void
    {
        $e = new HttpException(500, 'Internal error');

        $this->assertSame([], $e->getHeaders());
    }

    public function testHttpExceptionPreviousException(): void
    {
        $prev = new \LogicException('root cause');
        $e = new HttpException(500, 'Wrapped', $prev);

        $this->assertSame($prev, $e->getPrevious());
    }

    public function testNotFoundException(): void
    {
        $e = new NotFoundException();

        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('Not Found', $e->getMessage());
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testNotFoundExceptionCustomMessage(): void
    {
        $e = new NotFoundException('User not found');

        $this->assertSame(404, $e->getStatusCode());
        $this->assertSame('User not found', $e->getMessage());
    }

    public function testValidationException(): void
    {
        $errors = ['email' => 'Required', 'name' => 'Too short'];
        $e = new ValidationException($errors);

        $this->assertSame(422, $e->getStatusCode());
        $this->assertSame('Validation failed', $e->getMessage());
        $this->assertSame($errors, $e->getErrors());
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testValidationExceptionCustomMessage(): void
    {
        $e = new ValidationException(['field' => 'bad'], 'Invalid input');

        $this->assertSame('Invalid input', $e->getMessage());
        $this->assertSame(['field' => 'bad'], $e->getErrors());
    }

    public function testUnauthorizedException(): void
    {
        $e = new UnauthorizedException();

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Unauthorized', $e->getMessage());
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testForbiddenException(): void
    {
        $e = new ForbiddenException();

        $this->assertSame(403, $e->getStatusCode());
        $this->assertSame('Forbidden', $e->getMessage());
        $this->assertInstanceOf(HttpException::class, $e);
    }

    public function testForbiddenExceptionCustomMessage(): void
    {
        $e = new ForbiddenException('Insufficient privileges');

        $this->assertSame(403, $e->getStatusCode());
        $this->assertSame('Insufficient privileges', $e->getMessage());
    }
}
