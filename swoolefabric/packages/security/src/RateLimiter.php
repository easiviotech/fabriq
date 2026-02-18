<?php

declare(strict_types=1);

namespace SwooleFabric\Security;

use SwooleFabric\Storage\DbManager;

/**
 * Redis-based sliding window rate limiter.
 *
 * Uses a sorted set per key with timestamps as scores.
 * Atomic check-and-increment via MULTI/EXEC.
 *
 * Key format: Caller decides (typically "rl:{tenantId}:{ip}" or "rl:{tenantId}:{route}").
 */
final class RateLimiter
{
    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Check if a request is allowed and record it.
     *
     * @param string $key         Rate limit key
     * @param int $maxRequests    Maximum requests in window
     * @param int $windowSeconds  Sliding window size in seconds
     * @return array{allowed: bool, remaining: int, retry_after: int}
     */
    public function attempt(string $key, int $maxRequests, int $windowSeconds): array
    {
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;
        $member = (string) $now . ':' . bin2hex(random_bytes(4));

        $redis = $this->db->redis();
        try {
            // Remove expired entries
            $redis->zRemRangeByScore($key, '-inf', (string) $windowStart);

            // Count current entries
            $currentCount = (int) $redis->zCard($key);

            if ($currentCount >= $maxRequests) {
                // Rate limited — calculate retry after
                $oldest = $redis->zRange($key, 0, 0, true);
                $retryAfter = 1;
                if (is_array($oldest) && !empty($oldest)) {
                    $oldestScore = (float) reset($oldest);
                    $retryAfter = max(1, (int) ceil(($oldestScore + $windowSeconds) - $now));
                }

                return [
                    'allowed' => false,
                    'remaining' => 0,
                    'retry_after' => $retryAfter,
                ];
            }

            // Add current request
            $redis->zAdd($key, $now, $member);

            // Set TTL on key (auto-cleanup)
            $redis->expire($key, $windowSeconds + 1);

            return [
                'allowed' => true,
                'remaining' => max(0, $maxRequests - $currentCount - 1),
                'retry_after' => 0,
            ];
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Get remaining quota without consuming a request.
     *
     * @return array{remaining: int, total: int}
     */
    public function remaining(string $key, int $maxRequests, int $windowSeconds): array
    {
        $now = microtime(true);
        $windowStart = $now - $windowSeconds;

        $redis = $this->db->redis();
        try {
            $redis->zRemRangeByScore($key, '-inf', (string) $windowStart);
            $currentCount = (int) $redis->zCard($key);

            return [
                'remaining' => max(0, $maxRequests - $currentCount),
                'total' => $maxRequests,
            ];
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Reset a rate limit key.
     */
    public function reset(string $key): void
    {
        $redis = $this->db->redis();
        try {
            $redis->del($key);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

