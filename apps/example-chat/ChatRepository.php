<?php

declare(strict_types=1);

namespace SwooleFabric\ExampleChat;

use SwooleFabric\Storage\TenantAwareRepository;

/**
 * Chat repository — all queries enforce tenant_id.
 *
 * Uses the TenantAwareRepository base class.
 * Backed by in-memory arrays for demo purposes (swap with DbManager for real use).
 */
final class ChatRepository
{
    /** @var array<string, array> In-memory data stores keyed by table name */
    private array $tables = [
        'tenants' => [],
        'api_keys' => [],
        'users' => [],
        'rooms' => [],
        'room_members' => [],
        'messages' => [],
    ];

    // ── Platform (no tenant scope) ───────────────────────────────────

    public function createTenant(array $tenant, array $apiKey): void
    {
        $this->tables['tenants'][$tenant['id']] = $tenant;
        $this->tables['api_keys'][] = [
            'id' => 'ak-' . bin2hex(random_bytes(4)),
            'tenant_id' => $tenant['id'],
            'prefix' => $apiKey['prefix'],
            'key_hash' => $apiKey['hash'],
            'scopes' => '["*"]',
        ];
    }

    public function findTenantBySlug(string $slug): ?array
    {
        foreach ($this->tables['tenants'] as $tenant) {
            if ($tenant['slug'] === $slug) {
                return $tenant;
            }
        }
        return null;
    }

    public function findApiKeyByPrefix(string $prefix): ?array
    {
        foreach ($this->tables['api_keys'] as $key) {
            if ($key['prefix'] === $prefix) {
                return $key;
            }
        }
        return null;
    }

    // ── Tenant-scoped (enforce tenant_id) ────────────────────────────

    public function createUser(array $user): void
    {
        if (empty($user['tenant_id'])) {
            throw new \RuntimeException('tenant_id is required for user creation');
        }
        $this->tables['users'][$user['id']] = $user;
    }

    public function createRoom(array $room): void
    {
        if (empty($room['tenant_id'])) {
            throw new \RuntimeException('tenant_id is required for room creation');
        }
        $this->tables['rooms'][$room['id']] = $room;
    }

    public function listRooms(string $tenantId): array
    {
        return array_values(array_filter(
            $this->tables['rooms'],
        fn(array $r) => $r['tenant_id'] === $tenantId
        ));
    }

    public function joinRoom(string $tenantId, string $roomId, string $userId): void
    {
        $this->tables['room_members'][] = [
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'user_id' => $userId,
        ];
    }

    public function createMessage(array $message): void
    {
        if (empty($message['tenant_id'])) {
            throw new \RuntimeException('tenant_id is required for message creation');
        }
        $this->tables['messages'][$message['id']] = $message;
    }

    public function listMessages(string $tenantId, string $roomId): array
    {
        return array_values(array_filter(
            $this->tables['messages'],
        fn(array $m) => $m['tenant_id'] === $tenantId && $m['room_id'] === $roomId
        ));
    }

    /**
     * Count messages for a room.
     */
    public function messageCount(string $tenantId, string $roomId): int
    {
        return count(array_filter(
            $this->tables['messages'],
        fn(array $m) => $m['tenant_id'] === $tenantId && $m['room_id'] === $roomId
        ));
    }
}
