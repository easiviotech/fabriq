<?php

declare(strict_types=1);

namespace Fabriq\Realtime;

use Fabriq\Storage\DbManager;

/**
 * Redis-backed presence tracking.
 *
 * Tracks which users are online per tenant using Redis Sets.
 * Each worker reports its connections; Redis provides the global view.
 *
 * Keys: sf:presence:{tenantId} → SET of user IDs
 */
final class Presence
{
    private const KEY_PREFIX = 'sf:presence:';

    public function __construct(
        private readonly DbManager $db,
    ) {}

    /**
     * Mark a user as online for a tenant.
     */
    public function online(string $tenantId, string $userId): void
    {
        $redis = $this->db->redis();
        try {
            $redis->sAdd(self::KEY_PREFIX . $tenantId, $userId);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Mark a user as offline for a tenant.
     */
    public function offline(string $tenantId, string $userId): void
    {
        $redis = $this->db->redis();
        try {
            $redis->sRem(self::KEY_PREFIX . $tenantId, $userId);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Get all online user IDs for a tenant.
     *
     * @return list<string>
     */
    public function getOnlineUsers(string $tenantId): array
    {
        $redis = $this->db->redis();
        try {
            $members = $redis->sMembers(self::KEY_PREFIX . $tenantId);
            return is_array($members) ? array_values($members) : [];
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Check if a specific user is online.
     */
    public function isOnline(string $tenantId, string $userId): bool
    {
        $redis = $this->db->redis();
        try {
            return (bool) $redis->sIsMember(self::KEY_PREFIX . $tenantId, $userId);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }

    /**
     * Get the count of online users for a tenant.
     */
    public function onlineCount(string $tenantId): int
    {
        $redis = $this->db->redis();
        try {
            return (int) $redis->sCard(self::KEY_PREFIX . $tenantId);
        } finally {
            $this->db->releaseRedis($redis);
        }
    }
}

