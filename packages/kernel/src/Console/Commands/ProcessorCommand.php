<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Events\EventConsumer;
use Fabriq\Queue\Consumer;
use Fabriq\Queue\IdempotencyStore;
use Fabriq\Storage\DbManager;

final class ProcessorCommand extends Command
{
    public function getName(): string
    {
        return 'processor';
    }

    public function getDescription(): string
    {
        return 'Start queue/event processor';
    }

    public function handle(array $args, array $options): int
    {
        echo "╔══════════════════════════════════════╗\n";
        echo "║              Fabriq                  ║\n";
        echo "║         Queue Processor              ║\n";
        echo "╚══════════════════════════════════════╝\n\n";

        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $dbManager = new DbManager();

        \Swoole\Coroutine\run(function () use ($config, $dbManager, $basePath) {
            $dbManager->boot($config);

            $idempotencyStore = new IdempotencyStore($dbManager);
            $consumer = new Consumer(
                db: $dbManager,
                idempotencyStore: $idempotencyStore,
                consumerGroup: $config->get('queue.consumer_group', 'sf_workers'),
                maxAttempts: (int) $config->get('queue.retry.max_attempts', 3),
                backoff: $config->get('queue.retry.backoff', [1, 5, 30]),
            );

            $processorBootstrap = $basePath . '/bootstrap/processor.php';
            if (is_file($processorBootstrap)) {
                (require $processorBootstrap)($consumer, $dbManager);
            }

            $eventConsumer = new EventConsumer(
                db: $dbManager,
                consumerGroup: $config->get('events.consumer_group', 'sf_consumers'),
            );

            $eventBootstrap = $basePath . '/bootstrap/events.php';
            if (is_file($eventBootstrap)) {
                (require $eventBootstrap)($eventConsumer, $dbManager);
            }

            echo "[Fabriq] Processor starting on queue 'default'...\n";

            \Swoole\Coroutine::create(function () use ($eventConsumer) {
                $eventConsumer->consume();
            });

            $consumer->consume('default');
        });

        return 0;
    }
}
