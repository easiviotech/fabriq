<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Fabriq\Kernel\Config;
use Fabriq\Observability\Logger;
use Fabriq\Storage\DbManager;
use Swoole\Timer;

/**
 * Redis ZSET-based skill matchmaking.
 *
 * Players queue with their skill rating. The matchmaker periodically
 * polls Redis to find groups of players within a rating range, then
 * creates game rooms and notifies matched players.
 *
 * Algorithm:
 *   1. Player calls queue() → added to Redis ZSET (score = rating)
 *   2. Background poll (every 1s) scans the ZSET
 *   3. For each player, find others within ±rating_range
 *   4. If enough players found → create room, notify via callback
 *   5. If player waits too long → expand range, then timeout
 *
 * Uses O(log N) Redis sorted set operations for efficiency.
 */
final class Matchmaker
{
    private int $ratingRange;
    private int $expandAfterSeconds;
    private int $maxWaitSeconds;

    /** @var callable(list<array{player_id: string, rating: float, tenant_id: string, fd: int}>): void */
    private $matchCallback;

    private ?int $timerId = null;
    private bool $running = false;
    private int $playersPerMatch;

    public function __construct(
        private readonly DbManager $db,
        private readonly ?Config $config = null,
        private readonly ?Logger $logger = null,
    ) {
        $this->ratingRange = (int)($config?->get('gaming.matchmaking.rating_range', 100) ?? 100);
        $this->expandAfterSeconds = (int)($config?->get('gaming.matchmaking.expand_after_seconds', 10) ?? 10);
        $this->maxWaitSeconds = (int)($config?->get('gaming.matchmaking.max_wait_seconds', 60) ?? 60);
        $this->matchCallback = fn() => null;
        $this->playersPerMatch = 2;
    }

    /**
     * Set the callback to invoke when a match is found.
     *
     * @param callable(list<array{player_id: string, rating: float, tenant_id: string, fd: int}>): void $callback
     */
    public function onMatch(callable $callback): void
    {
        $this->matchCallback = $callback;
    }

    /**
     * Set the number of players required for a match.
     */
    public function setPlayersPerMatch(int $count): void
    {
        $this->playersPerMatch = max(2, $count);
    }

