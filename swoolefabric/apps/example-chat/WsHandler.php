<?php

declare(strict_types=1);

namespace SwooleFabric\ExampleChat;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Realtime\Gateway;
use SwooleFabric\Realtime\PushService;
use SwooleFabric\Events\EventBus;

/**
 * WebSocket message handler for the chat application.
 *
 * Handles message types:
 *   - join_room:     Join a chat room
 *   - leave_room:    Leave a chat room
 *   - send_message:  Send a message to a room
 *   - typing:        Broadcast typing indicator
 *
 * Authentication is handled by WsAuthHandler (onOpen).
 * This handler processes messages from authenticated connections.
 */
final class WsHandler
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly PushService $pushService,
        private readonly ChatRepository $repo,
        private readonly ?EventBus $eventBus = null,
    ) {}

    /**
     * Handle incoming WebSocket message.
     */
    public function onMessage(WsServer $server, Frame $frame): void
    {
        $fd = $frame->fd;
        $meta = $this->gateway->getFdMeta($fd);

        if ($meta === null) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Not authenticated. Reconnect with a valid token.',
            ], JSON_THROW_ON_ERROR));
            return;
        }

        $tenantId = $meta['tenant_id'];
        $userId = $meta['user_id'];

        // Restore context
        Context::setTenantId($tenantId);
        Context::setActorId($userId);

        // Parse message
        $data = json_decode($frame->data, true);
        if (!is_array($data)) {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'Invalid JSON message',
            ], JSON_THROW_ON_ERROR));
            return;
        }

        $type = $data['type'] ?? '';

        match ($type) {
            'join_room'    => $this->handleJoinRoom($server, $fd, $tenantId, $userId, $data),
            'leave_room'   => $this->handleLeaveRoom($server, $fd, $tenantId, $userId, $data),
            'send_message' => $this->handleSendMessage($server, $fd, $tenantId, $userId, $data),
            'typing'       => $this->handleTyping($tenantId, $userId, $data),
            default        => $server->push($fd, json_encode([
                'type' => 'error',
                'message' => "Unknown message type: {$type}",
            ], JSON_THROW_ON_ERROR)),
        };
    }

    // ── Message Type Handlers ────────────────────────────────────────

    private function handleJoinRoom(WsServer $server, int $fd, string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'room_id is required',
            ], JSON_THROW_ON_ERROR));
            return;
        }

        $this->gateway->joinRoom($fd, $tenantId, $roomId);
        $this->repo->joinRoom($tenantId, $roomId, $userId);

        // Confirm to the user
        $server->push($fd, json_encode([
            'type' => 'room_joined',
            'room_id' => $roomId,
            'user_id' => $userId,
        ], JSON_THROW_ON_ERROR));

        // Notify room members
        $this->pushService->pushRoom($tenantId, $roomId, [
            'type' => 'user_joined',
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);
    }

    private function handleLeaveRoom(WsServer $server, int $fd, string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            return;
        }

        $this->gateway->leaveRoom($fd, $tenantId, $roomId);

        $server->push($fd, json_encode([
            'type' => 'room_left',
            'room_id' => $roomId,
        ], JSON_THROW_ON_ERROR));

        $this->pushService->pushRoom($tenantId, $roomId, [
            'type' => 'user_left',
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);
    }

    private function handleSendMessage(WsServer $server, int $fd, string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        $body = $data['body'] ?? '';

        if ($roomId === '' || $body === '') {
            $server->push($fd, json_encode([
                'type' => 'error',
                'message' => 'room_id and body are required',
            ], JSON_THROW_ON_ERROR));
            return;
        }

        $messageId = $this->generateUuid();

        $message = [
            'id' => $messageId,
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'user_id' => $userId,
            'body' => $body,
            'created_at' => date('c'),
        ];

        // Persist
        $this->repo->createMessage($message);

        // Confirm to sender
        $server->push($fd, json_encode([
            'type' => 'message_sent',
            'message' => $message,
        ], JSON_THROW_ON_ERROR));

        // Push to room via Redis Pub/Sub (reaches all workers)
        $this->pushService->pushRoom($tenantId, $roomId, [
            'type' => 'new_message',
            'message' => $message,
        ]);

        // Emit event for consumers (projections, counters, etc.)
        if ($this->eventBus !== null) {
            $this->eventBus->emit('message.sent', $message, "msg:{$messageId}");
        }
    }

    private function handleTyping(string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'] ?? '';
        if ($roomId === '') {
            return;
        }

        $this->pushService->pushRoom($tenantId, $roomId, [
            'type' => 'typing',
            'room_id' => $roomId,
            'user_id' => $userId,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

