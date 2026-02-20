<?php

declare(strict_types=1);

namespace App\Models;

use Fabriq\Orm\Model;
use Fabriq\Orm\Relations\BelongsTo;

/**
 * API Key model.
 *
 * Represents an API key belonging to a tenant in the platform database.
 * Scoped by tenant_id (FK to tenants).
 *
 * Table: sf_platform.api_keys
 */
class ApiKey extends Model
{
    protected string $table = 'api_keys';
    protected string $primaryKey = 'id';
    protected string $pool = 'platform';
    protected bool $tenantScoped = true;
    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'tenant_id',
        'key_prefix',
        'key_hash',
        'name',
        'scopes',
        'expires_at',
    ];

    protected array $casts = [
        'scopes' => 'json',
    ];

    // ── Relationships ───────────────────────────────────────────────

    /**
     * An API key belongs to a tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}

