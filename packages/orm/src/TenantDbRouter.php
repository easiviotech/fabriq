<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Context;
use Fabriq\Storage\ConnectionPool;
use Fabriq\Storage\DbManager;
use Fabriq\Storage\MysqlConnectionFactory;
use Fabriq\Tenancy\TenantContext;
use RuntimeException;

/**
 * Per-tenant database connection router.
 *
 * Resolves the correct database connection for the current tenant based on
 * their configured strategy:
 *
 *   - **shared**:      Use the default 'app' pool. Tenant isolation is via WHERE tenant_id.
 *   - **same_server**: Borrow from 'app' pool, then `USE <tenant_db>`. Restore on release.
 *   - **dedicated**:   Dynamically create/cache a ConnectionPool for the tenant's dedicated server.
 *                      LRU eviction keeps memory bounded.
 *
 * Usage:
 *   $handle = $router->acquire();
 *   $conn   = $handle['conn'];
 *   // ... execute queries ...
 *   $router->release($handle);
 */
final class TenantDbRouter
{
    /** @var array<string, string> LRU order: pool key → tenant ID (most recent at end) */
    private array $lruOrder = [];

    /** @var int Maximum dedicated pools to keep alive */
    private int $maxDedicatedPools;

    /** @var array<string, mixed> Pool config for dedicated tenant pools */
    private array $dedicatedPoolConfig;

    /** @var string Default strategy when tenant has no config */
    private string $defaultStrategy;

    public function __construct(
        private readonly DbManager $db,
        private readonly Config $config,
    ) {
        $routing = $config->get('orm.tenant_routing', []);
        $routing = is_array($routing) ? $routing : [];

        $this->defaultStrategy = (string) ($routing['default_strategy'] ?? 'shared');
        $this->maxDedicatedPools = (int) ($routing['max_dedicated_pools'] ?? 50);
        $this->dedicatedPoolConfig = is_array($routing['dedicated_pool'] ?? null)
            ? $routing['dedicated_pool']
            : ['max_size' => 10, 'borrow_timeout' => 3.0, 'idle_timeout' => 120.0];
    }

    /**
     * Acquire a database connection for the current tenant.
     *
     * Returns a handle array that MUST be passed to release() when done.
     *
     * @param string $pool Base pool name ('app' or 'platform')
     * @return array{conn: mixed, pool: string, strategy: string, original_db: string|null}
     */
    public function acquire(string $pool = 'app'): array
    {
        // Platform pool is never tenant-routed
        if ($pool === 'platform') {
            return [
                'conn'        => $this->db->borrow('platform'),
                'pool'        => 'platform',
                'strategy'    => 'shared',
                'original_db' => null,
            ];
        }

        $tenant = Context::tenant();
        $strategy = $this->resolveStrategy($tenant);

        return match ($strategy) {
            'same_server' => $this->acquireSameServer($tenant),
            'dedicated'   => $this->acquireDedicated($tenant),
            default       => $this->acquireShared(),
        };
    }

    /**
     * Release a connection handle back to its pool.
     *
     * @param array{conn: mixed, pool: string, strategy: string, original_db: string|null} $handle
     */
    public function release(array $handle): void
    {
        $conn = $handle['conn'];
        $pool = $handle['pool'];
        $strategy = $handle['strategy'];
        $originalDb = $handle['original_db'];

        // Restore the original database if we switched
        if ($strategy === 'same_server' && $originalDb !== null) {
            try {
                $conn->query("USE `{$originalDb}`");
            } catch (\Throwable) {
                // Best effort — pool health check will catch broken connections
            }
        }

        $this->db->release($pool, $conn);
    }

    // ── Strategy Resolution ─────────────────────────────────────────

    private function resolveStrategy(?TenantContext $tenant): string
    {
        if ($tenant === null) {
            return $this->defaultStrategy;
        }

        $strategy = $tenant->configValue('database.strategy');
        if (is_string($strategy) && in_array($strategy, ['shared', 'same_server', 'dedicated'], true)) {
            return $strategy;
        }

        return $this->defaultStrategy;
    }