    /**
     * Queue a player for matchmaking.
     *
     * @param string $playerId Unique player identifier
     * @param float $skillRating Player's skill rating
     * @param string $tenantId Tenant scope
     * @param int $fd WebSocket file descriptor (for notification)
     */
    public function queue(string $playerId, float $skillRating, string $tenantId, int $fd): void
    {
        $redis = $this->db->redis();
        try {
            // Add to main matchmaking ZSET (score = rating)
            $redis->zAdd($this->queueKey($tenantId), $skillRating, $playerId);

            // Store player metadata
            $redis->hSet($this->metaKey($tenantId), $playerId, json_encode([
                'player_id' => $playerId,
                'rating' => $skillRating,
                'tenant_id' => $tenantId,
                'fd' => $fd,
                'queued_at' => time(),
            ], JSON_THROW_ON_ERROR));

            $this->logger?->info('Player queued for matchmaking', [
                'player_id' => $playerId,
                'rating' => $skillRating,
                'tenant_id' => $tenantId,
            ]);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Remove a player from the matchmaking queue.
     */
    public function dequeue(string $playerId, string $tenantId): void
    {
        $redis = $this->db->redis();
        try {
            $redis->zRem($this->queueKey($tenantId), $playerId);
            $redis->hDel($this->metaKey($tenantId), $playerId);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Start the matchmaking polling loop.
     *
     * @param string $tenantId Tenant to poll for
     * @param int $pollIntervalMs Poll interval in milliseconds
     */
    public function start(string $tenantId, int $pollIntervalMs = 1000): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $this->timerId = Timer::tick($pollIntervalMs, function () use ($tenantId) {
            $this->poll($tenantId);
        });

        $this->logger?->info('Matchmaker started', ['tenant_id' => $tenantId]);
    }

    /**
     * Stop the matchmaking loop.
     */
    public function stop(): void
    {
        $this->running = false;

        if ($this->timerId !== null) {
            Timer::clear($this->timerId);
            $this->timerId = null;
        }
    }

    /**
     * Get the queue size for a tenant.
     */
    public function queueSize(string $tenantId): int
    {
        $redis = $this->db->redis();
        try {
            return (int)$redis->zCard($this->queueKey($tenantId));
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    // ── Internal Polling ────────────────────────────────────────────

    /**
     * Poll the matchmaking queue and find matches.
     */
    private function poll(string $tenantId): void
    {
        $redis = $this->db->redis();
        try {
            $queueKey = $this->queueKey($tenantId);
            $metaKey = $this->metaKey($tenantId);

            // Get all queued players with their ratings
            $players = $redis->zRange($queueKey, 0, -1, true);
            if (!is_array($players) || count($players) < $this->playersPerMatch) {
                return;
            }

            $now = time();
            $matched = [];
            $toRemove = [];

            // Sort by rating for efficient range matching
            $sorted = [];
            foreach ($players as $playerId => $rating) {
                $meta = $redis->hGet($metaKey, $playerId);
                if ($meta === false) {
                    $toRemove[] = $playerId;
                    continue;
                }

                $info = json_decode($meta, true);
                if (!is_array($info)) {
                    $toRemove[] = $playerId;
                    continue;
                }

                // Check timeout
                $queuedAt = (int)($info['queued_at'] ?? 0);
                if ($now - $queuedAt > $this->maxWaitSeconds) {
                    $toRemove[] = $playerId;
                    continue;
                }

                // Expand range for long-waiting players
                $waitTime = $now - $queuedAt;
                $effectiveRange = $this->ratingRange;
                if ($waitTime > $this->expandAfterSeconds) {
                    $expansionFactor = 1 + (($waitTime - $this->expandAfterSeconds) / $this->expandAfterSeconds);
                    $effectiveRange = (int)($this->ratingRange * $expansionFactor);
                }

                $sorted[] = array_merge($info, ['effective_range' => $effectiveRange]);
            }

            // Clean up stale entries
            foreach ($toRemove as $playerId) {
                $redis->zRem($queueKey, $playerId);
                $redis->hDel($metaKey, $playerId);
            }

            // Simple greedy matching
            $used = [];
            foreach ($sorted as $i => $player) {
                if (isset($used[$player['player_id']])) {
                    continue;
                }

                $group = [$player];
                $rating = (float)$player['rating'];
                $range = (int)$player['effective_range'];

                for ($j = $i + 1; $j < count($sorted) && count($group) < $this->playersPerMatch; $j++) {
                    $candidate = $sorted[$j];
                    if (isset($used[$candidate['player_id']])) {
                        continue;
                    }

                    if (abs((float)$candidate['rating'] - $rating) <= $range) {
                        $group[] = $candidate;
                    }
                }

                if (count($group) >= $this->playersPerMatch) {
                    // Match found!
                    foreach ($group as $p) {
                        $used[$p['player_id']] = true;
                        $redis->zRem($queueKey, $p['player_id']);
                        $redis->hDel($metaKey, $p['player_id']);
                    }

                    $matched[] = $group;
                }
            }

            // Invoke match callbacks
            foreach ($matched as $group) {
                ($this->matchCallback)($group);

                $this->logger?->info('Match found', [
                    'players' => array_column($group, 'player_id'),
                    'tenant_id' => $tenantId,
                ]);
            }
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    // ── Key Helpers ─────────────────────────────────────────────────

    private function queueKey(string $tenantId): string
    {
        return "matchmaking:{$tenantId}:queue";
    }

    private function metaKey(string $tenantId): string
    {
        return "matchmaking:{$tenantId}:meta";
    }
}

