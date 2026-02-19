<?php

declare(strict_types=1);

namespace App\Gaming;

use Fabriq\Gaming\GameRoomManager;
use Fabriq\Gaming\LobbyManager;
use Fabriq\Gaming\Matchmaker;
use Fabriq\Kernel\Context;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * REST API controller for game lobbies and matchmaking.
 *
 * Endpoints:
 *   GET    /api/game/rooms            — List available rooms
 *   POST   /api/game/rooms            — Create a new room
 *   POST   /api/game/matchmaking      — Queue for matchmaking
 *   DELETE /api/game/matchmaking       — Leave matchmaking queue
 *   GET    /api/game/rooms/{id}       — Get room info
 */
final class GameController
{
    public function __construct(
        private readonly GameRoomManager $roomManager,
        private readonly LobbyManager $lobbyManager,
        private readonly Matchmaker $matchmaker,
    ) {}

    /**
     * List available game rooms for the current tenant.
     */
    public function listRooms(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';
        $rooms = $this->roomManager->getAvailableRooms($tenantId);

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['rooms' => $rooms], JSON_THROW_ON_ERROR));
    }

    /**
     * Create a new game room.
     */
    public function createRoom(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $tickType = $body['tick_type'] ?? 'realtime';
        $maxPlayers = isset($body['max_players']) ? (int)$body['max_players'] : null;

        $room = $this->roomManager->createRoom($tenantId, $tickType, $maxPlayers);

        if ($room === null) {
            $response->status(503);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode(['error' => 'Server at room capacity']));
            return;
        }

        // Create lobby for the room
        $this->lobbyManager->createLobby($room);

        $response->header('Content-Type', 'application/json');
        $response->status(201);
        $response->end(json_encode($room->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Get room info.
     */
    public function showRoom(Request $request, Response $response, string $roomId): void
    {
        $room = $this->roomManager->findRoom($roomId);

        if ($room !== null) {
            $data = $room->toArray();

            // Include lobby state if applicable
            if ($this->lobbyManager->hasLobby($roomId)) {
                $data['lobby'] = $this->lobbyManager->getLobbyState($roomId);
            }

            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($data, JSON_THROW_ON_ERROR));
            return;
        }

        // Try global lookup (room on another worker)
        $globalRoom = $this->roomManager->findRoomGlobal($roomId);
        if ($globalRoom !== null) {
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode($globalRoom, JSON_THROW_ON_ERROR));
            return;
        }

        $response->status(404);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['error' => 'Room not found']));
    }

    /**
     * Queue for matchmaking.
     */
    public function queueMatchmaking(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';
        $userId = Context::actorId() ?? 'anonymous';
        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $rating = (float)($body['rating'] ?? 1000);
        $fd = (int)($body['fd'] ?? 0);

        $this->matchmaker->queue($userId, $rating, $tenantId, $fd);

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode([
            'status' => 'queued',
            'player_id' => $userId,
            'rating' => $rating,
            'queue_size' => $this->matchmaker->queueSize($tenantId),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Leave matchmaking queue.
     */
    public function dequeueMatchmaking(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';
        $userId = Context::actorId() ?? 'anonymous';

        $this->matchmaker->dequeue($userId, $tenantId);

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['status' => 'dequeued']));
    }
}

