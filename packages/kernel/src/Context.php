<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

use Swoole\Coroutine;

/**
 * Coroutine-local execution context.
 *
 * Every execution path (HTTP request, WS message, job, consumer) MUST
 * call Context::reset() at the very start. All getters return the value
 * for the CURRENT coroutine only — no global state leaks.
 */
final class Context
{
    private const KEY = '__fabriq_ctx';

    // ── Factory ──────────────────────────────────────────────────────

    /**
     * Reset context for the current coroutine. MUST be the first thing
     * called in every request/message/job handler.
     */
    public static function reset(): void
    {
        $bag = Coroutine::getContext();
        if ($bag === null) {
            return;
        }

        $bag[self::KEY] = [
            'tenant_id'      => null,
            'correlation_id' => self::generateId(),
            'request_id'     => self::generateId(),
            'actor_id'       => null,
            'extra'          => [],
        ];
    }

    // ── Getters ──────────────────────────────────────────────────────

    public static function tenantId(): ?string
    {
        return self::get('tenant_id');
    }

    public static function correlationId(): ?string
    {
        return self::get('correlation_id');
    }

    public static function requestId(): ?string
    {
        return self::get('request_id');
    }

    public static function actorId(): ?string
    {
        return self::get('actor_id');
    }

    /**
     * Return all context fields as an associative array.
     * Useful for structured logging.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $bag = Coroutine::getContext();
        if ($bag === null || !isset($bag[self::KEY])) {
            return [];
        }
        return $bag[self::KEY];
    }

    // ── Setters ──────────────────────────────────────────────────────

    public static function setTenantId(string $tenantId): void
    {
        self::set('tenant_id', $tenantId);
    }

    public static function setCorrelationId(string $correlationId): void
    {
        self::set('correlation_id', $correlationId);
    }

    public static function setRequestId(string $requestId): void
    {
        self::set('request_id', $requestId);
    }

    public static function setActorId(string $actorId): void
    {
        self::set('actor_id', $actorId);
    }

    /**
     * Store an arbitrary key in the extra bag.
     */
    public static function setExtra(string $key, mixed $value): void
    {
        $bag = Coroutine::getContext();
        if ($bag === null || !isset($bag[self::KEY])) {
            return;
        }
        $bag[self::KEY]['extra'][$key] = $value;
    }

    public static function getExtra(string $key, mixed $default = null): mixed
    {
        $bag = Coroutine::getContext();
        if ($bag === null || !isset($bag[self::KEY])) {
            return $default;
        }
        return $bag[self::KEY]['extra'][$key] ?? $default;
    }

    // ── Internals ────────────────────────────────────────────────────

    private static function get(string $field): mixed
    {
        $bag = Coroutine::getContext();
        if ($bag === null || !isset($bag[self::KEY])) {
            return null;
        }
        return $bag[self::KEY][$field] ?? null;
    }

    private static function set(string $field, mixed $value): void
    {
        $bag = Coroutine::getContext();
        if ($bag === null || !isset($bag[self::KEY])) {
            return;
        }
        $bag[self::KEY][$field] = $value;
    }

    private static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
