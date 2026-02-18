<?php

declare(strict_types=1);

namespace SwooleFabric\Queue;

use Swoole\Coroutine;
use SwooleFabric\Storage\DbManager;

/**
 * Job scheduler — cron-like timer that promotes delayed jobs.
 *
 * Two responsibilities:
 *   1. Move delayed jobs (ZSET) to their target stream when due
 *   2. Dispatch recurring scheduled jobs at configured intervals
 *
 * Runs in a long-lived coroutine loop.
 */
final class Scheduler
{
    private bool $running = false;

    /** @var list<array{queue: string, job_type: string, payload: array, interval: int, last_run: float}> */
    private array $schedules = [];

    private const DELAYED_PREFIX = 'sf:delayed:';
    private const STREAM_PREFIX = 'sf:queue:';

    public function __construct(
        private readonly DbManager $db,
        private readonly float $pollInterval = 1.0,
    ) {}

    /**
     * Register a recurring scheduled job.
     *
     * @param string $queue     Target queue
     * @param string $jobType   Job type identifier
     * @param array<string, mixed> $payload  Static payload
     * @param int $intervalSeconds  Run every N seconds
     */
    public function every(string $queue, string $jobType, array $payload, int $intervalSeconds): void
    {
        $this->schedules[] = [
            'queue' => $queue,
            'job_type' => $jobType,
            'payload' => $payload,
            'interval' => $intervalSeconds,
            'last_run' => 0.0,
        ];
    }

    /**
     * Start the scheduler loop.
     */
    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            while ($this->running) {
                $this->tick();
                Coroutine::sleep($this->pollInterval);
            }
        });
    }

    /**
     * Stop the scheduler.
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Execute one scheduler tick:
     *   1. Promote due delayed jobs
     *   2. Dispatch due recurring jobs
     */
    private function tick(): void
    {
        $this->promoteDelayedJobs();
        $this->dispatchRecurringJobs();
    }

    /**
     * Check all delayed ZSETs and move due jobs to their streams.
     */
    private function promoteDelayedJobs(): void
    {
        $now = microtime(true);
        $queues = $this->getDelayedQueues();

        foreach ($queues as $queue) {
            $zsetKey = self::DELAYED_PREFIX . $queue;

            $redis = $this->db->redis();
            try {
                // Get all jobs with score <= now (due)
                $dueJobs = $redis->zRangeByScore($zsetKey, '-inf', (string) $now, ['limit' => [0, 100]]);

                if (!is_array($dueJobs) || empty($dueJobs)) {
                    continue;
                }

                foreach ($dueJobs as $serialized) {
                    $message = json_decode($serialized, true);
                    if (!is_array($message)) {
                        continue;
                    }

                    $targetQueue = $message['_queue'] ?? $queue;
                    unset($message['_queue']);

                    $streamKey = self::STREAM_PREFIX . $targetQueue;
                    $redis->xAdd($streamKey, '*', $message);
                    $redis->zRem($zsetKey, $serialized);
                }
            } catch (\Throwable $e) {
                error_log("[SwooleFabric][Scheduler] Error promoting delayed jobs: {$e->getMessage()}");
            } finally {
                $this->db->releaseRedis($redis);
            }
        }
    }

    /**
     * Dispatch recurring scheduled jobs that are due.
     */
    private function dispatchRecurringJobs(): void
    {
        $now = microtime(true);

        foreach ($this->schedules as &$schedule) {
            if (($now - $schedule['last_run']) >= $schedule['interval']) {
                $schedule['last_run'] = $now;

                $streamKey = self::STREAM_PREFIX . $schedule['queue'];
                $message = [
                    'job_type' => $schedule['job_type'],
                    'payload' => json_encode($schedule['payload'], JSON_THROW_ON_ERROR),
                    'tenant_id' => '',
                    'actor_id' => 'scheduler',
                    'correlation_id' => bin2hex(random_bytes(16)),
                    'request_id' => bin2hex(random_bytes(8)),
                    'dispatched_at' => (string) $now,
                    'attempts' => '0',
                ];

                $redis = $this->db->redis();
                try {
                    $redis->xAdd($streamKey, '*', $message);
                } catch (\Throwable $e) {
                    error_log("[SwooleFabric][Scheduler] Error dispatching scheduled job: {$e->getMessage()}");
                } finally {
                    $this->db->releaseRedis($redis);
                }
            }
        }
        unset($schedule);
    }

    /**
     * Get known delayed queue names.
     *
     * @return list<string>
     */
    private function getDelayedQueues(): array
    {
        // Scan for sf:delayed:* keys
        $redis = $this->db->redis();
        try {
            $keys = [];
            $cursor = '0';
            do {
                $result = $redis->scan($cursor, self::DELAYED_PREFIX . '*', 100);
                if (is_array($result)) {
                    $keys = array_merge($keys, $result);
                }
            } while ($cursor !== '0' && $cursor !== 0);

            // Extract queue names from keys
            $queues = [];
            $prefixLen = strlen(self::DELAYED_PREFIX);
            foreach ($keys as $key) {
                $queues[] = substr($key, $prefixLen);
            }
            return $queues;
        } catch (\Throwable) {
            return ['default'];
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

