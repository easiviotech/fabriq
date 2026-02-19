<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Fabriq\Observability\Logger;
use Swoole\Timer;
use Swoole\WebSocket\Server as WsServer;

/**
 * Pre-game lobby manager.
 *
 * Manages the waiting-room phase before a game starts:
 *   - Players join a lobby
 *   - Ready checks: all players must confirm readiness
 *   - Countdown timer: starts when all players are ready
 *   - Auto-start: game begins when countdown expires
 *   - Kick/leave: handle players who leave during lobby
 *
 * Flow: Lobby → Ready Check → Countdown → Game Start
 */
final class LobbyManager
{
    /** @var array<string, array{
     *     room: GameRoom,
     *     countdown_timer: ?int,
     *     countdown_seconds: int,
     *     started: bool,
     * }> roomId → lobby state */
    private array $lobbies = [];

    private int $countdownSeconds;

    public function __construct(
        private readonly GameRoomManager $roomManager,
        private readonly UdpProtocol $protocol,
        private readonly ?WsServer $server = null,
        private readonly ?Logger $logger = null,
        int $countdownSeconds = 5,
    ) {
        $this->countdownSeconds = $countdownSeconds;
    }

    /**
     * Create a new lobby for a room.
     */
    public function createLobby(GameRoom $room): void
    {
        $this->lobbies[$room->getId()] = [
            'room' => $room,
            'countdown_timer' => null,
            'countdown_seconds' => $this->countdownSeconds,
            'started' => false,
        ];

        $this->logger?->info('Lobby created', ['room_id' => $room->getId()]);
    }

    /**
     * Handle a player ready toggle.
     */
    public function setPlayerReady(string $roomId, string $playerId, bool $ready = true): void
    {
        $lobby = $this->lobbies[$roomId] ?? null;
        if ($lobby === null || $lobby['started']) {
            return;
        }

        $room = $lobby['room'];
        $room->setPlayerReady($playerId, $ready);

        // Notify all players of ready state change
        $this->broadcastLobbyState($roomId);

        // Check if all players are ready
        if ($room->allPlayersReady() && $room->getPlayerCount() >= 2) {
            $this->startCountdown($roomId);
        } else {
            $this->cancelCountdown($roomId);
        }
    }

    /**
     * Get lobby state for API/WS response.
     *
     * @return array<string, mixed>|null
     */
    public function getLobbyState(string $roomId): ?array
    {
        $lobby = $this->lobbies[$roomId] ?? null;
        if ($lobby === null) {
            return null;
        }

        $room = $lobby['room'];

        return [
            'room_id' => $roomId,
            'status' => $lobby['started'] ? 'starting' : 'waiting',
            'players' => array_map(fn($id) => [
                'player_id' => $id,
                'ready' => $room->getPlayer($id)['ready'] ?? false,
            ], $room->getPlayerIds()),
            'countdown' => $lobby['countdown_timer'] !== null ? $lobby['countdown_seconds'] : null,
            'min_players' => 2,
        ];
    }

    /**
     * Remove a lobby.
     */
    public function removeLobby(string $roomId): void
    {
        $this->cancelCountdown($roomId);
        unset($this->lobbies[$roomId]);
    }

    /**
     * Check if a room has an active lobby.
     */
    public function hasLobby(string $roomId): bool
    {
        return isset($this->lobbies[$roomId]);
    }

    // ── Internal ────────────────────────────────────────────────────

    /**
     * Start the countdown to game start.
     */
    private function startCountdown(string $roomId): void
    {
        $lobby = &$this->lobbies[$roomId];
        if ($lobby === null || $lobby['countdown_timer'] !== null) {
            return;
        }

        $lobby['countdown_seconds'] = $this->countdownSeconds;
        $remaining = $this->countdownSeconds;

        $lobby['countdown_timer'] = Timer::tick(1000, function () use ($roomId, &$remaining) {
            $remaining--;

            if (!isset($this->lobbies[$roomId])) {
                return;
            }

            $this->lobbies[$roomId]['countdown_seconds'] = $remaining;

            // Broadcast countdown
            $this->broadcastToRoom($roomId, [
                'type' => 'countdown',
                'seconds' => $remaining,
            ]);

            if ($remaining <= 0) {
                $this->startGame($roomId);
            }
        });

        $this->broadcastToRoom($roomId, [
            'type' => 'countdown_started',
            'seconds' => $this->countdownSeconds,
        ]);

        $this->logger?->info('Countdown started', [
            'room_id' => $roomId,
            'seconds' => $this->countdownSeconds,
        ]);
    }

    /**
     * Cancel an active countdown.
     */
    private function cancelCountdown(string $roomId): void
    {
        $lobby = &$this->lobbies[$roomId];
        if ($lobby === null || $lobby['countdown_timer'] === null) {
            return;
        }

        Timer::clear($lobby['countdown_timer']);
        $lobby['countdown_timer'] = null;
        $lobby['countdown_seconds'] = $this->countdownSeconds;

        $this->broadcastToRoom($roomId, [
            'type' => 'countdown_cancelled',
        ]);
    }

    /**
     * Transition from lobby to active game.
     */
    private function startGame(string $roomId): void
    {
        $lobby = &$this->lobbies[$roomId];
        if ($lobby === null || $lobby['started']) {
            return;
        }

        $this->cancelCountdown($roomId);
        $lobby['started'] = true;

        $room = $lobby['room'];
        $room->start();

        $this->broadcastToRoom($roomId, [
            'type' => 'game_started',
            'room_id' => $roomId,
        ]);

        $this->logger?->info('Game started from lobby', ['room_id' => $roomId]);

        // Remove lobby (game is now managed by GameLoop)
        unset($this->lobbies[$roomId]);
    }

    /**
     * Broadcast lobby state to all players in a room.
     */
    private function broadcastLobbyState(string $roomId): void
    {
        $state = $this->getLobbyState($roomId);
        if ($state === null) {
            return;
        }

        $this->broadcastToRoom($roomId, array_merge(['type' => 'lobby_state'], $state));
    }

    /**
     * Send a message to all players in a room.
     */
    private function broadcastToRoom(string $roomId, array $message): void
    {
        $lobby = $this->lobbies[$roomId] ?? null;
        if ($lobby === null || $this->server === null) {
            return;
        }

        $payload = json_encode($message, JSON_THROW_ON_ERROR);
        $room = $lobby['room'];

        foreach ($room->getPlayerFds() as $fd) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $payload);
            }
        }
    }
}

