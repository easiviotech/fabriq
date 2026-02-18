<?php

declare(strict_types=1);

namespace SwooleFabric\Realtime;

use SwooleFabric\Storage\DbManager;

/**
 * Stateless push service — publishes messages via Redis Pub/Sub.
 *
 * Business workers call these methods to push data to WebSocket clients.
 * The actual delivery happens in each Gateway worker via RealtimeSubscriber.
 *
 * Channels:
 *   sf:push:user:{tenantId}:{userId}   → target specific user
 *   sf:push:room:{tenantId}:{roomId}   → target all users in a room
 *   sf:push:topic:{topic}              → broadcast to topic subscribers
 */
final class PushService
{
    private const CHANNEL_USER  = 'sf:push:user:';
    private const CHANNEL_ROOM  = 'sf:push:room:';
    private const CHANNEL_TOPIC = 'sf:push:topic:';

    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Push to a specific user across all workers.
     *
     * @param string $tenantId
     * @param string $userId
     * @param array<string, mixed> $payload
     */
    public function pushUser(string $tenantId, string $userId, array $payload): void
    {
        $channel = self::CHANNEL_USER . "{$tenantId}:{$userId}";
        $this->publish($channel, [
            'type' => 'user',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'payload' => $payload,
        ]);
    }

    /**
     * Push to all members of a room across all workers.
     *
     * @param string $tenantId
     * @param string $roomId
     * @param array<string, mixed> $payload
     */
    public function pushRoom(string $tenantId, string $roomId, array $payload): void
    {
        $channel = self::CHANNEL_ROOM . "{$tenantId}:{$roomId}";
        $this->publish($channel, [
            'type' => 'room',
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'payload' => $payload,
        ]);
    }

    /**
     * Push to a global topic (cross-tenant broadcast).
     *
     * @param string $topic
     * @param array<string, mixed> $payload
     */
    public function pushTopic(string $topic, array $payload): void
    {
        $channel = self::CHANNEL_TOPIC . $topic;
        $this->publish($channel, [
            'type' => 'topic',
            'topic' => $topic,
            'payload' => $payload,
        ]);
    }

    /**
     * Publish a message to a Redis channel.
     *
     * @param string $channel
     * @param array<string, mixed> $message
     */
    private function publish(string $channel, array $message): void
    {
        $redis = $this->db->redis();
        try {
            $redis->publish($channel, json_encode($message, JSON_THROW_ON_ERROR));
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

