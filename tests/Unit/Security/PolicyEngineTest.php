<?php

declare(strict_types=1);

namespace SwooleFabric\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use SwooleFabric\Security\PolicyEngine;

final class PolicyEngineTest extends TestCase
{
    private PolicyEngine $engine;
    private array $auditLog = [];

    protected function setUp(): void
    {
        $this->engine = new PolicyEngine();
        $this->auditLog = [];
        $this->engine->setAuditLogger(function (array $decision) {
            $this->auditLog[] = $decision;
        });
    }

    public function testRbacAllowsGrantedPermission(): void
    {
        $this->engine->defineRole('editor', ['rooms:create', 'messages:read', 'messages:create']);

        $result = $this->engine->evaluate('user-1', ['editor'], 'rooms', 'create');
        $this->assertTrue($result);
    }

    public function testRbacDeniesUngrantedPermission(): void
    {
        $this->engine->defineRole('viewer', ['messages:read']);

        $result = $this->engine->evaluate('user-1', ['viewer'], 'rooms', 'create');
        $this->assertFalse($result);
    }

    public function testWildcardPermission(): void
    {
        $this->engine->defineRole('admin', ['*:*']);

        $result = $this->engine->evaluate('admin-1', ['admin'], 'anything', 'any_action');
        $this->assertTrue($result);
    }

    public function testResourceWildcard(): void
    {
        $this->engine->defineRole('room_admin', ['rooms:*']);

        $this->assertTrue($this->engine->evaluate('u-1', ['room_admin'], 'rooms', 'delete'));
        $this->assertFalse($this->engine->evaluate('u-1', ['room_admin'], 'messages', 'delete'));
    }

    public function testAbacConditionCanDeny(): void
    {
        $this->engine->defineRole('editor', ['rooms:create']);

        // ABAC: only allow room creation during business hours
        $this->engine->addCondition('rooms', 'create', function (array $ctx): bool {
            return ($ctx['hour'] ?? 12) >= 9 && ($ctx['hour'] ?? 12) <= 17;
        });

        // Within hours
        $this->assertTrue($this->engine->evaluate('u-1', ['editor'], 'rooms', 'create', ['hour' => 10]));

        // Outside hours
        $this->assertFalse($this->engine->evaluate('u-1', ['editor'], 'rooms', 'create', ['hour' => 22]));
    }

    public function testAuditLogging(): void
    {
        $this->engine->defineRole('viewer', ['messages:read']);

        $this->engine->evaluate('u-1', ['viewer'], 'messages', 'read');

        $this->assertCount(1, $this->auditLog);
        $this->assertTrue($this->auditLog[0]['allowed']);
        $this->assertSame('u-1', $this->auditLog[0]['actor_id']);
    }

    public function testMultipleRolesUnion(): void
    {
        $this->engine->defineRole('viewer', ['messages:read']);
        $this->engine->defineRole('editor', ['messages:create']);

        // User with both roles
        $this->assertTrue($this->engine->evaluate('u-1', ['viewer', 'editor'], 'messages', 'read'));
        $this->assertTrue($this->engine->evaluate('u-1', ['viewer', 'editor'], 'messages', 'create'));
    }
}
