<?php

declare(strict_types=1);

namespace App\Streaming;

use Fabriq\Realtime\Gateway;
use Fabriq\Streaming\ChatModerator;
use Fabriq\Streaming\ViewerTracker;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

/**
 * WebSocket handler for live stream chat.
 *
 * Handles chat messages, viewer heartbeats, and moderation commands.
 *
 * Message types:
 *   - chat:       Send a chat message
 *   - heartbeat:  Viewer heartbeat (keeps viewer count accurate)
 *   - ban:        Ban a user (moderator only)
 *   - unban:      Unban a user (moderator only)
 */
final class StreamChatHandler
{
    public function __construct(
        private readonly ChatModerator $moderator,
        private readonly ViewerTracker $viewerTracker,
        private readonly Gateway $gateway,
    ) {}

    /**
     * Handle an incoming chat-related WebSocket message.
     */
    public function handle(
        WsServer $server,
        Frame $frame,
        string $tenantId,
        string $userId,
        array $data,
    ): void {
        $type = $data['type'] ?? '';
        $streamId = $data['stream_id'] ?? '';

        if ($streamId === '') {
            $server->push($frame->fd, json_encode(['error' => 'Missing stream_id']));
            return;
        }

        match ($type) {
            'chat' => $this->handleChat($server, $frame, $tenantId, $userId, $streamId, $data),
            'heartbeat' => $this->handleHeartbeat($tenantId, $userId, $streamId),
            'ban' => $this->handleBan($server, $frame, $tenantId, $streamId, $data),
            'unban' => $this->handleUnban($server, $frame, $tenantId, $streamId, $data),
            default => $server->push($frame->fd, json_encode(['error' => 'Unknown chat action'])),
        };
    }

    private function handleChat(
        WsServer $server,
        Frame $frame,
        string $tenantId,
        string $userId,
        string $streamId,
        array $data,
    ): void {
        $message = $data['message'] ?? '';

        $validation = $this->moderator->validate($tenantId, $streamId, $userId, $message);

        if (!$validation['allowed']) {
            $server->push($frame->fd, json_encode([
                'type' => 'chat_rejected',
                'reason' => $validation['reason'],
            ]));
            return;
        }

        // Broadcast chat message to all viewers in the stream room
        $chatMessage = json_encode([
            'type' => 'chat',
            'stream_id' => $streamId,
            'user_id' => $userId,
            'message' => $message,
            'timestamp' => time(),
        ], JSON_THROW_ON_ERROR);

        $roomId = "stream:{$streamId}";
        $this->gateway->pushToRoom($server, $tenantId, $roomId, $chatMessage);
    }

    private function handleHeartbeat(string $tenantId, string $userId, string $streamId): void
    {
        $this->viewerTracker->heartbeat($tenantId, $streamId, $userId);
    }

    private function handleBan(
        WsServer $server,
        Frame $frame,
        string $tenantId,
        string $streamId,
        array $data,
    ): void {
        $targetUserId = $data['user_id'] ?? '';
        $duration = (int)($data['duration'] ?? 0);

        if ($targetUserId === '') {
            $server->push($frame->fd, json_encode(['error' => 'Missing user_id']));
            return;
        }

        $this->moderator->ban($tenantId, $streamId, $targetUserId, $duration);

        $server->push($frame->fd, json_encode([
            'type' => 'user_banned',
            'user_id' => $targetUserId,
            'duration' => $duration,
        ]));
    }

    private function handleUnban(
        WsServer $server,
        Frame $frame,
        string $tenantId,
        string $streamId,
        array $data,
    ): void {
        $targetUserId = $data['user_id'] ?? '';

        if ($targetUserId === '') {
            $server->push($frame->fd, json_encode(['error' => 'Missing user_id']));
            return;
        }

        $this->moderator->unban($tenantId, $streamId, $targetUserId);

        $server->push($frame->fd, json_encode([
            'type' => 'user_unbanned',
            'user_id' => $targetUserId,
        ]));
    }
}

