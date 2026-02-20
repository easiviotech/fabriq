<?php

declare(strict_types=1);

namespace App\Models;

use Fabriq\Orm\Model;
use Fabriq\Orm\Relations\BelongsTo;

/**
 * Message model.
 *
 * Represents a chat message in the app database, tenant-scoped.
 *
 * Table: sf_app.messages
 */
class Message extends Model
{
    protected string $table = 'messages';
    protected string $primaryKey = 'id';
    protected string $pool = 'app';
    protected bool $tenantScoped = true;
    protected bool $timestamps = false;

    protected array $fillable = [
        'id',
        'tenant_id',
        'room_id',
        'user_id',
        'body',
    ];

    // ── Relationships ───────────────────────────────────────────────

    /**
     * A message belongs to a room.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    /**
     * A message belongs to a user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}

