<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Console\Command;

final class ServeCommand extends Command
{
    public function getName(): string
    {
        return 'serve';
    }

    public function getDescription(): string
    {
        return 'Start HTTP + WebSocket server';
    }

    public function handle(array $args, array $options): int
    {
        echo "╔══════════════════════════════════════╗\n";
        echo "║              Fabriq                  ║\n";
        echo "║   Unified Swoole Runtime Platform    ║\n";
        echo "╚══════════════════════════════════════╝\n\n";

        if ($this->app === null) {
            $this->error('Application not bootstrapped.');
            return 1;
        }

        $this->app->run();
        return 0;
    }
}
