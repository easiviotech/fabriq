<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use RuntimeException;

/**
 * Static database facade.
 *
 * Entry point for all ORM operations. Provides a clean, static API
 * that internally delegates to QueryBuilder, ProcedureCall, and
 * TenantDbRouter for coroutine-safe, tenant-aware database access.
 *
 * Usage:
 *   // Query builder
 *   $users = DB::table('users')->where('status', 'active')->get();
 *
 *   // Stored procedure call
 *   $result = DB::call('sp_get_stats')
 *       ->in('user_id', $userId)
 *       ->out('total')
 *       ->exec();
 *
 *   // Raw query
 *   $rows = DB::raw('SELECT * FROM users WHERE id = ?', [$id]);
 *
 *   // Transaction
 *   DB::transaction('app', function ($conn) {
 *       // ... queries using $conn ...
 *   });
 */
final class DB
{
    private static ?TenantDbRouter $router = null;

    /**
     * Set the TenantDbRouter (called by OrmServiceProvider).
     */
    public static function setRouter(TenantDbRouter $router): void
    {
        self::$router = $router;
    }

    /**
     * Get the router instance.
     */
    public static function getRouter(): TenantDbRouter
    {
        if (self::$router === null) {
            throw new RuntimeException('DB facade not initialized. Ensure OrmServiceProvider is registered.');
        }
        return self::$router;
    }

    // ── Query Builder ───────────────────────────────────────────────

    /**
     * Start a query on a table.
     *
     * @param string $table Table name
     * @return QueryBuilder
     */
    public static function table(string $table): QueryBuilder
    {
        return (new QueryBuilder(self::getRouter()))->table($table);
    }

    // ── Stored Procedures ───────────────────────────────────────────

    /**
     * Start a stored procedure call.
     *
     * @param string $procedure Procedure name
     * @return ProcedureCall
     */
    public static function call(string $procedure): ProcedureCall
    {
        return new ProcedureCall($procedure, self::getRouter());
    }

    /**
     * Alias for call() — more descriptive for some use cases.
     */
    public static function procedure(string $procedure): ProcedureCall
    {
        return self::call($procedure);
    }

    // ── Raw Queries ─────────────────────────────────────────────────

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql SQL statement
     * @param list<mixed> $params Bind values
     * @param string $pool Connection pool
     * @return list<array<string, mixed>>
     */
    public static function raw(string $sql, array $params = [], string $pool = 'app'): array
    {
        $router = self::getRouter();
        $handle = $router->acquire($pool);
        $conn = $handle['conn'];

        try {
            if (empty($params)) {
                $result = $conn->query($sql);
            } else {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new RuntimeException("MySQL prepare failed: {$conn->error} (SQL: {$sql})");
                }
                $result = $stmt->execute($params);
            }

            if ($result === false) {
                throw new RuntimeException("MySQL query failed: {$conn->error} (SQL: {$sql})");
            }

            return is_array($result) ? $result : [];
        } finally {
            $router->release($handle);
        }
    }

    // ── Transactions ────────────────────────────────────────────────

    /**
     * Execute a callback within a database transaction.
     *
     * Borrows ONE connection for the full duration, commits on success,
     * rolls back on exception, always releases.
     *
     * @template T
     * @param string $pool Connection pool name
     * @param callable(mixed): T $callback Receives the raw MySQL connection
     * @return T
     */
    public static function transaction(string $pool, callable $callback): mixed
    {
        $router = self::getRouter();
        $handle = $router->acquire($pool);
        $conn = $handle['conn'];

        try {
            $conn->begin();
            $result = $callback($conn);
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            try {
                $conn->rollback();
            } catch (\Throwable) {
                // Swallow — original exception is more important
            }
            throw $e;
        } finally {
            $router->release($handle);
        }
    }

    // ── Connection Access ───────────────────────────────────────────

    /**
     * Borrow a connection from the tenant-routed pool.
     *
     * The caller is responsible for releasing via DB::release().
     *
     * @return array{conn: mixed, pool: string, strategy: string, original_db: string|null}
     */
    public static function connection(string $pool = 'app'): array
    {
        return self::getRouter()->acquire($pool);
    }

    /**
     * Release a connection handle.
     *
     * @param array{conn: mixed, pool: string, strategy: string, original_db: string|null} $handle
     */
    public static function release(array $handle): void
    {
        self::getRouter()->release($handle);
    }
}

