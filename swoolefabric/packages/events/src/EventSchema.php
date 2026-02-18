<?php

declare(strict_types=1);

namespace SwooleFabric\Events;

/**
 * Event schema — defines the structure of events on the bus.
 *
 * Immutable value object for type-safe event creation.
 */
final class EventSchema
{
    /**
     * @param string $eventType   Event type identifier (e.g. 'message.sent', 'user.created')
     * @param string $tenantId    Tenant that owns this event
     * @param array<string, mixed> $payload  Event data
     * @param string $dedupeKey   Unique key for deduplication
     * @param float $timestamp    When the event occurred
     * @param string $correlationId  Correlation ID for tracing
     * @param string $actorId     Who triggered the event
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $tenantId,
        public readonly array $payload,
        public readonly string $dedupeKey,
        public readonly float $timestamp,
        public readonly string $correlationId = '',
        public readonly string $actorId = '',
    ) {}

    /**
     * Create an event from context and payload.
     *
     * @param string $eventType
     * @param string $tenantId
     * @param array<string, mixed> $payload
     * @param string|null $dedupeKey  If null, auto-generated
     * @param string $correlationId
     * @param string $actorId
     */
    public static function create(
        string $eventType,
        string $tenantId,
        array $payload,
        ?string $dedupeKey = null,
        string $correlationId = '',
        string $actorId = '',
    ): self {
        return new self(
            eventType: $eventType,
            tenantId: $tenantId,
            payload: $payload,
            dedupeKey: $dedupeKey ?? bin2hex(random_bytes(16)),
            timestamp: microtime(true),
            correlationId: $correlationId,
            actorId: $actorId,
        );
    }

    /**
     * Serialize to array for stream storage.
     *
     * @return array<string, string>
     */
    public function toStreamFields(): array
    {
        return [
            'event_type' => $this->eventType,
            'tenant_id' => $this->tenantId,
            'payload' => json_encode($this->payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => $this->dedupeKey,
            'timestamp' => (string) $this->timestamp,
            'correlation_id' => $this->correlationId,
            'actor_id' => $this->actorId,
        ];
    }

    /**
     * Reconstruct from stream fields.
     */
    public static function fromStreamFields(array $fields): self
    {
        return new self(
            eventType: $fields['event_type'] ?? '',
            tenantId: $fields['tenant_id'] ?? '',
            payload: json_decode($fields['payload'] ?? '{}', true) ?? [],
            dedupeKey: $fields['dedupe_key'] ?? '',
            timestamp: (float) ($fields['timestamp'] ?? 0),
            correlationId: $fields['correlation_id'] ?? '',
            actorId: $fields['actor_id'] ?? '',
        );
    }
}

