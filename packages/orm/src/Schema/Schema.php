<?php

declare(strict_types=1);

namespace Fabriq\Orm\Schema;

use Fabriq\Orm\TenantDbRouter;
use RuntimeException;

/**
 * DDL executor for database schema management.
 *
 * Provides a clean API for CREATE TABLE, ALTER TABLE, DROP TABLE,
 * and schema inspection. All operations route through TenantDbRouter.
 *
 * Usage:
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->uuid('id')->primary();
 *       $table->string('name', 100)->notNull();
 *       $table->timestamps();
 *   });
 *
 *   Schema::drop('temp_data');
 */
final class Schema
{
    private static ?TenantDbRouter $router = null;
    private static string $defaultPool = 'app';

    /**
     * Set the TenantDbRouter (called during provider boot).
     */
    public static function setRouter(TenantDbRouter $router): void
    {
        self::$router = $router;
    }

    /**
     * Set the default pool for schema operations.
     */
    public static function setDefaultPool(string $pool): void
    {
        self::$defaultPool = $pool;
    }

    /**
     * Create a new table.
     *
     * @param string $table Table name
     * @param callable(Blueprint): void $callback
     * @param string|null $pool Connection pool (null = default)
     */
    public static function create(string $table, callable $callback, ?string $pool = null): void
    {
        $blueprint = new Blueprint($table, isCreate: true);
        $callback($blueprint);

        $sql = $blueprint->toCreateSql();
        self::executeDdl($sql, $pool);
    }

    /**
     * Alter an existing table.
     *
     * @param string $table Table name
     * @param callable(Blueprint): void $callback
     * @param string|null $pool Connection pool (null = default)
     */
    public static function alter(string $table, callable $callback, ?string $pool = null): void
    {
        $blueprint = new Blueprint($table, isCreate: false);
        $callback($blueprint);

        $statements = $blueprint->toAlterSql();
        foreach ($statements as $sql) {
            self::executeDdl($sql, $pool);
        }
    }

    /**
     * Drop a table if it exists.
     */
    public static function drop(string $table, ?string $pool = null): void
    {
        self::executeDdl("DROP TABLE IF EXISTS `{$table}`", $pool);
    }

    /**
     * Drop a table (fails if it doesn't exist).
     */
    public static function dropIfExists(string $table, ?string $pool = null): void
    {
        self::executeDdl("DROP TABLE IF EXISTS `{$table}`", $pool);
    }

    /**
     * Rename a table.
     */
    public static function rename(string $from, string $to, ?string $pool = null): void
    {
        self::executeDdl("RENAME TABLE `{$from}` TO `{$to}`", $pool);
    }

    /**
     * Check if a table exists.
     */
    public static function hasTable(string $table, ?string $pool = null): bool
    {
        $rows = self::executeQuery("SHOW TABLES LIKE '{$table}'", $pool);
        return !empty($rows);
    }

    /**
     * Check if a column exists on a table.
     */
    public static function hasColumn(string $table, string $column, ?string $pool = null): bool
    {
        $rows = self::executeQuery(
            "SHOW COLUMNS FROM `{$table}` WHERE Field = '{$column}'",
            $pool
        );
        return !empty($rows);
    }

    /**
     * Get all column names for a table.
     *
     * @return list<string>
     */
    public static function getColumnNames(string $table, ?string $pool = null): array
    {
        $rows = self::executeQuery("SHOW COLUMNS FROM `{$table}`", $pool);
        return array_map(fn($row) => $row['Field'] ?? '', $rows);
    }

    // ── Internal Execution ──────────────────────────────────────────

    private static function executeDdl(string $sql, ?string $pool = null): void
    {
        $router = self::getRouter();
        $handle = $router->acquire($pool ?? self::$defaultPool);
        $conn = $handle['conn'];

        try {
            $result = $conn->query($sql);
            if ($result === false) {
                throw new RuntimeException("DDL execution failed: {$conn->error} (SQL: {$sql})");
            }
        } finally {
            $router->release($handle);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function executeQuery(string $sql, ?string $pool = null): array
    {
        $router = self::getRouter();
        $handle = $router->acquire($pool ?? self::$defaultPool);
        $conn = $handle['conn'];

        try {
            $result = $conn->query($sql);
            if ($result === false) {
                throw new RuntimeException("Schema query failed: {$conn->error} (SQL: {$sql})");
            }
            return is_array($result) ? $result : [];
        } finally {
            $router->release($handle);
        }
    }

    private static function getRouter(): TenantDbRouter
    {
        if (self::$router === null) {
            throw new RuntimeException('Schema requires a TenantDbRouter. Ensure OrmServiceProvider is registered.');
        }
        return self::$router;
    }
}

