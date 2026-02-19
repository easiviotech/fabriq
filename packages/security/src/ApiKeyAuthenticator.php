<?php

declare(strict_types=1);

namespace Fabriq\Security;

use Fabriq\Storage\DbManager;

/**
 * API key authenticator.
 *
 * Validates API keys in the format: sf_{prefix}_{secret}
 *
 * Lookup flow:
 *   1. Extract prefix from key
 *   2. Look up prefix in platform DB (api_keys table)
 *   3. Hash the full key and compare with stored hash
 *   4. Return tenant_id + scopes on success
 */
final class ApiKeyAuthenticator
{
    /** @var callable(string): ?array  Lookup function: fn(prefix) → row|null */
    private $lookupByPrefix;

    /**
     * @param callable(string): ?array $lookupByPrefix  fn(prefix) → api_key row
     */
    public function __construct(callable $lookupByPrefix)
    {
        $this->lookupByPrefix = $lookupByPrefix;
    }

    /**
     * Create an instance backed by DbManager.
     */
    public static function withDb(DbManager $db): self
    {
        return new self(function (string $prefix) use ($db): ?array {
            $conn = $db->platform();
            try {
                $stmt = $conn->prepare(
                    'SELECT id, tenant_id, key_hash, scopes, expires_at FROM api_keys WHERE key_prefix = ?'
                );
                if ($stmt === false) {
                    return null;
                }
                $result = $stmt->execute([$prefix]);
                if (is_array($result) && !empty($result)) {
                    return $result[0];
                }
                return null;
            } finally {
                $db->releasePlatform($conn);
            }
        });
    }

    /**
     * Authenticate an API key.
     *
     * @param string $key  Full API key (sf_{prefix}_{secret})
     * @return array{id: string, tenant_id: string, scopes: list<string>}|null
     */
    public function authenticate(string $key): ?array
    {
        $parsed = $this->parseKey($key);
        if ($parsed === null) {
            return null;
        }

        [$prefix, $secret] = $parsed;

        // Look up by prefix
        $row = ($this->lookupByPrefix)($prefix);
        if ($row === null) {
            return null;
        }

        // Check expiration
        if (isset($row['expires_at']) && $row['expires_at'] !== null) {
            if (strtotime($row['expires_at']) < time()) {
                return null;
            }
        }

        // Verify hash
        $keyHash = hash('sha256', $key);
        if (!hash_equals($row['key_hash'] ?? '', $keyHash)) {
            return null;
        }

        // Parse scopes
        $scopes = [];
        if (isset($row['scopes'])) {
            $decoded = is_string($row['scopes']) ? json_decode($row['scopes'], true) : $row['scopes'];
            $scopes = is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => $row['id'] ?? '',
            'tenant_id' => $row['tenant_id'] ?? '',
            'scopes' => $scopes,
        ];
    }

    /**
     * Generate a new API key.
     *
     * @return array{key: string, prefix: string, hash: string}
     */
    public static function generateKey(): array
    {
        $prefix = bin2hex(random_bytes(4)); // 8 chars
        $secret = bin2hex(random_bytes(16)); // 32 chars
        $key = "sf_{$prefix}_{$secret}";

        return [
            'key' => $key,
            'prefix' => $prefix,
            'hash' => hash('sha256', $key),
        ];
    }

    /**
     * Parse an API key into prefix and secret.
     *
     * @return array{0: string, 1: string}|null  [prefix, secret]
     */
    private function parseKey(string $key): ?array
    {
        // Format: sf_{prefix}_{secret}
        if (!str_starts_with($key, 'sf_')) {
            return null;
        }

        $rest = substr($key, 3);
        $underscorePos = strpos($rest, '_');
        if ($underscorePos === false) {
            return null;
        }

        $prefix = substr($rest, 0, $underscorePos);
        $secret = substr($rest, $underscorePos + 1);

        if ($prefix === '' || $secret === '') {
            return null;
        }

        return [$prefix, $secret];
    }
}

