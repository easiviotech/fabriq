<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Orm\Schema\MigrationRunner;
use Fabriq\Orm\TenantDbRouter;
use Fabriq\Storage\DbManager;

final class MigrateRollbackCommand extends Command
{
    public function getName(): string
    {
        return 'migrate:rollback';
    }

    public function getDescription(): string
    {
        return 'Rollback the last database migration batch';
    }

    public function handle(array $args, array $options): int
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $exitCode = 0;

        \Swoole\Coroutine\run(function () use ($config, $basePath, &$exitCode) {
            $dbManager = new DbManager();
            $dbManager->boot($config);

            try {
                $router = new TenantDbRouter($dbManager, $config);
                $runner = new MigrationRunner($router, $basePath . '/database/migrations');
                $rolledBack = $runner->rollback();

                if (empty($rolledBack)) {
                    $this->info('Nothing to roll back.');
                } else {
                    foreach ($rolledBack as $migration) {
                        $this->info("Rolled back: {$migration}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Rollback failed: {$e->getMessage()}");
                $exitCode = 1;
            }
        });

        return $exitCode;
    }
}
