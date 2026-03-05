<?php

declare(strict_types=1);

namespace Fabriq\Kernel\Console\Commands;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Console\Command;
use Fabriq\Storage\DbManager;

final class SeedCommand extends Command
{
    public function getName(): string
    {
        return 'db:seed';
    }

    public function getDescription(): string
    {
        return 'Run database seeders';
    }

    public function handle(array $args, array $options): int
    {
        \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);

        $basePath = $this->basePath();
        $config = Config::fromDirectory($basePath . '/config');
        $seederClass = $options['class'] ?? 'Database\\Seeders\\DatabaseSeeder';
        $exitCode = 0;

        \Swoole\Coroutine\run(function () use ($config, $basePath, $seederClass, &$exitCode) {
            $dbManager = new DbManager();
            $dbManager->boot($config);

            if (!class_exists($seederClass)) {
                $seederFile = $basePath . '/database/seeders/' . class_basename_seed($seederClass) . '.php';
                if (is_file($seederFile)) {
                    require_once $seederFile;
                }
            }

            if (!class_exists($seederClass)) {
                $this->error("Seeder class not found: {$seederClass}");
                $exitCode = 1;
                return;
            }

            try {
                $this->info("Seeding: {$seederClass}");
                $seeder = new $seederClass();
                $seeder->run();
                $this->info('Database seeding completed.');
            } catch (\Throwable $e) {
                $this->error("Seeding failed: {$e->getMessage()}");
                $exitCode = 1;
            }
        });

        return $exitCode;
    }
}

function class_basename_seed(string $class): string
{
    $pos = strrpos($class, '\\');
    return $pos !== false ? substr($class, $pos + 1) : $class;
}
