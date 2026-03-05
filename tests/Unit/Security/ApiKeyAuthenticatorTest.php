<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Fabriq\Security\ApiKeyAuthenticator;

final class ApiKeyAuthenticatorTest extends TestCase
{
    public function testGenerateKey(): void
    {
        $generated = ApiKeyAuthenticator::generateKey();

        $this->assertArrayHasKey('key', $generated);
        $this->assertArrayHasKey('prefix', $generated);
        $this->assertArrayHasKey('hash', $generated);
        $this->assertStringStartsWith('sf_', $generated['key']);
        $this->assertSame(hash('sha256', $generated['key']), $generated['hash']);
    }

    public function testAuthenticateValidKey(): void
    {
        $generated = ApiKeyAuthenticator::generateKey();

        $lookup = fn(string $prefix) => [
            'id' => 'key-1',
            'tenant_id' => 'tenant-1',
            'key_hash' => $generated['hash'],
            'scopes' => '["read","write"]',
            'expires_at' => null,
        ];

        $auth = new ApiKeyAuthenticator($lookup);
        $result = $auth->authenticate($generated['key']);

        $this->assertNotNull($result);
        $this->assertSame('key-1', $result['id']);
        $this->assertSame('tenant-1', $result['tenant_id']);
        $this->assertSame(['read', 'write'], $result['scopes']);
    }

    public function testAuthenticateInvalidFormat(): void
    {
        $lookup = fn(string $prefix) => null;
        $auth = new ApiKeyAuthenticator($lookup);

        $this->assertNull($auth->authenticate('invalid_key_format'));
        $this->assertNull($auth->authenticate(''));
        $this->assertNull($auth->authenticate('pk_abc_def'));
    }

    public function testAuthenticateWrongHash(): void
    {
        $generated = ApiKeyAuthenticator::generateKey();

        $lookup = fn(string $prefix) => [
            'id' => 'key-1',
            'tenant_id' => 'tenant-1',
            'key_hash' => 'wrong_hash_value',
            'scopes' => '[]',
            'expires_at' => null,
        ];

        $auth = new ApiKeyAuthenticator($lookup);
        $this->assertNull($auth->authenticate($generated['key']));
    }

    public function testAuthenticateExpiredKey(): void
    {
        $generated = ApiKeyAuthenticator::generateKey();

        $lookup = fn(string $prefix) => [
            'id' => 'key-1',
            'tenant_id' => 'tenant-1',
            'key_hash' => $generated['hash'],
            'scopes' => '[]',
            'expires_at' => date('Y-m-d H:i:s', time() - 3600),
        ];

        $auth = new ApiKeyAuthenticator($lookup);
        $this->assertNull($auth->authenticate($generated['key']));
    }

    public function testAuthenticateUnknownPrefix(): void
    {
        $lookup = fn(string $prefix) => null;
        $auth = new ApiKeyAuthenticator($lookup);

        $generated = ApiKeyAuthenticator::generateKey();
        $this->assertNull($auth->authenticate($generated['key']));
    }

    public function testScopeParsing(): void
    {
        $generated = ApiKeyAuthenticator::generateKey();

        $lookup = fn(string $prefix) => [
            'id' => 'key-1',
            'tenant_id' => 'tenant-1',
            'key_hash' => $generated['hash'],
            'scopes' => '["users:read","users:write","admin"]',
            'expires_at' => null,
        ];

        $auth = new ApiKeyAuthenticator($lookup);
        $result = $auth->authenticate($generated['key']);

        $this->assertNotNull($result);
        $this->assertIsArray($result['scopes']);
        $this->assertSame(['users:read', 'users:write', 'admin'], $result['scopes']);
    }
}
