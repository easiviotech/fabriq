<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Tenancy;

use PHPUnit\Framework\TestCase;
use Fabriq\Tenancy\TenantContext;

final class TenantContextTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $t = new TenantContext(id: 'uuid-1', slug: 'acme', name: 'Acme Inc');

        $this->assertSame('uuid-1', $t->id);
        $this->assertSame('acme', $t->slug);
        $this->assertSame('Acme Inc', $t->name);
        $this->assertSame('free', $t->plan);
        $this->assertSame('active', $t->status);
        $this->assertNull($t->domain);
        $this->assertSame([], $t->config);
        $this->assertNull($t->dbKey);
    }

    public function testFromArray(): void
    {
        $row = [
            'id' => 'tid-42',
            'slug' => 'widgets',
            'name' => 'Widgets Co',
            'plan' => 'pro',
            'status' => 'active',
            'domain' => 'widgets.example.com',
            'config_json' => json_encode(['feature_flags' => ['beta' => true]]),
        ];

        $t = TenantContext::fromArray($row);

        $this->assertSame('tid-42', $t->id);
        $this->assertSame('widgets', $t->slug);
        $this->assertSame('pro', $t->plan);
        $this->assertSame('widgets.example.com', $t->domain);
        $this->assertTrue($t->config['feature_flags']['beta']);
    }

    public function testFromArrayWithJsonConfig(): void
    {
        $row = [
            'id' => '1',
            'slug' => 's',
            'name' => 'N',
            'config_json' => '{"theme":"dark"}',
        ];

        $t = TenantContext::fromArray($row);

        $this->assertSame('dark', $t->config['theme']);
    }

    public function testFromArrayWithArrayConfig(): void
    {
        $row = [
            'id' => '1',
            'slug' => 's',
            'name' => 'N',
            'config' => ['theme' => 'light'],
        ];

        $t = TenantContext::fromArray($row);

        $this->assertSame('light', $t->config['theme']);
    }

    public function testFromArrayDeriveDbKey(): void
    {
        $fromConfig = TenantContext::fromArray([
            'id' => '1', 'slug' => 's', 'name' => 'N',
            'config_json' => json_encode(['database' => ['key' => 'shard_3']]),
        ]);
        $this->assertSame('shard_3', $fromConfig->dbKey);

        $fromRow = TenantContext::fromArray([
            'id' => '2', 'slug' => 's2', 'name' => 'N2',
            'db_key' => 'shard_7',
        ]);
        $this->assertSame('shard_7', $fromRow->dbKey);
    }

    public function testToArray(): void
    {
        $t = new TenantContext(
            id: 'x', slug: 'y', name: 'Z',
            plan: 'enterprise', status: 'active',
            domain: 'z.io', config: ['k' => 'v'], dbKey: 'db1',
        );

        $arr = $t->toArray();

        $this->assertSame('x', $arr['id']);
        $this->assertSame('y', $arr['slug']);
        $this->assertSame('Z', $arr['name']);
        $this->assertSame('enterprise', $arr['plan']);
        $this->assertSame('z.io', $arr['domain']);
        $this->assertSame(['k' => 'v'], $arr['config']);
        $this->assertSame('db1', $arr['db_key']);
    }

    public function testIsActive(): void
    {
        $active = new TenantContext(id: '1', slug: 's', name: 'n', status: 'active');
        $suspended = new TenantContext(id: '2', slug: 's2', name: 'n2', status: 'suspended');
        $deleted = new TenantContext(id: '3', slug: 's3', name: 'n3', status: 'deleted');

        $this->assertTrue($active->isActive());
        $this->assertFalse($suspended->isActive());
        $this->assertFalse($deleted->isActive());
    }

    public function testConfigValue(): void
    {
        $t = new TenantContext(
            id: '1', slug: 's', name: 'n',
            config: ['database' => ['host' => 'db.local', 'port' => 3306]],
        );

        $this->assertSame('db.local', $t->configValue('database.host'));
        $this->assertSame(3306, $t->configValue('database.port'));
    }

    public function testConfigValueDefault(): void
    {
        $t = new TenantContext(id: '1', slug: 's', name: 'n');

        $this->assertNull($t->configValue('missing.key'));
        $this->assertSame('fallback', $t->configValue('missing.key', 'fallback'));
    }
}
