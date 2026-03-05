<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel\Console;

use PHPUnit\Framework\TestCase;
use Fabriq\Kernel\Console\Command;
use Fabriq\Kernel\Console\CommandRegistry;
use Fabriq\Kernel\Console\Kernel;

final class KernelTest extends TestCase
{
    private CommandRegistry $registry;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->registry = new CommandRegistry();
        $this->kernel = new Kernel($this->registry);
    }

    public function testRunWithUnknownCommandReturnsOne(): void
    {
        ob_start();
        $exit = $this->kernel->run(['fabriq', 'does:not:exist']);
        ob_end_clean();

        $this->assertSame(1, $exit);
    }

    public function testRunWithKnownCommand(): void
    {
        $command = new class extends Command {
            public function getName(): string { return 'test:ok'; }
            public function getDescription(): string { return 'Returns zero'; }
            public function handle(array $args, array $options): int { return 0; }
        };

        $this->registry->register($command);

        ob_start();
        $exit = $this->kernel->run(['fabriq', 'test:ok']);
        ob_end_clean();

        $this->assertSame(0, $exit);
    }

    public function testRunHelp(): void
    {
        ob_start();
        $exit = $this->kernel->run(['fabriq', 'help']);
        ob_end_clean();

        $this->assertSame(0, $exit);
    }

    public function testArgumentParsing(): void
    {
        $receivedArgs = [];
        $receivedOptions = [];

        $command = new class($receivedArgs, $receivedOptions) extends Command {
            public function __construct(private array &$args, private array &$opts) {}
            public function getName(): string { return 'test:parse'; }
            public function getDescription(): string { return 'Test parsing'; }

            public function handle(array $args, array $options): int
            {
                $this->args = $args;
                $this->opts = $options;
                return 0;
            }
        };

        $this->registry->register($command);

        ob_start();
        $exit = $this->kernel->run([
            'fabriq', 'test:parse', 'MyName', '--force', '--env=production', '-v',
        ]);
        ob_end_clean();

        $this->assertSame(0, $exit);
        $this->assertSame(['MyName'], $receivedArgs);
        $this->assertSame('true', $receivedOptions['force']);
        $this->assertSame('production', $receivedOptions['env']);
        $this->assertSame('true', $receivedOptions['v']);
    }
}
