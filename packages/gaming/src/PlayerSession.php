<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Fabriq\Storage\DbManager;

/**
 * Player connection tracking with reconnection support.
 *
 * When a player disconnects, their session is kept alive for a
 * configurable grace period. If they reconnect within this window,
 * they resume where they left off (same room, same state).
 *
 * Session data is stored in Redis for cross-worker access.
 */
final class PlayerSession
{
    private int $reconnectionWindow;

    /** @var array<string, array{
     *     player_id: string,
     *     tenant_id: string,
     *     user_id: string,
     *     room_id: ?string,
     *     fd: int,
     *     connected: bool,
     *     disconnected_at: ?int,
     *     data: array<string, mixed>,
     * }> playerId → session data */
    private array $sessions = [];

    public function __construct(
        private readonly DbManager $db,
        private int $windowSeconds = 30,
    ) {
        $this->reconnectionWindow = $windowSeconds;
    }

    /**
     * Create or update a player session.
     */
    public function connect(
        string $playerId,
        string $tenantId,
        string $userId,
        int $fd,
        array $data = [],
    ): void {
        $this->sessions[$playerId] = [
            'player_id' => $playerId,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'room_id' => $this->sessions[$playerId]['room_id'] ?? null,
            'fd' => $fd,
            'connected' => true,
            'disconnected_at' => null,
            'data' => array_merge($this->sessions[$playerId]['data'] ?? [], $data),
        ];

        // Store in Redis for cross-worker access
        $this->saveToRedis($playerId);
    }

    /**
     * Mark a player as disconnected. Starts the reconnection timer.
     */
    public function disconnect(string $playerId): void
    {
        if (!isset($this->sessions[$playerId])) {
            return;
        }

        $this->sessions[$playerId]['connected'] = false;
        $this->sessions[$playerId]['disconnected_at'] = time();
        $this->saveToRedis($playerId);
    }

    /**
     * Attempt to reconnect a player.
     *
     * @return array{success: bool, room_id: ?string, data: array<string, mixed>}
     */
    public function reconnect(string $playerId, int $newFd): array
    {
        // Check local sessions first
        $session = $this->sessions[$playerId] ?? null;

        // If not found locally, check Redis (player might have been on another worker)
        if ($session === null) {
            $session = $this->loadFromRedis($playerId);
        }

        if ($session === null) {
            return ['success' => false, 'room_id' => null, 'data' => []];
        }

        // Check if still within reconnection window
        $disconnectedAt = $session['disconnected_at'] ?? 0;
        if ($disconnectedAt > 0 && (time() - $disconnectedAt) > $this->reconnectionWindow) {
            $this->destroy($playerId);
            return ['success' => false, 'room_id' => null, 'data' => []];
        }

        // Reconnect
        $session['fd'] = $newFd;
        $session['connected'] = true;
        $session['disconnected_at'] = null;
        $this->sessions[$playerId] = $session;
        $this->saveToRedis($playerId);

        return [
            'success' => true,
            'room_id' => $session['room_id'],
            'data' => $session['data'],
        ];
    }

    /**
     * Set the room a player is in.
     */
    public function setRoom(string $playerId, ?string $roomId): void
    {
        if (isset($this->sessions[$playerId])) {
            $this->sessions[$playerId]['room_id'] = $roomId;
            $this->saveToRedis($playerId);
        }
    }

    /**
     * Get a player session.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $playerId): ?array
    {
        return $this->sessions[$playerId] ?? $this->loadFromRedis($playerId);
    }

    /**
     * Check if a player session exists and is within reconnection window.
     */
    public function canReconnect(string $playerId): bool
    {
        $session = $this->get($playerId);
        if ($session === null) {
            return false;
        }

        if ($session['connected']) {
            return false; // Already connected
        }

        $disconnectedAt = $session['disconnected_at'] ?? 0;
        return ($disconnectedAt > 0) && (time() - $disconnectedAt) <= $this->reconnectionWindow;
    }

    /**
     * Destroy a player session completely.
     */
    public function destroy(string $playerId): void
    {
        unset($this->sessions[$playerId]);

        $redis = $this->db->redis();
        try {
            $redis->del("player_session:{$playerId}");
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Clean up expired sessions.
     */
    public function pruneExpired(): void
    {
        $now = time();

        foreach ($this->sessions as $playerId => $session) {
            if (!$session['connected'] && $session['disconnected_at'] !== null) {
                if (($now - $session['disconnected_at']) > $this->reconnectionWindow) {
                    $this->destroy($playerId);
                }
            }
        }
    }

    // ── Redis Persistence ───────────────────────────────────────────

    private function saveToRedis(string $playerId): void
    {
        $session = $this->sessions[$playerId] ?? null;
        if ($session === null) {
            return;
        }

        $redis = $this->db->redis();
        try {
            $redis->setEx(
                "player_session:{$playerId}",
                $this->reconnectionWindow * 2, // Keep slightly longer than window
                json_encode($session, JSON_THROW_ON_ERROR),
            );
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    private function loadFromRedis(string $playerId): ?array
    {
        $redis = $this->db->redis();
        try {
            $data = $redis->get("player_session:{$playerId}");
            if ($data === false) {
                return null;
            }

            $decoded = json_decode($data, true);
            return is_array($decoded) ? $decoded : null;
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

