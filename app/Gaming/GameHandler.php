<?php

declare(strict_types=1);

namespace App\Gaming;

use Fabriq\Gaming\GameRoomManager;
use Fabriq\Gaming\LobbyManager;
use Fabriq\Gaming\PlayerSession;
use Fabriq\Gaming\UdpProtocol;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

/**
 * WebSocket handler for game messages.
 *
 * Routes incoming WebSocket messages to the appropriate game room
 * or lobby. Handles player join, leave, input, and room management.
 *
 * Message types:
 *   - join_room:    Join a game room
 *   - leave_room:   Leave a game room
 *   - player_input: Send player input to the game loop
 *   - ready:        Toggle ready state in lobby
 *   - reconnect:    Attempt to reconnect to a previous session
 */
final class GameHandler
{
    public function __construct(
        private readonly GameRoomManager $roomManager,
        private readonly LobbyManager $lobbyManager,
        private readonly PlayerSession $playerSession,
        private readonly UdpProtocol $protocol,
    ) {}

    /**
     * Handle an incoming WebSocket game message.
     */
    public function handle(
        WsServer $server,
        Frame $frame,
        string $tenantId,
        string $userId,
    ): void {
        // Decode message (supports both JSON text and binary msgpack)
        if ($frame->opcode === WEBSOCKET_OPCODE_BINARY) {
            $data = $this->protocol->decode($frame->data);
        } else {
            $data = json_decode($frame->data, true);
        }

        if (!is_array($data) || !isset($data['type'])) {
            $server->push($frame->fd, json_encode(['error' => 'Invalid game message']));
            return;
        }

        $type = $data['type'];

        match ($type) {
            'join_room' => $this->handleJoinRoom($server, $frame->fd, $tenantId, $userId, $data),
            'leave_room' => $this->handleLeaveRoom($server, $frame->fd, $data),
            'player_input' => $this->handlePlayerInput($frame->fd, $data),
            'ready' => $this->handleReady($frame->fd, $userId, $data),
            'reconnect' => $this->handleReconnect($server, $frame->fd, $tenantId, $userId),
            default => $server->push($frame->fd, json_encode(['error' => 'Unknown game action'])),
        };
    }

    /**
     * Handle player disconnect.
     */
    public function handleDisconnect(int $fd, string $userId): void
    {
        $this->roomManager->handleDisconnect($fd);
        $this->playerSession->disconnect($userId);
    }

    // ── Internal Handlers ───────────────────────────────────────────

    private function handleJoinRoom(WsServer $server, int $fd, string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            $server->push($fd, json_encode(['error' => 'Missing room_id']));
            return;
        }

        $success = $this->roomManager->joinRoom($roomId, $userId, $fd, $userId);

        if (!$success) {
            $server->push($fd, json_encode(['error' => 'Cannot join room (full or not found)']));
            return;
        }

        // Track session
        $this->playerSession->connect($userId, $tenantId, $userId, $fd);
        $this->playerSession->setRoom($userId, $roomId);

        $room = $this->roomManager->findRoom($roomId);

        $server->push($fd, json_encode([
            'type' => 'room_joined',
            'room_id' => $roomId,
            'room' => $room?->toArray(),
        ]));

        // If lobby exists, broadcast updated state
        if ($this->lobbyManager->hasLobby($roomId)) {
            // The broadcast is handled internally by the lobby
        }
    }

    private function handleLeaveRoom(WsServer $server, int $fd, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            return;
        }

        // Find the player's ID from the room
        $room = $this->roomManager->findRoom($roomId);
        if ($room !== null) {
            $playerId = $room->findPlayerByFd($fd);
            if ($playerId !== null) {
                $this->roomManager->leaveRoom($roomId, $playerId);
                $this->playerSession->setRoom($playerId, null);
            }
        }

        $server->push($fd, json_encode([
            'type' => 'room_left',
            'room_id' => $roomId,
        ]));
    }

    private function handlePlayerInput(int $fd, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            return;
        }

        $room = $this->roomManager->findRoom($roomId);
        if ($room === null) {
            return;
        }

        // Apply input to game state (the tick handler processes it)
        $playerId = $room->findPlayerByFd($fd);
        if ($playerId !== null) {
            $room->setState("input:{$playerId}", $data['data'] ?? []);
        }
    }

    private function handleReady(int $fd, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            return;
        }

        $ready = (bool)($data['ready'] ?? true);
        $this->lobbyManager->setPlayerReady($roomId, $userId, $ready);
    }

    private function handleReconnect(WsServer $server, int $fd, string $tenantId, string $userId): void
    {
        $result = $this->playerSession->reconnect($userId, $fd);

        if (!$result['success']) {
            $server->push($fd, json_encode([
                'type' => 'reconnect_failed',
                'reason' => 'Session expired or not found',
            ]));
            return;
        }

        $roomId = $result['room_id'];
        if ($roomId !== null) {
            // Rejoin the room
            $room = $this->roomManager->findRoom($roomId);
            if ($room !== null) {
                $room->addPlayer($userId, $fd, $userId, $result['data']);

                $server->push($fd, json_encode([
                    'type' => 'reconnected',
                    'room_id' => $roomId,
                    'state' => $room->getState(),
                ]));
                return;
            }
        }

        $server->push($fd, json_encode([
            'type' => 'reconnected',
            'room_id' => null,
            'message' => 'Session restored but room no longer exists',
        ]));
    }
}

