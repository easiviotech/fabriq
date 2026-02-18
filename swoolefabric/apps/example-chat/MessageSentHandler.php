<?php

declare(strict_types=1);

namespace SwooleFabric\ExampleChat;

use SwooleFabric\Events\EventSchema;
use SwooleFabric\Observability\Logger;

/**
 * Event consumer handler for MessageSent events.
 *
 * Processes message.sent events:
 *   - Updates room message counters
 *   - Could update projections, search indices, notifications, etc.
 *
 * Registered with EventConsumer in the worker bootstrap.
 */
final class MessageSentHandler
{
    /** @var array<string, int> roomId → message count (in-memory projection) */
    private array $counters = [];

    public function __construct(
        private readonly ChatRepository $repo,
        private readonly ?Logger $logger = null,
    ) {}

    /**
     * Handle a message.sent event.
     */
    public function __invoke(EventSchema $event): void
    {
        $payload = $event->payload;
        $roomId = $payload['room_id'] ?? '';
        $tenantId = $event->tenantId;
        $messageId = $payload['id'] ?? '';

        $this->logger?->info('Processing message.sent event', [
            'message_id' => $messageId,
            'room_id' => $roomId,
            'tenant_id' => $tenantId,
            'dedupe_key' => $event->dedupeKey,
        ]);

        // Update in-memory counter
        $key = "{$tenantId}:{$roomId}";
        if (!isset($this->counters[$key])) {
            $this->counters[$key] = 0;
        }
        $this->counters[$key]++;

        $this->logger?->debug('Message counter updated', [
            'room_key' => $key,
            'count' => $this->counters[$key],
        ]);
    }

    /**
     * Get message count for a room (from in-memory projection).
     */
    public function getCount(string $tenantId, string $roomId): int
    {
        return $this->counters["{$tenantId}:{$roomId}"] ?? 0;
    }

    /**
     * Get all counters.
     *
     * @return array<string, int>
     */
    public function allCounters(): array
    {
        return $this->counters;
    }
}

