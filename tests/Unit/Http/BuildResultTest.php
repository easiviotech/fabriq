<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Fabriq\Http\Frontend\BuildResult;

final class BuildResultTest extends TestCase
{
    public function testConstructorProperties(): void
    {
        $result = new BuildResult(
            tenantSlug: 'acme',
            status: 'success',
            commitHash: 'abc123',
            durationMs: 1500.5,
            log: 'Build complete',
            timestamp: '2026-03-06T12:00:00+00:00',
        );

        $this->assertSame('acme', $result->tenantSlug);
        $this->assertSame('success', $result->status);
        $this->assertSame('abc123', $result->commitHash);
        $this->assertSame(1500.5, $result->durationMs);
        $this->assertSame('Build complete', $result->log);
        $this->assertSame('2026-03-06T12:00:00+00:00', $result->timestamp);
    }

    public function testToArray(): void
    {
        $result = new BuildResult(
            tenantSlug: 'acme',
            status: 'success',
            commitHash: 'abc123',
            durationMs: 1200.0,
            log: 'ok',
            timestamp: '2026-01-01T00:00:00+00:00',
        );

        $array = $result->toArray();

        $this->assertSame('acme', $array['tenant_slug']);
        $this->assertSame('success', $array['status']);
        $this->assertSame('abc123', $array['commit_hash']);
        $this->assertSame(1200.0, $array['duration_ms']);
        $this->assertSame('ok', $array['log']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $array['timestamp']);
    }

    public function testQueued(): void
    {
        $result = BuildResult::queued('tenant-x');

        $this->assertSame('tenant-x', $result->tenantSlug);
        $this->assertSame('queued', $result->status);
        $this->assertSame('', $result->commitHash);
        $this->assertSame(0.0, $result->durationMs);
        $this->assertSame('', $result->log);
        $this->assertNotEmpty($result->timestamp);
    }

    public function testFailed(): void
    {
        $result = BuildResult::failed('tenant-y', 'npm error', 3200.0);

        $this->assertSame('tenant-y', $result->tenantSlug);
        $this->assertSame('failed', $result->status);
        $this->assertSame('', $result->commitHash);
        $this->assertSame(3200.0, $result->durationMs);
        $this->assertSame('npm error', $result->log);
        $this->assertNotEmpty($result->timestamp);
    }
}
