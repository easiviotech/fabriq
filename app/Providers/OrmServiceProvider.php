<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Container;
use Fabriq\Kernel\ServiceProvider;
use Fabriq\Orm\DB;
use Fabriq\Orm\Model;
use Fabriq\Orm\Schema\MigrationRunner;
use Fabriq\Orm\Schema\Schema;
use Fabriq\Orm\TenantDbRouter;
use Fabriq\Storage\DbManager;

/**
 * ORM Service Provider.
 *
 * Registers the ORM's core services into the container:
 *   - TenantDbRouter (per-tenant database connection routing)
 *   - MigrationRunner (schema migration execution)
 *
 * On boot:
 *   - Wires DB facade and Model base class with the router
 *   - Wires Schema class with the router
 */
class OrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $container = $this->app->container();

        // TenantDbRouter — singleton per worker
        $container->singleton(TenantDbRouter::class, function (Container $c) {
            return new TenantDbRouter(
                db: $c->make(DbManager::class),
                config: $c->make(Config::class),
            );
        });

        // MigrationRunner — singleton per worker
        $container->singleton(MigrationRunner::class, function (Container $c) {
            $config = $c->make(Config::class);
            $basePath = $this->app->basePath();

            $migrationPath = $config->get('orm.migration_path', 'database/migrations');
            if (!is_string($migrationPath)) {
                $migrationPath = 'database/migrations';
            }

            // Resolve relative path from base
            if (!str_starts_with($migrationPath, '/') && !str_starts_with($migrationPath, '\\') && !preg_match('/^[A-Z]:/i', $migrationPath)) {
                $migrationPath = $basePath . DIRECTORY_SEPARATOR . $migrationPath;
            }

            $migrationTable = $config->get('orm.migration_table', 'migrations');
            if (!is_string($migrationTable)) {
                $migrationTable = 'migrations';
            }

            return new MigrationRunner(
                router: $c->make(TenantDbRouter::class),
                migrationPath: $migrationPath,
                migrationTable: $migrationTable,
            );
        });
    }

    public function boot(): void
    {
        // Wire static facades with the router on each worker start
        $this->app->onWorkerStart(function (Container $c) {
            /** @var TenantDbRouter $router */
            $router = $c->make(TenantDbRouter::class);

            // Wire DB facade
            DB::setRouter($router);

            // Wire Model base class
            Model::setRouter($router);

            // Wire Schema class
            Schema::setRouter($router);
        });
    }
}

