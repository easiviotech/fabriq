<?php

declare(strict_types=1);

namespace Fabriq\Http\Frontend;

/**
 * Immutable value object representing the outcome of a frontend build.
 */
final class BuildResult
{
    public function __construct(
        public readonly string $tenantSlug,
        public readonly string $status,
        public readonly string $commitHash,
        public readonly float $durationMs,
        public readonly string $log,
        public readonly string $timestamp,
    ) {}

    public function toArray(): array
    {
        return [
            'tenant_slug' => $this->tenantSlug,
            'status' => $this->status,
            'commit_hash' => $this->commitHash,
            'duration_ms' => $this->durationMs,
            'log' => $this->log,
            'timestamp' => $this->timestamp,
        ];
    }

    public static function queued(string $tenantSlug): self
    {
        return new self(
            tenantSlug: $tenantSlug,
            status: 'queued',
            commitHash: '',
            durationMs: 0,
            log: '',
            timestamp: date('c'),
        );
    }

    public static function failed(string $tenantSlug, string $log, float $durationMs): self
    {
        return new self(
            tenantSlug: $tenantSlug,
            status: 'failed',
            commitHash: '',
            durationMs: $durationMs,
            log: $log,
            timestamp: date('c'),
        );
    }
}
