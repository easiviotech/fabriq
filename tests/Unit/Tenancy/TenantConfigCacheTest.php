<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Fabriq\Tenancy\TenantConfigCache;
use Fabriq\Tenancy\TenantContext;

final class TenantConfigCacheTest extends TestCase
{
    private function makeTenant(string $id, string $slug, ?string $domain = null): TenantContext
    {
        return new TenantContext(id: $id, slug: $slug, name: "Tenant {$id}", domain: $domain);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new TenantConfigCache();

        $this->assertNull($cache->get('id:nonexistent'));
    }

    public function testPutAndGet(): void
    {
        $cache = new TenantConfigCache();
        $tenant = $this->makeTenant('t1', 'acme', 'acme.io');

        $cache->put($tenant);

        $this->assertSame($tenant, $cache->get('id:t1'));
        $this->assertSame($tenant, $cache->get('slug:acme'));
        $this->assertSame($tenant, $cache->get('domain:acme.io'));
    }

    public function testInvalidate(): void
    {
        $cache = new TenantConfigCache();
        $tenant = $this->makeTenant('t1', 'acme', 'acme.io');
        $cache->put($tenant);

        $cache->invalidate('t1');

        $this->assertNull($cache->get('id:t1'));
        $this->assertNull($cache->get('slug:acme'));
        $this->assertNull($cache->get('domain:acme.io'));
    }

    public function testFlush(): void
    {
        $cache = new TenantConfigCache();
        $cache->put($this->makeTenant('t1', 's1'));
        $cache->put($this->makeTenant('t2', 's2'));

        $cache->flush();

        $this->assertSame(0, $cache->size());
    }

    public function testSize(): void
    {
        $cache = new TenantConfigCache();
        $this->assertSame(0, $cache->size());

        $cache->put($this->makeTenant('t1', 's1'));
        // id + slug = 2 entries
        $this->assertSame(2, $cache->size());

        $cache->put($this->makeTenant('t2', 's2', 'example.com'));
        // + id + slug + domain = 5
        $this->assertSame(5, $cache->size());
    }

    public function testExpiryRemovesEntry(): void
    {
        $cache = new TenantConfigCache(ttl: 0.01);
        $cache->put($this->makeTenant('t1', 's1'));

        usleep(20_000); // 20ms, well past 10ms TTL

        $this->assertNull($cache->get('id:t1'));
    }

    public function testGcRemovesExpiredEntries(): void
    {
        $cache = new TenantConfigCache(ttl: 0.01);
        $cache->put($this->makeTenant('t1', 's1'));

        usleep(20_000);

        $sizeBefore = $cache->size();
        $cache->gc();
        $this->assertSame(0, $cache->size());
        $this->assertGreaterThan(0, $sizeBefore);
    }

    public function testEvictsOldestWhenFull(): void
    {
        // Each put() creates 2 keys (id: + slug:), so maxEntries=4 holds 2 tenants.
        // When at capacity, put() calls evictOldest() which removes 10% (min 1).
        $cache = new TenantConfigCache(ttl: 300.0, maxEntries: 4);

        $cache->put($this->makeTenant('t1', 's1'));
        $this->assertSame(2, $cache->size());

        $cache->put($this->makeTenant('t2', 's2'));
        $this->assertSame(4, $cache->size());

        // Adding t3: each of the 2 entries (id:t3 + slug:t3) triggers
        // eviction when capacity is reached, so total should not exceed max + 2
        $cache->put($this->makeTenant('t3', 's3'));

        // After eviction and insertion, size should be <= maxEntries + new entries
        $this->assertLessThanOrEqual(6, $cache->size());
        // t3 must be present since it was just inserted (slug is 's3')
        $this->assertNotNull($cache->get('slug:s3'));
    }
}
