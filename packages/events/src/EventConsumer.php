<?php

declare(strict_types=1);

namespace SwooleFabric\Events;

use Swoole\Coroutine;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Storage\DbManager;

/**
 * Event consumer — reads events from Redis Streams with deduplication.
 *
 * Subscribes to event type streams and dispatches to registered handlers.
 * Deduplication uses Redis SET to track processed dedupe keys.
 *
 * Stream: sf:events:{eventType}
 * Consumer group: sf_consumers
 * Dedupe set: sf:dedupe:{consumerName}
 */
final class EventConsumer
{
    private bool $running = false;

    /** @var array<string, callable(EventSchema): void> eventType → handler */
    private array $handlers = [];

    private const STREAM_PREFIX = 'sf:events:';
    private const DEDUPE_PREFIX = 'sf:dedupe:';
    private const DEDUPE_TTL = 86400; // 24 hours

    public function __construct(
        private readonly DbManager $db,
        private readonly string $consumerGroup = 'sf_consumers',
    ) {}

    /**
     * Register an event handler.
     *
     * @param string $eventType
     * @param callable(EventSchema): void $handler
     */
    public function on(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /**
     * Start consuming events.
     *
     * @param string $consumerName Unique consumer identifier
     */
    public function consume(string $consumerName = ''): void
    {
        if ($consumerName === '') {
            $consumerName = 'econsumer-' . getmypid() . '-' . bin2hex(random_bytes(4));
        }

        $this->running = true;

        // Build stream keys from registered handlers
        $streams = [];
        foreach (array_keys($this->handlers) as $eventType) {
            $streamKey = self::STREAM_PREFIX . $eventType;
            $this->ensureConsumerGroup($streamKey);
            $streams[$streamKey] = '>';
        }

        if (empty($streams)) {
            return;
        }

        while ($this->running) {
            try {
                $redis = $this->db->redis();
                try {
                    $messages = $redis->xReadGroup(
                        $this->consumerGroup,
                        $consumerName,
                        $streams,
                        1,
                        2000, // block 2 seconds
                    );
                } finally {
                    $this->db->releaseRedis($redis);
                }

                if (!is_array($messages) || empty($messages)) {
                    continue;
                }

                foreach ($messages as $streamKey => $entries) {
                    $eventType = $this->extractEventType($streamKey);

                    foreach ($entries as $messageId => $fields) {
                        $this->processEvent($streamKey, $messageId, $eventType, $fields, $consumerName);
                    }
                }
            } catch (\Throwable $e) {
                error_log("[SwooleFabric][EventConsumer] Error: {$e->getMessage()}");
                Coroutine::sleep(1.0);
            }
        }
    }

    /**
     * Stop consuming.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Process a single event message.
     */
    private function processEvent(
        string $streamKey,
        string $messageId,
        string $eventType,
        array $fields,
        string $consumerName,
    ): void {
        $event = EventSchema::fromStreamFields($fields);

        // Dedupe check
        if ($event->dedupeKey !== '' && $this->isDuplicate($event->dedupeKey, $consumerName)) {
            $this->ack($streamKey, $messageId);
            return;
        }

        // Reset context
        Context::reset();
        if ($event->tenantId !== '') {
            Context::setTenantId($event->tenantId);
        }
        if ($event->actorId !== '') {
            Context::setActorId($event->actorId);
        }
        if ($event->correlationId !== '') {
            Context::setCorrelationId($event->correlationId);
        }

        // Find and execute handler
        $handler = $this->handlers[$eventType] ?? null;
        if ($handler === null) {
            $this->ack($streamKey, $messageId);
            return;
        }

        try {
            $handler($event);

            // Mark as processed for dedupe
            if ($event->dedupeKey !== '') {
                $this->markProcessed($event->dedupeKey, $consumerName);
            }
        } catch (\Throwable $e) {
            error_log("[SwooleFabric][EventConsumer] Handler error for {$eventType}: {$e->getMessage()}");
        }

        $this->ack($streamKey, $messageId);
    }

    /**
     * Check if an event has already been processed (dedupe).
     */
    private function isDuplicate(string $dedupeKey, string $consumerName): bool
    {
        $redis = $this->db->redis();
        try {
            $setKey = self::DEDUPE_PREFIX . $consumerName;
            return (bool) $redis->sIsMember($setKey, $dedupeKey);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Mark a dedupe key as processed.
     */
    private function markProcessed(string $dedupeKey, string $consumerName): void
    {
        $redis = $this->db->redis();
        try {
            $setKey = self::DEDUPE_PREFIX . $consumerName;
            $redis->sAdd($setKey, $dedupeKey);
            // Set TTL on the whole set (refreshes on each add)
            $redis->expire($setKey, self::DEDUPE_TTL);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Acknowledge a message.
     */
    private function ack(string $streamKey, string $messageId): void
    {
        $redis = $this->db->redis();
        try {
            $redis->xAck($streamKey, $this->consumerGroup, [$messageId]);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Extract event type from stream key.
     */
    private function extractEventType(string $streamKey): string
    {
        return substr($streamKey, strlen(self::STREAM_PREFIX));
    }

    /**
     * Ensure consumer group exists for a stream.
     */
    private function ensureConsumerGroup(string $streamKey): void
    {
        $redis = $this->db->redis();
        try {
            @$redis->xGroup('CREATE', $streamKey, $this->consumerGroup, '0', true);
        } catch (\Throwable) {
            // Group may already exist
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

