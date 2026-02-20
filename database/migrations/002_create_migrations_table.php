<?php

declare(strict_types=1);

use Fabriq\Orm\Schema\Blueprint;
use Fabriq\Orm\Schema\Migration;
use Fabriq\Orm\Schema\Schema;

/**
 * Bootstrap migration — creates the migrations tracking table.
 *
 * This migration is primarily a reference; the MigrationRunner
 * auto-creates the migrations table on first run. However, having
 * it as a migration file ensures it's documented and can be
 * rolled back / recreated consistently.
 */
return new class extends Migration
{
    protected string $pool = 'app';

    public function up(): void
    {
        Schema::create('migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migration', 255)->notNull()->unique();
            $table->dateTime('ran_at')->notNull();
        }, $this->pool);
    }

    public function down(): void
    {
        Schema::drop('migrations', $this->pool);
    }
};

