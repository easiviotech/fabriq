<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Fabriq\Tenancy\TenantContext;
use Fabriq\Tenancy\TenantResolver;
use Fabriq\Tenancy\TenantConfigCache;
use Swoole\Http\Request as SwooleRequest;
use RuntimeException;

/**
 * Tests for TenantResolver — chain resolution of tenant from request.
 *
 * Uses real Swoole\Http\Request instances with manually set properties.
 */
final class TenantResolverTest extends TestCase
{
    private TenantContext $acmeTenant;

    protected function setUp(): void
    {
        $this->acmeTenant = new TenantContext(
            id: 'tid-001',
            slug: 'acme',
            name: 'Acme Corp',
            plan: 'pro',
            status: 'active',
            domain: 'acme.example.com',
        );
    }

    private function makeLookup(?TenantContext $tenant): callable
    {
        return function (string $type, string $value) use ($tenant): ?TenantContext {
            // Simple lookup that matches any known key
            if ($tenant === null) {
                return null;
            }
            return match ($type) {
                'slug' => ($value === $tenant->slug) ? $tenant : null,
                'id' => ($value === $tenant->id) ? $tenant : null,
                'domain' => ($value === $tenant->domain) ? $tenant : null,
                default => null,
            };
        };
    }

    private function makeSwooleRequest(array $headers = [], string $uri = '/'): SwooleRequest
    {
        $request = new SwooleRequest();
        $request->header = $headers;
        $request->server = ['request_uri' => $uri];
        return $request;
    }

    // ── Precedence Tests ─────────────────────────────────────────────

    public function testResolvesFromHeaderStrategy(): void
    {
        $resolver = new TenantResolver(
            chain: ['header'],
            lookup: $this->makeLookup($this->acmeTenant),
        );

        /** @var \Swoole\Http\Request $request */
        $request = $this->makeSwooleRequest(['x-tenant' => 'acme']);
        $tenant = $resolver->resolve($request);

        $this->assertSame('tid-001', $tenant->id);
        $this->assertSame('acme', $tenant->slug);
    }

    public function testResolvesFromHostSubdomain(): void
    {
        $resolver = new TenantResolver(
            chain: ['host'],
            lookup: $this->makeLookup($this->acmeTenant),
        );

        $request = $this->makeSwooleRequest(['host' => 'acme.app.fabrq.io']);
        $tenant = $resolver->resolve($request);

        $this->assertSame('tid-001', $tenant->id);
    }

    public function testResolvesFromHostCustomDomain(): void
    {
        $resolver = new TenantResolver(
            chain: ['host'],
            lookup: $this->makeLookup($this->acmeTenant),
        );

        $request = $this->makeSwooleRequest(['host' => 'acme.example.com']);
        $tenant = $resolver->resolve($request);

        $this->assertSame('tid-001', $tenant->id);
    }

    public function testChainPriority_HostBeforeHeader(): void
    {
        $secondTenant = new TenantContext(
            id: 'tid-002', slug: 'beta', name: 'Beta Inc',
            domain: 'beta.app.fabrq.io',
        );

        // Lookup returns acme for host, beta for header
        $lookup = function (string $type, string $value) use ($secondTenant): ?TenantContext {
            if ($type === 'domain' && $value === 'acme.example.com') {
                return $this->acmeTenant;
            }
            if ($type === 'slug' && $value === 'beta') {
                return $secondTenant;
            }
            return null;
        };

        $resolver = new TenantResolver(
            chain: ['host', 'header'],
            lookup: $lookup,
        );

        // Both host and header present — host wins
        $request = $this->makeSwooleRequest([
            'host' => 'acme.example.com',
            'x-tenant' => 'beta',
        ]);
        $tenant = $resolver->resolve($request);

        $this->assertSame('tid-001', $tenant->id, 'Host strategy should take precedence');
    }

    public function testFallsToNextStrategyWhenFirstFails(): void
    {
        $resolver = new TenantResolver(
            chain: ['host', 'header'],
            lookup: $this->makeLookup($this->acmeTenant),
        );

        // No host match (single-segment host), falls to header
        $request = $this->makeSwooleRequest([
            'host' => 'localhost',
            'x-tenant' => 'acme',
        ]);
        $tenant = $resolver->resolve($request);

        $this->assertSame('tid-001', $tenant->id);
    }

    // ── Failure Cases ────────────────────────────────────────────────

    public function testThrowsWhenNoStrategyResolves(): void
    {
        $resolver = new TenantResolver(
            chain: ['host', 'header'],
            lookup: fn() => null,
        );

        $request = $this->makeSwooleRequest([]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to resolve tenant');
        $resolver->resolve($request);
    }

    public function testThrowsForSuspendedTenant(): void
    {
        $suspended = new TenantContext(
            id: 'tid-003', slug: 'suspended-co', name: 'Suspended Co',
            status: 'suspended',
        );

        $resolver = new TenantResolver(
            chain: ['header'],
            lookup: fn(string $type, string $value) => $value === 'suspended-co' ? $suspended : null,
        );

        $request = $this->makeSwooleRequest(['x-tenant' => 'suspended-co']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not active');
        $resolver->resolve($request);
    }

    // ── TenantContext Tests ──────────────────────────────────────────

    public function testTenantContextFromArray(): void
    {
        $tenant = TenantContext::fromArray([
            'id' => 'tid-100',
            'slug' => 'test',
            'name' => 'Test Tenant',
            'plan' => 'enterprise',
            'status' => 'active',
            'domain' => 'test.example.com',
            'config_json' => '{"feature_flags":{"beta":true}}',
        ]);

        $this->assertSame('tid-100', $tenant->id);
        $this->assertSame('enterprise', $tenant->plan);
        $this->assertTrue($tenant->configValue('feature_flags.beta'));
    }

    public function testTenantContextToArray(): void
    {
        $arr = $this->acmeTenant->toArray();

        $this->assertSame('tid-001', $arr['id']);
        $this->assertSame('acme', $arr['slug']);
        $this->assertSame('Acme Corp', $arr['name']);
    }

    // ── TenantConfigCache Tests ──────────────────────────────────────

    public function testCachePutAndGet(): void
    {
        $cache = new TenantConfigCache(ttl: 60.0);
        $cache->put($this->acmeTenant);

        $this->assertSame('tid-001', $cache->get('slug:acme')?->id);
        $this->assertSame('tid-001', $cache->get('id:tid-001')?->id);
        $this->assertSame('tid-001', $cache->get('domain:acme.example.com')?->id);
    }

    public function testCacheReturnsNullForMissing(): void
    {
        $cache = new TenantConfigCache();
        $this->assertNull($cache->get('slug:nonexistent'));
    }

    public function testCacheInvalidate(): void
    {
        $cache = new TenantConfigCache();
        $cache->put($this->acmeTenant);

        $this->assertNotNull($cache->get('slug:acme'));

        $cache->invalidate('tid-001');

        $this->assertNull($cache->get('slug:acme'));
        $this->assertNull($cache->get('id:tid-001'));
    }

    public function testCacheFlush(): void
    {
        $cache = new TenantConfigCache();
        $cache->put($this->acmeTenant);

        $this->assertSame(3, $cache->size()); // id + slug + domain entries

        $cache->flush();

        $this->assertSame(0, $cache->size());
    }
}

