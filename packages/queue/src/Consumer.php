<?php

declare(strict_types=1);

namespace SwooleFabric\Queue;

use Swoole\Coroutine;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Storage\DbManager;

/**
 * Queue consumer — reads jobs from Redis Streams.
 *
 * Features:
 *   - Consumer group processing (each job delivered to one consumer)
 *   - Retry with configurable backoff
 *   - Dead Letter Queue (DLQ) after max attempts
 *   - Context reset per job
 *   - Idempotency check before processing
 *
 * Stream: sf:queue:{queueName}
 * Consumer group: sf_workers
 * DLQ: sf:dlq:{queueName}
 */
final class Consumer
{
    private bool $running = false;

    /** @var array<string, callable(array): void> jobType → handler */
    private array $handlers = [];

    private const STREAM_PREFIX = 'sf:queue:';
    private const DLQ_PREFIX = 'sf:dlq:';

    public function __construct(
        private readonly DbManager $db,
        private readonly ?IdempotencyStore $idempotencyStore = null,
        private readonly string $consumerGroup = 'sf_workers',
        private readonly int $maxAttempts = 3,
        private readonly array $backoff = [1, 5, 30],
    ) {}

    /**
     * Register a job handler.
     *
     * @param string $jobType
     * @param callable(array): void $handler  fn(payload)
     */
    public function registerHandler(string $jobType, callable $handler): void
    {
        $this->handlers[$jobType] = $handler;
    }

    /**
     * Start consuming from a queue.
     *
     * Blocks in a loop. Call from a dedicated coroutine or worker.
     *
     * @param string $queue Queue name
     * @param string $consumerName Unique consumer identifier
     */
    public function consume(string $queue, string $consumerName = ''): void
    {
        if ($consumerName === '') {
            $consumerName = 'consumer-' . getmypid() . '-' . bin2hex(random_bytes(4));
        }

        $streamKey = self::STREAM_PREFIX . $queue;
        $this->running = true;

        // Ensure consumer group exists
        $this->ensureConsumerGroup($streamKey);

        while ($this->running) {
            try {
                $redis = $this->db->redis();
                try {
                    // Read from stream (blocking with timeout)
                    $messages = $redis->xReadGroup(
                        $this->consumerGroup,
                        $consumerName,
                        [$streamKey => '>'],
                        1, // count
                        2000, // block 2 seconds
                    );
                } finally {
                    $this->db->releaseRedis($redis);
                }

                if (!is_array($messages) || empty($messages)) {
                    continue;
                }

                foreach ($messages as $stream => $entries) {
                    foreach ($entries as $messageId => $fields) {
                        $this->processMessage($queue, $streamKey, $messageId, $fields);
                    }
                }
            } catch (\Throwable $e) {
                // Log error and continue
                error_log("[SwooleFabric][Consumer] Error: {$e->getMessage()}");
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
     * Process a single message from the stream.
     */
    private function processMessage(string $queue, string $streamKey, string $messageId, array $fields): void
    {
        // Reset context for each job
        Context::reset();

        // Restore context from job fields
        if (isset($fields['tenant_id']) && $fields['tenant_id'] !== '') {
            Context::setTenantId($fields['tenant_id']);
        }
        if (isset($fields['actor_id']) && $fields['actor_id'] !== '') {
            Context::setActorId($fields['actor_id']);
        }
        if (isset($fields['correlation_id']) && $fields['correlation_id'] !== '') {
            Context::setCorrelationId($fields['correlation_id']);
        }

        $jobType = $fields['job_type'] ?? '';
        $payload = json_decode($fields['payload'] ?? '{}', true) ?? [];
        $attempts = (int) ($fields['attempts'] ?? 0);

        // Idempotency check
        $idempotencyKey = $fields['idempotency_key'] ?? null;
        if ($idempotencyKey !== null && $this->idempotencyStore !== null) {
            $tenantId = Context::tenantId() ?? '';
            $existing = $this->idempotencyStore->check($idempotencyKey, $tenantId);
            if ($existing !== null) {
                // Already processed — acknowledge and skip
                $this->ack($streamKey, $messageId);
                return;
            }
        }

        // Find handler
        $handler = $this->handlers[$jobType] ?? null;
        if ($handler === null) {
            error_log("[SwooleFabric][Consumer] No handler for job type: {$jobType}");
            $this->ack($streamKey, $messageId);
            return;
        }

        try {
            $handler($payload);

            // Acknowledge successful processing
            $this->ack($streamKey, $messageId);

            // Store idempotency result
            if ($idempotencyKey !== null && $this->idempotencyStore !== null) {
                $tenantId = Context::tenantId() ?? '';
                $this->idempotencyStore->store($idempotencyKey, $tenantId, ['status' => 'processed']);
            }
        } catch (\Throwable $e) {
            $attempts++;

            if ($attempts >= $this->maxAttempts) {
                // Move to DLQ
                $this->moveToDlq($queue, $messageId, $fields, $e->getMessage());
                $this->ack($streamKey, $messageId);
            } else {
                // Retry with backoff
                $backoffSeconds = $this->backoff[$attempts - 1] ?? end($this->backoff);
                error_log("[SwooleFabric][Consumer] Retrying {$jobType} (attempt {$attempts}) in {$backoffSeconds}s: {$e->getMessage()}");

                // Re-dispatch with incremented attempts
                Coroutine::sleep((float) $backoffSeconds);
                $this->redispatch($queue, $fields, $attempts);
                $this->ack($streamKey, $messageId);
            }
        }
    }

    /**
     * Acknowledge a message (mark as processed).
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
     * Move a failed message to the Dead Letter Queue.
     */
    private function moveToDlq(string $queue, string $messageId, array $fields, string $error): void
    {
        $dlqKey = self::DLQ_PREFIX . $queue;
        $fields['_error'] = $error;
        $fields['_original_id'] = $messageId;
        $fields['_failed_at'] = (string) microtime(true);

        $redis = $this->db->redis();
        try {
            $redis->xAdd($dlqKey, '*', $fields);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Re-dispatch a job with updated attempt count.
     */
    private function redispatch(string $queue, array $fields, int $attempts): void
    {
        $fields['attempts'] = (string) $attempts;
        $streamKey = self::STREAM_PREFIX . $queue;

        $redis = $this->db->redis();
        try {
            $redis->xAdd($streamKey, '*', $fields);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Ensure the consumer group exists for a stream.
     */
    private function ensureConsumerGroup(string $streamKey): void
    {
        $redis = $this->db->redis();
        try {
            // XGROUP CREATE — if group already exists, Redis returns an error we ignore
            @$redis->xGroup('CREATE', $streamKey, $this->consumerGroup, '0', true);
        } catch (\Throwable) {
            // Group may already exist — that's fine
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

