<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApiKey;
use App\Models\Message;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Fabriq\Orm\DB;

/**
 * Chat repository — all queries enforce tenant_id.
 *
 * Delegates to ORM Model classes for database access.
 * Tenant scoping is automatic via HasTenantScope on each model.
 */
final class ChatRepository
{
    // ── Platform (no tenant scope) ───────────────────────────────────

    public function createTenant(array $tenant, array $apiKey): void
    {
        Tenant::create([
            'id' => $tenant['id'],
            'slug' => $tenant['slug'],
            'name' => $tenant['name'],
            'plan' => $tenant['plan'] ?? 'free',
            'status' => $tenant['status'] ?? 'active',
            'config_json' => $tenant['config_json'] ?? null,
        ]);

        ApiKey::create([
            'id' => 'ak-' . bin2hex(random_bytes(4)),
            'tenant_id' => $tenant['id'],
            'key_prefix' => $apiKey['prefix'],
            'key_hash' => $apiKey['hash'],
            'name' => 'default',
            'scopes' => '["*"]',
        ]);
    }

    public function findTenantBySlug(string $slug): ?array
    {
        $tenant = Tenant::where('slug', $slug)->first();
        return $tenant?->toArray();
    }

    public function findTenantById(string $id): ?array
    {
        $tenant = Tenant::find($id);
        return $tenant?->toArray();
    }

    public function findTenantByDomain(string $domain): ?array
    {
        $tenant = Tenant::where('domain', $domain)->first();
        return $tenant?->toArray();
    }

    public function findApiKeyByPrefix(string $prefix): ?array
    {
        $key = ApiKey::where('key_prefix', $prefix)->first();
        return $key?->toArray();
    }

    // ── Tenant-scoped (enforce tenant_id) ────────────────────────────

    public function createUser(array $user): void
    {
        User::create([
            'id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'password_hash' => $user['password_hash'] ?? null,
            'status' => $user['status'] ?? 'active',
        ]);
    }

    public function createRoom(array $room): void
    {
        Room::create([
            'id' => $room['id'],
            'tenant_id' => $room['tenant_id'],
            'name' => $room['name'],
            'created_by' => $room['created_by'],
        ]);
    }

    public function listRooms(string $tenantId): array
    {
        return Room::query()->get()->toArray();
    }

    public function joinRoom(string $tenantId, string $roomId, string $userId): void
    {
        DB::table('room_members')
            ->tenantScoped(true)
            ->insert([
                'tenant_id' => $tenantId,
                'room_id' => $roomId,
                'user_id' => $userId,
            ]);
    }

    public function createMessage(array $message): void
    {
        Message::create([
            'id' => $message['id'],
            'tenant_id' => $message['tenant_id'],
            'room_id' => $message['room_id'],
            'user_id' => $message['user_id'],
            'body' => $message['body'],
        ]);
    }

    public function listMessages(string $tenantId, string $roomId): array
    {
        return Message::where('room_id', $roomId)
            ->orderBy('created_at', 'ASC')
            ->get()
            ->toArray();
    }

    /**
     * Count messages for a room.
     */
    public function messageCount(string $tenantId, string $roomId): int
    {
        return Message::where('room_id', $roomId)->count();
    }
}
