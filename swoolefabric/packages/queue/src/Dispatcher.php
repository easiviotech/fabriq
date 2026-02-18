<?php

declare(strict_types=1);

namespace SwooleFabric\Queue;

use SwooleFabric\Kernel\Context;
use SwooleFabric\Storage\DbManager;

/**
 * Job dispatcher — publishes jobs to Redis Streams.
 *
 * Supports:
 *   - Immediate dispatch (XADD to stream)
 *   - Delayed dispatch (ZADD to delay ZSET, picked up by Scheduler)
 *   - Idempotency key attachment
 *   - Tenant and correlation context propagation
 *
 * Stream: sf:queue:{queueName}
 * Delay ZSET: sf:delayed:{queueName}
 */
final class Dispatcher
{
    private const STREAM_PREFIX = 'sf:queue:';
    private const DELAYED_PREFIX = 'sf:delayed:';

    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Dispatch a job immediately.
     *
     * @param string $queue        Queue name (e.g. 'default', 'messages')
     * @param string $jobType      Job class/type identifier
     * @param array<string, mixed> $payload  Job data
     * @param string|null $idempotencyKey Optional idempotency key
     * @return string Stream message ID
     */
    public function dispatch(
        string $queue,
        string $jobType,
        array $payload,
        ?string $idempotencyKey = null,
    ): string {
        $message = $this->buildMessage($jobType, $payload, $idempotencyKey);

        $redis = $this->db->redis();
        try {
            $streamKey = self::STREAM_PREFIX . $queue;
            $id = $redis->xAdd($streamKey, '*', $message);
            return $id ?: '';
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Dispatch a delayed job.
     *
     * The job will be moved to the stream after $delaySeconds.
     *
     * @param string $queue
     * @param string $jobType
     * @param array<string, mixed> $payload
     * @param int $delaySeconds
     * @param string|null $idempotencyKey
     */
    public function dispatchDelayed(
        string $queue,
        string $jobType,
        array $payload,
        int $delaySeconds,
        ?string $idempotencyKey = null,
    ): void {
        $message = $this->buildMessage($jobType, $payload, $idempotencyKey);
        $message['_queue'] = $queue;

        $executeAt = microtime(true) + $delaySeconds;
        $serialized = json_encode($message, JSON_THROW_ON_ERROR);

        $redis = $this->db->redis();
        try {
            $zsetKey = self::DELAYED_PREFIX . $queue;
            $redis->zAdd($zsetKey, $executeAt, $serialized);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Build the job message envelope.
     *
     * @return array<string, string>
     */
    private function buildMessage(string $jobType, array $payload, ?string $idempotencyKey): array
    {
        $message = [
            'job_type' => $jobType,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'tenant_id' => Context::tenantId() ?? '',
            'actor_id' => Context::actorId() ?? '',
            'correlation_id' => Context::correlationId() ?? '',
            'request_id' => Context::requestId() ?? '',
            'dispatched_at' => (string) microtime(true),
            'attempts' => '0',
        ];

        if ($idempotencyKey !== null) {
            $message['idempotency_key'] = $idempotencyKey;
        }

        return $message;
    }
}

