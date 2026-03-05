<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Orm\DB;

class OrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Initialize the ORM DB facade with the storage layer.
        // This enables Model queries, QueryBuilder, and migrations.
        if ($this->container->has(\Fabriq\Storage\DbManager::class)) {
            $db = $this->container->make(\Fabriq\Storage\DbManager::class);
            DB::init($db);
        }
    }
}
