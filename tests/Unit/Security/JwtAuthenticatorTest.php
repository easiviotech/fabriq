<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Fabriq\Security\JwtAuthenticator;

final class JwtAuthenticatorTest extends TestCase
{
    private JwtAuthenticator $jwt;

    protected function setUp(): void
    {
        $this->jwt = new JwtAuthenticator('test-secret-key');
    }

    public function testEncodeProducesThreePartToken(): void
    {
        $token = $this->jwt->encode(['sub' => 'user-1']);

        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
        $this->assertNotEmpty($parts[0]);
        $this->assertNotEmpty($parts[1]);
        $this->assertNotEmpty($parts[2]);
    }

    public function testEncodeDecodeRoundTrip(): void
    {
        $claims = [
            'sub' => 'user-42',
            'tenant_id' => 'tenant-7',
            'roles' => ['admin', 'editor'],
        ];

        $token = $this->jwt->encode($claims);
        $decoded = $this->jwt->decode($token);

        $this->assertNotNull($decoded);
        $this->assertSame('user-42', $decoded['sub']);
        $this->assertSame('tenant-7', $decoded['tenant_id']);
        $this->assertSame(['admin', 'editor'], $decoded['roles']);
        $this->assertArrayHasKey('iat', $decoded);
        $this->assertArrayHasKey('exp', $decoded);
    }

    public function testDecodeValidToken(): void
    {
        $token = $this->jwt->encode(['sub' => 'valid-user', 'scope' => 'read']);
        $decoded = $this->jwt->decode($token);

        $this->assertNotNull($decoded);
        $this->assertSame('valid-user', $decoded['sub']);
        $this->assertSame('read', $decoded['scope']);
    }

    public function testDecodeInvalidToken(): void
    {
        $token = $this->jwt->encode(['sub' => 'user-1']);

        $parts = explode('.', $token);
        $parts[1] = $parts[1] . 'tampered';
        $tampered = implode('.', $parts);

        $this->assertNull($this->jwt->decode($tampered));
    }

    public function testDecodeExpiredToken(): void
    {
        $token = $this->jwt->encode([
            'sub' => 'user-1',
            'exp' => time() - 3600,
        ]);

        $this->assertNull($this->jwt->decode($token));
    }

    public function testDecodeWrongSecret(): void
    {
        $token = $this->jwt->encode(['sub' => 'user-1']);

        $other = new JwtAuthenticator('wrong-secret');
        $this->assertNull($other->decode($token));
    }

    public function testDecodeGarbage(): void
    {
        $this->assertNull($this->jwt->decode('not.a.jwt'));
        $this->assertNull($this->jwt->decode('completegarbage'));
        $this->assertNull($this->jwt->decode(''));
    }

    public function testCustomTtl(): void
    {
        $customTtl = 7200;
        $jwt = new JwtAuthenticator('secret', 'HS256', $customTtl);

        $before = time();
        $token = $jwt->encode(['sub' => 'user-1']);
        $after = time();

        $decoded = $jwt->decode($token);
        $this->assertNotNull($decoded);

        $expectedMin = $before + $customTtl;
        $expectedMax = $after + $customTtl;
        $this->assertGreaterThanOrEqual($expectedMin, $decoded['exp']);
        $this->assertLessThanOrEqual($expectedMax, $decoded['exp']);
    }
}
