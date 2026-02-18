<?php

declare(strict_types=1);

namespace SwooleFabric\Events;

use SwooleFabric\Kernel\Context;
use SwooleFabric\Storage\DbManager;

/**
 * Event bus — publishes domain events to Redis Streams.
 *
 * Events are published with schema validation and context propagation.
 *
 * Stream: sf:events:{eventType}   (one stream per event type)
 */
final class EventBus
{
    private const STREAM_PREFIX = 'sf:events:';

    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Publish a pre-built event schema.
     *
     * @return string Stream message ID
     */
    public function publish(EventSchema $event): string
    {
        $streamKey = self::STREAM_PREFIX . $event->eventType;
        $fields = $event->toStreamFields();

        $redis = $this->db->redis();
        try {
            $id = $redis->xAdd($streamKey, '*', $fields);
            return $id ?: '';
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Emit an event with auto-populated context.
     *
     * Convenience method that builds EventSchema from current Context.
     *
     * @param string $eventType
     * @param array<string, mixed> $payload
     * @param string|null $dedupeKey
     * @return string Stream message ID
     */
    public function emit(string $eventType, array $payload, ?string $dedupeKey = null): string
    {
        $event = EventSchema::create(
            eventType: $eventType,
            tenantId: Context::tenantId() ?? '',
            payload: $payload,
            dedupeKey: $dedupeKey,
            correlationId: Context::correlationId() ?? '',
            actorId: Context::actorId() ?? '',
        );

        return $this->publish($event);
    }
}

