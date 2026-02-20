<?php

declare(strict_types=1);

namespace App\Models;

use Fabriq\Orm\Collection;
use Fabriq\Orm\Model;
use Fabriq\Orm\Relations\BelongsToMany;
use Fabriq\Orm\Relations\HasMany;

/**
 * User model.
 *
 * Represents a user in the app database, tenant-scoped.
 *
 * Table: sf_app.users
 */
class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected string $pool = 'app';
    protected bool $tenantScoped = true;
    protected bool $timestamps = true;

    protected array $fillable = [
        'id',
        'tenant_id',
        'name',
        'email',
        'password_hash',
        'status',
    ];

    /** @var list<string> Hidden from serialization */
    protected array $hidden = ['password_hash'];

    // ── Relationships ───────────────────────────────────────────────

    /**
     * A user has many messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'user_id', 'id');
    }

    /**
     * A user belongs to many rooms (via room_members pivot).
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(
            Room::class,
            'room_members',
            'user_id',
            'room_id',
            'id',
            'id'
        );
    }
}

