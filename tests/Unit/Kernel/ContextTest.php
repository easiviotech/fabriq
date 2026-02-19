<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Kernel;

use PHPUnit\Framework\TestCase;
use Fabriq\Kernel\Context;

/**
 * Tests for Context — coroutine-local execution context.
 *
 * Note: These tests run outside of Swoole coroutines, so they test
 * the graceful fallback behavior (returning null when no coroutine
 * context is available). Full coroutine isolation tests require a
 * running Swoole environment.
 */
final class ContextTest extends TestCase
{
    public function testResetSetsDefaultValuesOutsideCoroutine(): void
    {
        // Outside a coroutine, reset should not throw
        Context::reset();

        // Outside coroutine, getters should return null gracefully
        $this->assertNull(Context::tenantId());
        $this->assertNull(Context::actorId());
    }

    public function testSettersAndGettersOutsideCoroutine(): void
    {
        Context::reset();

        // Outside coroutine context, setters should not throw
        Context::setTenantId('tenant-123');
        Context::setActorId('user-456');
        Context::setCorrelationId('corr-789');
        Context::setRequestId('req-abc');

        // Values won't persist outside a coroutine — returns null
        $this->assertNull(Context::tenantId());
        $this->assertNull(Context::actorId());
    }

    public function testAllReturnsEmptyOutsideCoroutine(): void
    {
        Context::reset();
        $this->assertEmpty(Context::all());
    }

    public function testExtraFieldsOutsideCoroutine(): void
    {
        Context::reset();
        Context::setExtra('custom_key', 'custom_value');

        // Outside coroutine, returns default
        $this->assertEquals('fallback', Context::getExtra('custom_key', 'fallback'));
    }
}
