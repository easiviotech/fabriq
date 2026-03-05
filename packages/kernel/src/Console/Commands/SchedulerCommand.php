<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Queue\Scheduler;
use Fabriq\Storage\DbManager;

final class SchedulerCommand extends Command
{
    public function getName(): string
    {
        return 'scheduler';
    }

    public function getDescription(): string
    {
        return 'Start cron-like scheduler';
    }

    public function handle(array $args, array $options): int
    {
        echo "╔══════════════════════════════════════╗\n";
        echo "║              Fabriq                  ║\n";
        echo "║            Scheduler                 ║\n";
        echo "╚══════════════════════════════════════╝\n\n";

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $dbManager = new DbManager();

        \Swoole\Coroutine\run(function () use ($config, $dbManager, $basePath) {
            $dbManager->boot($config);

            $scheduler = new Scheduler(
                db: $dbManager,
                pollInterval: 1.0,
            );

            $schedBootstrap = $basePath . '/bootstrap/scheduler.php';
            if (is_file($schedBootstrap)) {
                (require $schedBootstrap)($scheduler, $dbManager);
            }

            echo "[Fabriq] Scheduler started (polling every 1s)...\n";
            $scheduler->start();

            while (true) {
                \Swoole\Coroutine::sleep(60.0);
            }
        });

        return 0;
    }
}
