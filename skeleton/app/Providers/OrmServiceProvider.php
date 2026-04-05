<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Orm\DB;
use Fabriq\Orm\Model;
use Fabriq\Orm\Schema\Schema;
use Fabriq\Orm\TenantDbRouter;

class OrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Initialize the ORM DB facade with the TenantDbRouter.
        // This enables Model queries, QueryBuilder, and migrations.
        if ($this->container->has(\Fabriq\Storage\DbManager::class)) {
            $db     = $this->container->make(\Fabriq\Storage\DbManager::class);
            $config = $this->app->config();
            $router = new TenantDbRouter($db, $config);
            // Framework bug: each facade (DB, Model, Schema) holds its own
            // static router reference. All three must be set or ORM model
            // calls and schema operations will throw at runtime.
            DB::setRouter($router);
            Model::setRouter($router);
            Schema::setRouter($router);
        }
    }
}
