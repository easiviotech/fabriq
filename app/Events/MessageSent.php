<?php

declare(strict_types=1);

namespace App\Events;

/**
 * MessageSent domain event.
 *
 * Fired when a chat message is persisted (via HTTP or WebSocket).
 * Consumed by listeners to update projections, counters, notifications, etc.
 */
final class MessageSent
{
    public const EVENT_TYPE = 'message.sent';

    public function __construct(
        public readonly string $messageId,
        public readonly string $tenantId,
        public readonly string $roomId,
        public readonly string $userId,
        public readonly string $body,
        public readonly string $createdAt,
    ) {}

    /**
     * Build from a payload array (e.g., from EventSchema).
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            messageId: $payload['id'] ?? '',
            tenantId: $payload['tenant_id'] ?? '',
            roomId: $payload['room_id'] ?? '',
            userId: $payload['user_id'] ?? '',
            body: $payload['body'] ?? '',
            createdAt: $payload['created_at'] ?? '',
        );
    }

    /**
     * Convert to array for queue/event payloads.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->messageId,
            'tenant_id' => $this->tenantId,
            'room_id' => $this->roomId,
            'user_id' => $this->userId,
            'body' => $this->body,
            'created_at' => $this->createdAt,
        ];
    }
}

