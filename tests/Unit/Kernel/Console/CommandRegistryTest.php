<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel\Console;

use PHPUnit\Framework\TestCase;
use Fabriq\Kernel\Console\Command;
use Fabriq\Kernel\Console\CommandRegistry;

final class CommandRegistryTest extends TestCase
{
    private CommandRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CommandRegistry();
    }

    public function testRegisterAndResolve(): void
    {
        $command = $this->makeCommand('test:cmd', 'A test command');
        $this->registry->register($command);

        $resolved = $this->registry->resolve('test:cmd');
        $this->assertSame($command, $resolved);
    }

    public function testResolveUnknownReturnsNull(): void
    {
        $this->assertNull($this->registry->resolve('nonexistent:cmd'));
    }

    public function testHas(): void
    {
        $this->assertFalse($this->registry->has('test:cmd'));

        $this->registry->register($this->makeCommand('test:cmd', 'desc'));
        $this->assertTrue($this->registry->has('test:cmd'));
    }

    public function testAllReturnsSorted(): void
    {
        $this->registry->register($this->makeCommand('z:last', 'Last'));
        $this->registry->register($this->makeCommand('a:first', 'First'));
        $this->registry->register($this->makeCommand('m:middle', 'Middle'));

        $all = $this->registry->all();
        $names = array_keys($all);

        $this->assertSame(['a:first', 'm:middle', 'z:last'], $names);
    }

    private function makeCommand(string $name, string $description): Command
    {
        return new class($name, $description) extends Command {
            public function __construct(
                private readonly string $cmdName,
                private readonly string $cmdDescription,
            ) {}

            public function getName(): string
            {
                return $this->cmdName;
            }

            public function getDescription(): string
            {
                return $this->cmdDescription;
            }

            public function handle(array $args, array $options): int
            {
                return 0;
            }
        };
    }
}
