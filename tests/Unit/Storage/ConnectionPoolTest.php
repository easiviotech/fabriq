<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Fabriq\Storage\ConnectionPool;
use RuntimeException;

/**
 * Tests for ConnectionPool — Channel-based bounded pool.
 *
 * Uses mock connection objects (stdClass) since these tests run
 * outside Swoole coroutines. Tests the pool logic, not Swoole channels.
 *
 * Note: Channel-dependent methods (borrow/release) require a running
 * Swoole coroutine. These tests focus on construction and state management.
 */
final class ConnectionPoolTest extends TestCase
{
    private function makePool(int $maxSize = 5, float $borrowTimeout = 1.0, float $idleTimeout = 60.0): ConnectionPool
    {
        $counter = 0;
        return new ConnectionPool(
            factory: function () use (&$counter) {
                $conn = new \stdClass();
                $conn->id = ++$counter;
                $conn->alive = true;
                return $conn;
            },
            healthCheck: fn(mixed $conn) => $conn->alive ?? false,
            maxSize: $maxSize,
            borrowTimeout: $borrowTimeout,
            idleTimeout: $idleTimeout,
        );
    }

    public function testPoolCreatesWithCorrectConfiguration(): void
    {
        $pool = $this->makePool(maxSize: 10);

        $stats = $pool->stats();
        $this->assertSame(10, $stats['max_size']);
        $this->assertSame(0, $stats['current_size']);
        $this->assertFalse($stats['closed']);
    }

    public function testPoolInitialSizeIsZero(): void
    {
        $pool = $this->makePool();
        $this->assertSame(0, $pool->size());
        $this->assertSame(0, $pool->idleCount());
    }

    public function testCloseMarksPoolAsClosed(): void
    {
        $pool = $this->makePool();
        $pool->close();

        $stats = $pool->stats();
        $this->assertTrue($stats['closed']);
    }

    public function testBorrowThrowsAfterClose(): void
    {
        $pool = $this->makePool();
        $pool->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('closed');
        $pool->borrow();
    }

    public function testStatsReturnsExpectedShape(): void
    {
        $pool = $this->makePool(maxSize: 20);
        $stats = $pool->stats();

        $this->assertArrayHasKey('current_size', $stats);
        $this->assertArrayHasKey('max_size', $stats);
        $this->assertArrayHasKey('idle', $stats);
        $this->assertArrayHasKey('closed', $stats);
        $this->assertSame(20, $stats['max_size']);
    }
}

