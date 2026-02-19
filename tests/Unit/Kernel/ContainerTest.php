<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Fabriq\Kernel\Container;
use RuntimeException;

final class ContainerTest extends TestCase
{
    public function testBindAndMake(): void
    {
        $container = new Container();
        $container->bind('service', fn() => new \stdClass());

        $a = $container->make('service');
        $b = $container->make('service');

        $this->assertInstanceOf(\stdClass::class , $a);
        $this->assertNotSame($a, $b); // factory creates new each time
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton('service', fn() => new \stdClass());

        $a = $container->make('service');
        $b = $container->make('service');

        $this->assertSame($a, $b);
    }

    public function testInstanceRegistration(): void
    {
        $container = new Container();
        $obj = new \stdClass();
        $obj->name = 'test';
        $container->instance('service', $obj);

        $this->assertSame($obj, $container->make('service'));
    }

    public function testMakeThrowsOnMissing(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->make('nonexistent');
    }

    public function testHasReturnsTrueForBinding(): void
    {
        $container = new Container();
        $container->bind('service', fn() => 'value');

        $this->assertTrue($container->has('service'));
        $this->assertFalse($container->has('other'));
    }
}
