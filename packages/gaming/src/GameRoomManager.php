<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Fabriq\Kernel\Config;
use Fabriq\Observability\Logger;
use Fabriq\Storage\DbManager;
use Swoole\WebSocket\Server as WsServer;

/**
 * Game room lifecycle manager.
 *
 * Creates, finds, joins, and destroys game rooms.
 * Coordinates between the GameLoop and application layer.
 *
 * Cross-worker room discovery is handled via Redis:
 *   - Room metadata is stored in Redis hashes
 *   - Players are routed to the worker hosting their room
 *   - If a room is on another worker, the join is forwarded
 */
final class GameRoomManager
{
    /** @var array<string, GameRoom> roomId → GameRoom (local to this worker) */
    private array $rooms = [];

    private int $maxRoomsPerWorker;
    private int $maxPlayersPerRoom;

    public function __construct(
        private readonly GameLoop $gameLoop,
        private readonly DbManager $db,
        private readonly ?WsServer $server = null,
        private readonly ?Config $config = null,
        private readonly ?Logger $logger = null,
    ) {
        $this->maxRoomsPerWorker = (int)($config?->get('gaming.max_rooms_per_worker', 100) ?? 100);
        $this->maxPlayersPerRoom = (int)($config?->get('gaming.max_players_per_room', 64) ?? 64);
    }

    /**
     * Create a new game room.
     *
     * @param string $tenantId
     * @param string $tickType casual | realtime | competitive
     * @param int|null $maxPlayers Override default max players
     * @param callable|null $tickHandler Custom game logic
     * @return GameRoom|null Null if at room limit
     */
    public function createRoom(
        string $tenantId,
        string $tickType = 'realtime',
        ?int $maxPlayers = null,
        ?callable $tickHandler = null,
    ): ?GameRoom {
        if (count($this->rooms) >= $this->maxRoomsPerWorker) {
            $this->logger?->warning('Max rooms per worker reached', [
                'current' => count($this->rooms),
                'max' => $this->maxRoomsPerWorker,
            ]);
            return null;
        }

        $roomId = 'room_' . bin2hex(random_bytes(8));
        $max = $maxPlayers ?? $this->maxPlayersPerRoom;

        $room = new GameRoom($roomId, $tenantId, $tickType, $max, $tickHandler);

        if ($this->server !== null) {
            $room->setServer($this->server);
        }

        $this->rooms[$roomId] = $room;
        $this->gameLoop->addRoom($room);

        // Register in Redis for cross-worker discovery
        $this->publishRoomMeta($room);

        $this->logger?->info('Game room created', [
            'room_id' => $roomId,
            'tenant_id' => $tenantId,
            'tick_type' => $tickType,
            'max_players' => $max,
        ]);

        return $room;
    }

    /**
     * Find a room by ID (local only).
     */
    public function findRoom(string $roomId): ?GameRoom
    {
        return $this->rooms[$roomId] ?? null;
    }

    /**
     * Find a room by ID across all workers (via Redis).
     *
     * @return array<string, mixed>|null Room metadata
     */
    public function findRoomGlobal(string $roomId): ?array
    {
        $redis = $this->db->redis();
        try {
            $data = $redis->hGet('game_rooms', $roomId);
            if ($data === false) {
                return null;
            }
            return json_decode($data, true) ?: null;
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Join a player to a room.
     *
     * @return bool True if the player was added
     */
    public function joinRoom(string $roomId, string $playerId, int $fd, string $userId, array $data = []): bool
    {
        $room = $this->findRoom($roomId);
        if ($room === null) {
            return false;
        }

        $result = $room->addPlayer($playerId, $fd, $userId, $data);

        if ($result) {
            $this->publishRoomMeta($room);
        }

        return $result;
    }

    /**
     * Remove a player from a room.
     */
    public function leaveRoom(string $roomId, string $playerId): void
    {
        $room = $this->findRoom($roomId);
        if ($room === null) {
            return;
        }

        $room->removePlayer($playerId);

        // If room is empty and ended, clean it up
        if ($room->getPlayerCount() === 0 && $room->getStatus() === 'ended') {
            $this->destroyRoom($roomId);
        } else {
            $this->publishRoomMeta($room);
        }
    }

    /**
     * Destroy a room.
     */
    public function destroyRoom(string $roomId): void
    {
        $this->gameLoop->removeRoom($roomId);
        unset($this->rooms[$roomId]);

        // Remove from Redis
        $redis = $this->db->redis();
        try {
            $redis->hDel('game_rooms', $roomId);
        } finally {
            $this->db->releaseRedis($redis);
        }

        $this->logger?->info('Game room destroyed', ['room_id' => $roomId]);
    }

    /**
     * Find available rooms for a tenant (from Redis).
     *
     * @return list<array<string, mixed>>
     */
    public function getAvailableRooms(string $tenantId): array
    {
        $redis = $this->db->redis();
        try {
            $all = $redis->hGetAll('game_rooms');
            if (!is_array($all)) {
                return [];
            }

            $rooms = [];
            foreach ($all as $data) {
                $room = json_decode($data, true);
                if (is_array($room)
                    && ($room['tenant_id'] ?? '') === $tenantId
                    && ($room['status'] ?? '') === 'waiting'
                    && ($room['players'] ?? 0) < ($room['max_players'] ?? 0)
                ) {
                    $rooms[] = $room;
                }
            }

            return $rooms;
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Handle player disconnect — remove from any room they're in.
     */
    public function handleDisconnect(int $fd): void
    {
        foreach ($this->rooms as $room) {
            $playerId = $room->findPlayerByFd($fd);
            if ($playerId !== null) {
                $room->removePlayer($playerId);
                $this->publishRoomMeta($room);

                if ($room->getPlayerCount() === 0 && $room->getStatus() === 'ended') {
                    $this->destroyRoom($room->getId());
                }
                return;
            }
        }
    }

    /**
     * Get all local rooms.
     *
     * @return array<string, GameRoom>
     */
    public function getLocalRooms(): array
    {
        return $this->rooms;
    }

    /**
     * Get stats for monitoring.
     *
     * @return array{local_rooms: int, max_rooms: int}
     */
    public function stats(): array
    {
        return [
            'local_rooms' => count($this->rooms),
            'max_rooms' => $this->maxRoomsPerWorker,
        ];
    }

    // ── Internals ───────────────────────────────────────────────────

    /**
     * Publish room metadata to Redis for cross-worker discovery.
     */
    private function publishRoomMeta(GameRoom $room): void
    {
        $redis = $this->db->redis();
        try {
            $redis->hSet('game_rooms', $room->getId(), json_encode($room->toArray(), JSON_THROW_ON_ERROR));
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

