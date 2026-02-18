<?php

declare(strict_types=1);

namespace SwooleFabric\Realtime;

use Swoole\WebSocket\Server as WsServer;

/**
 * WebSocket connection Gateway.
 *
 * Maintains per-worker in-memory maps:
 *   - tenant → userId → [fd, ...]
 *   - tenant → roomId → [fd, ...]
 *   - fd → {tenantId, userId}
 *
 * No FD passing between workers. Each worker manages its own connections.
 * Cross-worker delivery is handled via Redis Pub/Sub (see RealtimeSubscriber).
 */
final class Gateway
{
    /** @var array<string, array<string, list<int>>> tenantId → userId → [fd, ...] */
    private array $userConnections = [];

    /** @var array<string, array<string, list<int>>> tenantId → roomId → [fd, ...] */
    private array $roomMembers = [];

    /** @var array<int, array{tenant_id: string, user_id: string}> fd → meta */
    private array $fdMeta = [];

    /**
     * Register a new WebSocket connection.
     */
    public function addConnection(int $fd, string $tenantId, string $userId): void
    {
        $this->fdMeta[$fd] = ['tenant_id' => $tenantId, 'user_id' => $userId];

        if (!isset($this->userConnections[$tenantId][$userId])) {
            $this->userConnections[$tenantId][$userId] = [];
        }
        $this->userConnections[$tenantId][$userId][] = $fd;
    }

    /**
     * Remove a WebSocket connection (on close).
     *
     * Also removes the fd from all rooms.
     */
    public function removeConnection(int $fd): void
    {
        $meta = $this->fdMeta[$fd] ?? null;
        if ($meta === null) {
            return;
        }

        $tenantId = $meta['tenant_id'];
        $userId = $meta['user_id'];

        // Remove from user connections
        if (isset($this->userConnections[$tenantId][$userId])) {
            $this->userConnections[$tenantId][$userId] = array_values(
                array_filter($this->userConnections[$tenantId][$userId], fn(int $f) => $f !== $fd)
            );
            if (empty($this->userConnections[$tenantId][$userId])) {
                unset($this->userConnections[$tenantId][$userId]);
            }
        }

        // Remove from all rooms
        if (isset($this->roomMembers[$tenantId])) {
            foreach ($this->roomMembers[$tenantId] as $roomId => &$fds) {
                $fds = array_values(array_filter($fds, fn(int $f) => $f !== $fd));
                if (empty($fds)) {
                    unset($this->roomMembers[$tenantId][$roomId]);
                }
            }
            unset($fds);
        }

        unset($this->fdMeta[$fd]);
    }

    /**
     * Join a user's fd to a room.
     */
    public function joinRoom(int $fd, string $tenantId, string $roomId): void
    {
        if (!isset($this->roomMembers[$tenantId][$roomId])) {
            $this->roomMembers[$tenantId][$roomId] = [];
        }

        if (!in_array($fd, $this->roomMembers[$tenantId][$roomId], true)) {
            $this->roomMembers[$tenantId][$roomId][] = $fd;
        }
    }

    /**
     * Leave a room.
     */
    public function leaveRoom(int $fd, string $tenantId, string $roomId): void
    {
        if (!isset($this->roomMembers[$tenantId][$roomId])) {
            return;
        }

        $this->roomMembers[$tenantId][$roomId] = array_values(
            array_filter($this->roomMembers[$tenantId][$roomId], fn(int $f) => $f !== $fd)
        );

        if (empty($this->roomMembers[$tenantId][$roomId])) {
            unset($this->roomMembers[$tenantId][$roomId]);
        }
    }

    /**
     * Push a message to a specific user (all their connections in this worker).
     */
    public function pushToUser(WsServer $server, string $tenantId, string $userId, string $payload): int
    {
        $fds = $this->userConnections[$tenantId][$userId] ?? [];
        $sent = 0;

        foreach ($fds as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $payload);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Push a message to all members of a room in this worker.
     */
    public function pushToRoom(WsServer $server, string $tenantId, string $roomId, string $payload): int
    {
        $fds = $this->roomMembers[$tenantId][$roomId] ?? [];
        $sent = 0;

        foreach ($fds as $fd) {
            if ($server->isEstablished($fd)) {
                $server->push($fd, $payload);
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Get the metadata for a file descriptor.
     *
     * @return array{tenant_id: string, user_id: string}|null
     */
    public function getFdMeta(int $fd): ?array
    {
        return $this->fdMeta[$fd] ?? null;
    }

    /**
     * Get all user IDs connected for a tenant.
     *
     * @return list<string>
     */
    public function getOnlineUsers(string $tenantId): array
    {
        return array_keys($this->userConnections[$tenantId] ?? []);
    }

    /**
     * Get all fds for a room.
     *
     * @return list<int>
     */
    public function getRoomFds(string $tenantId, string $roomId): array
    {
        return $this->roomMembers[$tenantId][$roomId] ?? [];
    }

    /**
     * Get stats for monitoring.
     *
     * @return array{total_connections: int, tenants: int, rooms: int}
     */
    public function stats(): array
    {
        $totalRooms = 0;
        foreach ($this->roomMembers as $rooms) {
            $totalRooms += count($rooms);
        }

        return [
            'total_connections' => count($this->fdMeta),
            'tenants' => count($this->userConnections),
            'rooms' => $totalRooms,
        ];
    }
}

