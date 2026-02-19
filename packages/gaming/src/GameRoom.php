<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Swoole\WebSocket\Server as WsServer;

/**
 * Single game room: state, players, tick handler, lifecycle.
 *
 * Room lifecycle: waiting → playing → ended
 *
 * The room delegates actual game logic to a "tick handler" callback
 * (provided by the application layer). This keeps the engine generic
 * while allowing any game type to be implemented.
 *
 * State management:
 *   - Full state: complete game state (for new players / reconnecting)
 *   - Deltas: only changed state since last tick (for bandwidth efficiency)
 */
final class GameRoom
{
    private string $id;
    private string $tenantId;
    private string $tickType;

    /** @var string waiting | playing | ended */
    private string $status = 'waiting';

    /** @var array<string, array{fd: int, user_id: string, ready: bool, data: array<string, mixed>}> */
    private array $players = [];

    /** @var array<string, mixed> Full authoritative game state */
    private array $state = [];

    /** @var array<string, mixed> Changed state since last tick */
    private array $deltas = [];

    /** @var callable(GameRoom, float): void Custom tick handler */
    private $tickHandler;

    private ?WsServer $server = null;
    private int $maxPlayers;
    private int $createdAt;
    private ?int $startedAt = null;

    /**
     * @param string $id Unique room identifier
     * @param string $tenantId Tenant scope
     * @param string $tickType One of: casual, realtime, competitive
     * @param int $maxPlayers Maximum players allowed
     * @param callable(GameRoom, float): void $tickHandler Game logic callback
     */
    public function __construct(
        string $id,
        string $tenantId,
        string $tickType = 'realtime',
        int $maxPlayers = 16,
        ?callable $tickHandler = null,
    ) {
        $this->id = $id;
        $this->tenantId = $tenantId;
        $this->tickType = $tickType;
        $this->maxPlayers = $maxPlayers;
        $this->tickHandler = $tickHandler ?? fn() => null;
        $this->createdAt = time();
    }

    // ── Getters ─────────────────────────────────────────────────────

    public function getId(): string { return $this->id; }
    public function getTenantId(): string { return $this->tenantId; }
    public function getTickType(): string { return $this->tickType; }
    public function getStatus(): string { return $this->status; }
    public function getMaxPlayers(): int { return $this->maxPlayers; }
    public function getPlayerCount(): int { return count($this->players); }
    public function isFull(): bool { return count($this->players) >= $this->maxPlayers; }

    /**
     * Set the Swoole WebSocket server for direct push.
     */
    public function setServer(WsServer $server): void
    {
        $this->server = $server;
    }

    // ── Player Management ───────────────────────────────────────────

    /**
     * Add a player to the room.
     *
     * @param string $playerId Unique player identifier
     * @param int $fd WebSocket file descriptor
     * @param string $userId User identity
     * @param array<string, mixed> $data Additional player data
     * @return bool True if the player was added
     */
    public function addPlayer(string $playerId, int $fd, string $userId, array $data = []): bool
    {
        if ($this->isFull()) {
            return false;
        }

        if ($this->status === 'ended') {
            return false;
        }

        $this->players[$playerId] = [
            'fd' => $fd,
            'user_id' => $userId,
            'ready' => false,
            'data' => $data,
        ];

        $this->deltas['players_changed'] = true;

        return true;
    }

    /**
     * Remove a player from the room.
     */
    public function removePlayer(string $playerId): void
    {
        unset($this->players[$playerId]);
        $this->deltas['players_changed'] = true;

        // End game if no players left
        if (empty($this->players) && $this->status === 'playing') {
            $this->end();
        }
    }

    /**
     * Mark a player as ready.
     */
    public function setPlayerReady(string $playerId, bool $ready = true): void
    {
        if (isset($this->players[$playerId])) {
            $this->players[$playerId]['ready'] = $ready;
        }
    }

    /**
     * Check if all players are ready.
     */
    public function allPlayersReady(): bool
    {
        if (empty($this->players)) {
            return false;
        }

        foreach ($this->players as $player) {
            if (!$player['ready']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a player by ID.
     *
     * @return array{fd: int, user_id: string, ready: bool, data: array<string, mixed>}|null
     */
    public function getPlayer(string $playerId): ?array
    {
        return $this->players[$playerId] ?? null;
    }

    /**
     * Find player ID by file descriptor.
     */
    public function findPlayerByFd(int $fd): ?string
    {
        foreach ($this->players as $playerId => $info) {
            if ($info['fd'] === $fd) {
                return $playerId;
            }
        }
        return null;
    }

    /**
     * Get all player file descriptors.
     *
     * @return list<int>
     */
    public function getPlayerFds(): array
    {
        return array_values(array_column($this->players, 'fd'));
    }

    /**
     * Get all player IDs.
     *
     * @return list<string>
     */
    public function getPlayerIds(): array
    {
        return array_keys($this->players);
    }

    // ── Game State ──────────────────────────────────────────────────

    /**
     * Get the full game state.
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Set a value in the game state (marks it as a delta).
     */
    public function setState(string $key, mixed $value): void
    {
        $this->state[$key] = $value;
        $this->deltas[$key] = $value;
    }

    /**
     * Get state deltas since last tick and clear them.
     *
     * @return array<string, mixed>
     */
    public function getDeltas(): array
    {
        $deltas = $this->deltas;
        $this->deltas = [];
        return $deltas;
    }

    /**
     * Replace the entire state (used during state sync / reconnection).
     *
     * @param array<string, mixed> $state
     */
    public function replaceState(array $state): void
    {
        $this->state = $state;
    }

    // ── Lifecycle ───────────────────────────────────────────────────

    /**
     * Start the game (transition: waiting → playing).
     */
    public function start(): bool
    {
        if ($this->status !== 'waiting') {
            return false;
        }

        $this->status = 'playing';
        $this->startedAt = time();
        $this->deltas['game_started'] = true;

        return true;
    }

    /**
     * End the game (transition: playing → ended).
     */
    public function end(): void
    {
        $this->status = 'ended';
        $this->deltas['game_ended'] = true;
    }

    // ── Tick ────────────────────────────────────────────────────────

    /**
     * Run one tick of game logic.
     *
     * Called by GameLoop at the configured tick rate.
     *
     * @param float $deltaTime Seconds since last tick
     */
    public function tick(float $deltaTime): void
    {
        if ($this->status !== 'playing') {
            return;
        }

        ($this->tickHandler)($this, $deltaTime);
    }

    /**
     * Set the tick handler.
     *
     * @param callable(GameRoom, float): void $handler
     */
    public function setTickHandler(callable $handler): void
    {
        $this->tickHandler = $handler;
    }

    // ── Communication ───────────────────────────────────────────────

    /**
     * Send data to a specific player.
     */
    public function sendToPlayer(int $fd, string $data): void
    {
        if ($this->server !== null && $this->server->isEstablished($fd)) {
            $this->server->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
        }
    }

    /**
     * Broadcast data to all players.
     */
    public function broadcast(string $data): void
    {
        foreach ($this->getPlayerFds() as $fd) {
            $this->sendToPlayer($fd, $data);
        }
    }

    // ── Info ────────────────────────────────────────────────────────

    /**
     * Get room info for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'tick_type' => $this->tickType,
            'status' => $this->status,
            'players' => count($this->players),
            'max_players' => $this->maxPlayers,
            'created_at' => $this->createdAt,
            'started_at' => $this->startedAt,
        ];
    }
}

