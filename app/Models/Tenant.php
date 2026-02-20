<?php

declare(strict_types=1);

namespace App\Models;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;
use Fabriq\Orm\Relations\HasMany;

/**
 * Tenant model.
 *
 * Represents a tenant in the platform database.
 * NOT tenant-scoped (this IS the tenant table).
 *
 * Table: sf_platform.tenants
 */
class Tenant extends Model
{
    protected string $table = 'tenants';
    protected string $primaryKey = 'id';
    protected string $pool = 'platform';
    protected bool $tenantScoped = false;
    protected bool $timestamps = true;

    protected array $fillable = [
        'id',
        'slug',
        'name',
        'domain',
        'plan',
        'status',
        'config_json',
    ];

    protected array $casts = [
        'config_json' => 'json',
    ];

    // ── Relationships ───────────────────────────────────────────────

    /**
     * A tenant has many API keys.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'tenant_id', 'id');
    }
}

