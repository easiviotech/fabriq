<?php

declare(strict_types=1);

namespace Fabriq\Orm\Concerns;

use Fabriq\Kernel\Context;
use RuntimeException;

/**
 * Automatic tenant scoping for models.
 *
 * When a model uses this trait and sets `protected bool $tenantScoped = true`,
 * all queries through the Model's QueryBuilder will automatically:
 *
 *   - Append `WHERE tenant_id = ?` on SELECT/UPDATE/DELETE
 *   - Inject `tenant_id` on INSERT
 *
 * The tenant_id is read from `Context::tenantId()`.
 */
trait HasTenantScope
{
    /**
     * Check if this model is tenant-scoped.
     */
    public function isTenantScoped(): bool
    {
        return property_exists($this, 'tenantScoped') ? $this->tenantScoped : false;
    }

    /**
     * Get the tenant_id column name.
     */
    public function getTenantIdColumn(): string
    {
        return property_exists($this, 'tenantIdColumn') ? $this->tenantIdColumn : 'tenant_id';
    }

    /**
     * Get the current tenant_id. Throws if not set and model is scoped.
     *
     * @throws RuntimeException
     */
    protected function resolveTenantId(): ?string
    {
        $tenantId = Context::tenantId();

        if ($this->isTenantScoped() && ($tenantId === null || $tenantId === '')) {
            throw new RuntimeException(
                static::class . ': tenant_id is required but not set on Context. '
                . 'Ensure TenancyMiddleware has run before accessing tenant-scoped models.'
            );
        }

        return $tenantId;
    }

    /**
     * Inject tenant_id into data for inserts.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function injectTenantIdForInsert(array $data): array
    {
        if (!$this->isTenantScoped()) {
            return $data;
        }

        $column = $this->getTenantIdColumn();
        if (!isset($data[$column])) {
            $data[$column] = $this->resolveTenantId();
        }

        return $data;
    }
}

