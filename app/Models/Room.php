<?php

declare(strict_types=1);

namespace App\Models;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;
use Fabriq\Orm\Relations\BelongsTo;
use Fabriq\Orm\Relations\BelongsToMany;
use Fabriq\Orm\Relations\HasMany;

/**
 * Room model.
 *
 * Represents a chat room in the app database, tenant-scoped.
 *
 * Table: sf_app.rooms
 */
class Room extends Model
{
    protected string $table = 'rooms';
    protected string $primaryKey = 'id';
    protected string $pool = 'app';
    protected bool $tenantScoped = true;
    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'tenant_id',
        'name',
        'created_by',
    ];

    // ── Relationships ───────────────────────────────────────────────

    /**
     * A room has many messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'room_id', 'id');
    }

    /**
     * A room belongs to many users (via room_members pivot).
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'room_members',
            'room_id',
            'user_id',
            'id',
            'id'
        );
    }

    /**
     * A room was created by a user.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }
}