    // ── Shared Strategy ─────────────────────────────────────────────

    /**
     * @return array{conn: mixed, pool: string, strategy: string, original_db: string|null}
     */
    private function acquireShared(): array
    {
        return [
            'conn'        => $this->db->borrow('app'),
            'pool'        => 'app',
            'strategy'    => 'shared',
            'original_db' => null,
        ];
    }

    // ── Same Server Strategy ────────────────────────────────────────

    /**
     * Borrow from the app pool, then USE the tenant's specific database.
     *
     * @return array{conn: mixed, pool: string, strategy: string, original_db: string|null}
     */
    private function acquireSameServer(?TenantContext $tenant): array
    {
        $tenantDb = $tenant?->configValue('database.name');

        if (!is_string($tenantDb) || $tenantDb === '') {
            // Fallback to shared if no DB name configured
            return $this->acquireShared();
        }

        $conn = $this->db->borrow('app');

        // Remember the original database so we can restore on release
        $originalDb = $this->config->get('database.app.database', 'sf_app');
        if (!is_string($originalDb)) {
            $originalDb = 'sf_app';
        }

        try {
            $conn->query("USE `{$tenantDb}`");
        } catch (\Throwable $e) {
            $this->db->release('app', $conn);
            throw new RuntimeException(
                "Failed to switch to tenant database [{$tenantDb}]: {$e->getMessage()}",
                0,
                $e
            );
        }

        return [
            'conn'        => $conn,
            'pool'        => 'app',
            'strategy'    => 'same_server',
            'original_db' => $originalDb,
        ];
    }

    // ── Dedicated Strategy ──────────────────────────────────────────

    /**
     * Get or create a dedicated pool for this tenant's MySQL server.
     *
     * @return array{conn: mixed, pool: string, strategy: string, original_db: string|null}
     */
    private function acquireDedicated(?TenantContext $tenant): array
    {
        if ($tenant === null) {
            return $this->acquireShared();
        }

        $dbConfig = $tenant->configValue('database');
        if (!is_array($dbConfig)) {
            return $this->acquireShared();
        }

        $poolKey = 'tenant_' . $tenant->id;

        // Ensure pool exists
        if (!$this->db->hasPool($poolKey)) {
            $this->createDedicatedPool($poolKey, $dbConfig);
        }

        // Touch LRU
        unset($this->lruOrder[$poolKey]);
        $this->lruOrder[$poolKey] = $tenant->id;

        return [
            'conn'        => $this->db->borrow($poolKey),
            'pool'        => $poolKey,
            'strategy'    => 'dedicated',
            'original_db' => null,
        ];
    }

    /**
     * Create a new ConnectionPool for a dedicated tenant database.
     *
     * @param string $poolKey
     * @param array<string, mixed> $dbConfig Tenant's database config
     */
    private function createDedicatedPool(string $poolKey, array $dbConfig): void
    {
        // Evict LRU pools if at capacity
        while (count($this->lruOrder) >= $this->maxDedicatedPools) {
            $evictKey = array_key_first($this->lruOrder);
            if ($evictKey !== null) {
                $this->db->removeMysqlPool($evictKey);
                unset($this->lruOrder[$evictKey]);
            }
        }

        $pool = MysqlConnectionFactory::createPool([
            'host'     => $dbConfig['host'] ?? '127.0.0.1',
            'port'     => (int) ($dbConfig['port'] ?? 3306),
            'database' => $dbConfig['name'] ?? $dbConfig['database'] ?? '',
            'username' => $dbConfig['username'] ?? 'root',
            'password' => $dbConfig['password'] ?? '',
            'charset'  => $dbConfig['charset'] ?? 'utf8mb4',
            'pool'     => $this->dedicatedPoolConfig,
        ]);

        $this->db->addMysqlPool($poolKey, $pool);
    }
}

