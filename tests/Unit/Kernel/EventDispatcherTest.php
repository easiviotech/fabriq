<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel;

use Fabriq\Kernel\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class EventDispatcherTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    public function testListenAndDispatch(): void
    {
        $called = false;
        $this->dispatcher->listen('test.event', function () use (&$called): void {
            $called = true;
        });

        $this->dispatcher->dispatch('test.event');

        $this->assertTrue($called);
    }

    public function testMultipleListeners(): void
    {
        $order = [];

        $this->dispatcher->listen('boot', function () use (&$order): void {
            $order[] = 'first';
        });
        $this->dispatcher->listen('boot', function () use (&$order): void {
            $order[] = 'second';
        });

        $this->dispatcher->dispatch('boot');

        $this->assertSame(['first', 'second'], $order);
    }

    public function testDispatchWithPayload(): void
    {
        $received = [];
        $this->dispatcher->listen('request.received', function (string $method, string $uri) use (&$received): void {
            $received = ['method' => $method, 'uri' => $uri];
        });

        $this->dispatcher->dispatch('request.received', ['POST', '/api/users']);

        $this->assertSame(['method' => 'POST', 'uri' => '/api/users'], $received);
    }

    public function testDispatchUnknownEventDoesNothing(): void
    {
        $this->dispatcher->dispatch('nonexistent.event', ['data']);

        $this->assertFalse($this->dispatcher->hasListeners('nonexistent.event'));
    }

    public function testHasListeners(): void
    {
        $this->assertFalse($this->dispatcher->hasListeners('some.event'));

        $this->dispatcher->listen('some.event', function (): void {});

        $this->assertTrue($this->dispatcher->hasListeners('some.event'));
    }

    public function testForgetEvent(): void
    {
        $this->dispatcher->listen('a', function (): void {});
        $this->dispatcher->listen('b', function (): void {});

        $this->dispatcher->forget('a');

        $this->assertFalse($this->dispatcher->hasListeners('a'));
        $this->assertTrue($this->dispatcher->hasListeners('b'));
    }

    public function testForgetAll(): void
    {
        $this->dispatcher->listen('a', function (): void {});
        $this->dispatcher->listen('b', function (): void {});

        $this->dispatcher->forget();

        $this->assertFalse($this->dispatcher->hasListeners('a'));
        $this->assertFalse($this->dispatcher->hasListeners('b'));
    }
}
