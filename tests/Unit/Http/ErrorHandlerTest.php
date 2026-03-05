<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use Fabriq\Http\ErrorHandler;
use Fabriq\Http\Exception\HttpException;
use Fabriq\Http\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    private ErrorHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new ErrorHandler(debug: true);
    }

    public function testResolveStatusCodeFromHttpException(): void
    {
        $method = new \ReflectionMethod($this->handler, 'resolveStatusCode');

        $this->assertSame(404, $method->invoke($this->handler, new NotFoundException()));
        $this->assertSame(403, $method->invoke($this->handler, new HttpException(403, 'Forbidden')));
    }

    public function testResolveStatusCodeFromGenericException(): void
    {
        $method = new \ReflectionMethod($this->handler, 'resolveStatusCode');

        $this->assertSame(500, $method->invoke($this->handler, new \RuntimeException('fail')));
        $this->assertSame(500, $method->invoke($this->handler, new \InvalidArgumentException('bad')));
    }

    public function testStatusText(): void
    {
        $method = new \ReflectionMethod($this->handler, 'statusText');

        $this->assertSame('Bad Request', $method->invoke($this->handler, 400));
        $this->assertSame('Unauthorized', $method->invoke($this->handler, 401));
        $this->assertSame('Forbidden', $method->invoke($this->handler, 403));
        $this->assertSame('Not Found', $method->invoke($this->handler, 404));
        $this->assertSame('Method Not Allowed', $method->invoke($this->handler, 405));
        $this->assertSame('Unprocessable Entity', $method->invoke($this->handler, 422));
        $this->assertSame('Too Many Requests', $method->invoke($this->handler, 429));
        $this->assertSame('Internal Server Error', $method->invoke($this->handler, 500));
        $this->assertSame('Bad Gateway', $method->invoke($this->handler, 502));
        $this->assertSame('Service Unavailable', $method->invoke($this->handler, 503));
        $this->assertSame('Error', $method->invoke($this->handler, 418));
    }

    public function testExtractCodeSnippet(): void
    {
        $method = new \ReflectionMethod($this->handler, 'extractCodeSnippet');
        $file = dirname(__DIR__, 3) . '/packages/http/src/ErrorHandler.php';

        $snippet = $method->invoke($this->handler, $file, 10, 3);

        $this->assertIsString($snippet);
        $this->assertStringContainsString('class="line-num"', $snippet);
        $this->assertStringContainsString('line-highlight', $snippet);
    }

    public function testExtractCodeSnippetNonexistentFile(): void
    {
        $method = new \ReflectionMethod($this->handler, 'extractCodeSnippet');

        $snippet = $method->invoke($this->handler, '/no/such/file.php', 1);

        $this->assertStringContainsString('not readable', $snippet);
    }

    public function testResolveHeaders(): void
    {
        $method = new \ReflectionMethod($this->handler, 'resolveHeaders');

        $headers = ['X-Custom' => 'value'];
        $exception = new HttpException(400, 'Bad', null, $headers);

        $this->assertSame($headers, $method->invoke($this->handler, $exception));
        $this->assertSame([], $method->invoke($this->handler, new \RuntimeException('generic')));
    }
}
