<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Orm\Schema\MigrationRunner;
use Fabriq\Orm\TenantDbRouter;
use Fabriq\Storage\DbManager;

final class MigrateCommand extends Command
{
    public function getName(): string
    {
        return 'migrate';
    }

    public function getDescription(): string
    {
        return 'Run database migrations';
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
                $ran = $runner->migrate();

                if (empty($ran)) {
                    $this->info('Nothing to migrate.');
                } else {
                    foreach ($ran as $migration) {
                        $this->info("Migrated: {$migration}");
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Migration failed: {$e->getMessage()}");
                $exitCode = 1;
            }
        });

        return $exitCode;
    }
}
