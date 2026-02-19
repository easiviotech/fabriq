<?php

declare(strict_types=1);

namespace Fabriq\Queue;

use Fabriq\Storage\DbManager;

/**
 * Idempotency store — ensures operations are executed at most once.
 *
 * Strategy: Redis-first for speed, platform DB for durability.
 *
 * Used by HTTP (Idempotency-Key header), WS commands, and job dispatch
 * to prevent duplicate processing.
 *
 * Keys: sf:idempotency:{tenantId}:{key}
 */
final class IdempotencyStore
{
    private const PREFIX = 'sf:idempotency:';

    /** Default TTL: 24 hours */
    private const DEFAULT_TTL = 86400;

    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Check if an idempotency key exists (already processed).
     *
     * @return array<string, mixed>|null  The stored result, or null if not found
     */
    public function check(string $key, string $tenantId): ?array
    {
        $redisKey = self::PREFIX . "{$tenantId}:{$key}";

        $redis = $this->db->redis();
        try {
            $data = $redis->get($redisKey);
            if ($data !== false && $data !== null) {
                $decoded = json_decode($data, true);
                return is_array($decoded) ? $decoded : null;
            }
        } finally {
            $this->db->releaseRedis($redis);
        }

        // Fallback: check platform DB
        return $this->checkDb($key, $tenantId);
    }

    /**
     * Store the result of a processed idempotency key.
     *
     * @param string $key
     * @param string $tenantId
     * @param array<string, mixed> $result
     * @param int $ttl TTL in seconds
     */
    public function store(string $key, string $tenantId, array $result, int $ttl = self::DEFAULT_TTL): void
    {
        $redisKey = self::PREFIX . "{$tenantId}:{$key}";
        $payload = json_encode($result, JSON_THROW_ON_ERROR);

        // Store in Redis
        $redis = $this->db->redis();
        try {
            $redis->set($redisKey, $payload, $ttl);
        } finally {
            $this->db->releaseRedis($redis);
        }

        // Store in platform DB for durability
        $this->storeDb($key, $tenantId, $result, $ttl);
    }

    /**
     * Execute a callback idempotently — if key exists, return cached result;
     * otherwise execute and store.
     *
     * @template T
     * @param string $key
     * @param string $tenantId
     * @param callable(): array<string, mixed> $callback
     * @param int $ttl
     * @return array<string, mixed>
     */
    public function once(string $key, string $tenantId, callable $callback, int $ttl = self::DEFAULT_TTL): array
    {
        $existing = $this->check($key, $tenantId);
        if ($existing !== null) {
            return $existing;
        }

        $result = $callback();
        $this->store($key, $tenantId, $result, $ttl);
        return $result;
    }

    /**
     * Delete an idempotency key (useful for invalidation).
     */
    public function delete(string $key, string $tenantId): void
    {
        $redisKey = self::PREFIX . "{$tenantId}:{$key}";

        $redis = $this->db->redis();
        try {
            $redis->del($redisKey);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    // ── Platform DB fallback ─────────────────────────────────────────

    private function checkDb(string $key, string $tenantId): ?array
    {
        try {
            $conn = $this->db->platform();
            try {
                $stmt = $conn->prepare(
                    'SELECT response FROM idempotency_keys WHERE `key` = ? AND tenant_id = ? AND expires_at > NOW()'
                );
                if ($stmt === false) {
                    return null;
                }
                $result = $stmt->execute([$key, $tenantId]);
                if (is_array($result) && !empty($result)) {
                    $response = $result[0]['response'] ?? null;
                    if ($response !== null) {
                        $decoded = json_decode($response, true);
                        return is_array($decoded) ? $decoded : null;
                    }
                }
                return null;
            } finally {
                $this->db->releasePlatform($conn);
            }
        } catch (\Throwable) {
            return null; // DB failure is non-fatal for idempotency checks
        }
    }

    private function storeDb(string $key, string $tenantId, array $result, int $ttl): void
    {
        try {
            $conn = $this->db->platform();
            try {
                $resultHash = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
                $response = json_encode($result, JSON_THROW_ON_ERROR);

                $stmt = $conn->prepare(
                    'INSERT INTO idempotency_keys (`key`, tenant_id, result_hash, response, expires_at) '
                    . 'VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND)) '
                    . 'ON DUPLICATE KEY UPDATE result_hash = VALUES(result_hash), response = VALUES(response), expires_at = VALUES(expires_at)'
                );

                if ($stmt !== false) {
                    $stmt->execute([$key, $tenantId, $resultHash, $response, $ttl]);
                }
            } finally {
                $this->db->releasePlatform($conn);
            }
        } catch (\Throwable) {
            // DB failure is non-fatal — Redis is the primary store
        }
    }
}

