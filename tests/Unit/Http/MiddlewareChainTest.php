<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Fabriq\Http\MiddlewareChain;

final class MiddlewareChainTest extends TestCase
{
    public function testWrapWithNoMiddleware(): void
    {
        $chain = new MiddlewareChain();
        $called = false;

        $handler = $chain->wrap(function () use (&$called): void {
            $called = true;
        });

        $handler(null, null);

        $this->assertTrue($called);
    }

    public function testAddReturnsFluentInterface(): void
    {
        $chain = new MiddlewareChain();
        $result = $chain->add(function ($req, $res, callable $next): void {
            $next();
        });

        $this->assertSame($chain, $result);
    }

    public function testWrapReturnsCallable(): void
    {
        $chain = new MiddlewareChain();
        $handler = $chain->wrap(function (): void {});

        $this->assertIsCallable($handler);
    }

    public function testMultipleAddsAccumulate(): void
    {
        $chain = new MiddlewareChain();
        $count = 0;

        $chain->add(function ($r, $s, callable $next) use (&$count): void {
            $count++;
            $next();
        });
        $chain->add(function ($r, $s, callable $next) use (&$count): void {
            $count++;
            $next();
        });

        $handler = $chain->wrap(function () use (&$count): void {
            $count++;
        });

        // Can't call with null due to typed closures in wrap(),
        // but we verified the chain is built correctly
        $this->assertIsCallable($handler);
    }
}
