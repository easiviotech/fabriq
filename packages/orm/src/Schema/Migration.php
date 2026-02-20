<?php

declare(strict_types=1);

namespace Fabriq\Orm\Schema;

/**
 * Base class for database migrations.
 *
 * Each migration file extends this class and implements up() and down().
 * The MigrationRunner discovers and executes migrations in order.
 *
 * Usage:
 *   class CreateUsersTable extends Migration
 *   {
 *       public function up(Schema $schema): void
 *       {
 *           Schema::create('users', function (Blueprint $table) {
 *               $table->uuid('id')->primary();
 *               $table->string('name');
 *               $table->timestamps();
 *           });
 *       }
 *
 *       public function down(Schema $schema): void
 *       {
 *           Schema::drop('users');
 *       }
 *   }
 */
abstract class Migration
{
    /**
     * The connection pool to use for this migration.
     * Override in subclass to target 'platform' instead of 'app'.
     */
    protected string $pool = 'app';

    /**
     * Run the migration (create/alter tables).
     */
    abstract public function up(): void;

    /**
     * Reverse the migration (drop/revert tables).
     */
    abstract public function down(): void;

    /**
     * Get the pool this migration targets.
     */
    public function getPool(): string
    {
        return $this->pool;
    }
}

