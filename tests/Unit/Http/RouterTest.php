<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Fabriq\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesStaticRoute(): void
    {
        $router = new Router();
        $router->get('/api/users', fn() => 'list');

        $match = $router->match('GET', '/api/users');

        $this->assertNotNull($match);
        $this->assertEmpty($match['params']);
    }

    public function testReturnsNullForNoMatch(): void
    {
        $router = new Router();
        $router->get('/api/users', fn() => 'list');

        $this->assertNull($router->match('GET', '/api/rooms'));
    }

    public function testMatchesDynamicRoute(): void
    {
        $router = new Router();
        $router->get('/api/users/{id}', fn() => 'show');

        $match = $router->match('GET', '/api/users/abc-123');

        $this->assertNotNull($match);
        $this->assertSame('abc-123', $match['params']['id']);
    }

    public function testMatchesMultipleParams(): void
    {
        $router = new Router();
        $router->get('/api/rooms/{roomId}/messages/{messageId}', fn() => 'msg');

        $match = $router->match('GET', '/api/rooms/r1/messages/m2');

        $this->assertNotNull($match);
        $this->assertSame('r1', $match['params']['roomId']);
        $this->assertSame('m2', $match['params']['messageId']);
    }

    public function testMethodMismatchReturnsNull(): void
    {
        $router = new Router();
        $router->post('/api/users', fn() => 'create');

        $this->assertNull($router->match('GET', '/api/users'));
    }

    public function testPathExistsForDifferentMethod(): void
    {
        $router = new Router();
        $router->post('/api/users', fn() => 'create');

        $this->assertTrue($router->pathExists('/api/users'));
    }

    public function testShorthandMethods(): void
    {
        $router = new Router();
        $router->post('/p', fn() => 'post');
        $router->put('/u', fn() => 'put');
        $router->delete('/d', fn() => 'delete');
        $router->patch('/pa', fn() => 'patch');

        $this->assertNotNull($router->match('POST', '/p'));
        $this->assertNotNull($router->match('PUT', '/u'));
        $this->assertNotNull($router->match('DELETE', '/d'));
        $this->assertNotNull($router->match('PATCH', '/pa'));
    }
}
