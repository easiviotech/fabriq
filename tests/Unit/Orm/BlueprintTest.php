<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Orm;

use PHPUnit\Framework\TestCase;
use Fabriq\Orm\Schema\Blueprint;
use Fabriq\Orm\Schema\ColumnDefinition;

final class BlueprintTest extends TestCase
{
    public function testUuidColumn(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->uuid('id');

        $this->assertInstanceOf(ColumnDefinition::class, $col);
        $this->assertStringContainsString('CHAR(36)', $col->toSql());
        $this->assertStringContainsString('`id`', $col->toSql());
    }

    public function testIdColumn(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->id();

        $sql = $col->toSql();
        $this->assertStringContainsString('BIGINT UNSIGNED AUTO_INCREMENT', $sql);
        $this->assertStringContainsString('PRIMARY KEY', $sql);
        $this->assertStringContainsString('`id`', $sql);
    }

    public function testStringColumn(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->string('name', 100);

        $sql = $col->toSql();
        $this->assertStringContainsString('VARCHAR(100)', $sql);
        $this->assertStringContainsString('`name`', $sql);
    }

    public function testIntegerColumn(): void
    {
        $bp = new Blueprint('stats');
        $col = $bp->integer('count');

        $this->assertStringContainsString('INT', $col->toSql());
    }

    public function testBooleanColumn(): void
    {
        $bp = new Blueprint('users');
        $col = $bp->boolean('active');

        $sql = $col->toSql();
        $this->assertStringContainsString('TINYINT(1)', $sql);
        $this->assertStringContainsString('DEFAULT 0', $sql);
    }

    public function testJsonColumn(): void
    {
        $bp = new Blueprint('settings');
        $col = $bp->json('payload');

        $this->assertStringContainsString('JSON', $col->toSql());
    }

    public function testEnumColumn(): void
    {
        $bp = new Blueprint('orders');
        $col = $bp->enum('status', ['pending', 'active', 'closed']);

        $sql = $col->toSql();
        $this->assertStringContainsString("ENUM('pending', 'active', 'closed')", $sql);
    }

    public function testTimestamps(): void
    {
        $bp = new Blueprint('posts');
        $bp->timestamps();

        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('`created_at`', $sql);
        $this->assertStringContainsString('`updated_at`', $sql);
        $this->assertStringContainsString('DATETIME', $sql);
        $this->assertStringContainsString('NULL', $sql);
    }

    public function testTenantId(): void
    {
        $bp = new Blueprint('resources');
        $bp->tenantId();

        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('`tenant_id`', $sql);
        $this->assertStringContainsString('CHAR(36)', $sql);
        $this->assertStringContainsString('INDEX', $sql);
    }

    public function testPrimaryAndUniqueAndIndex(): void
    {
        $bp = new Blueprint('users');
        $bp->string('email');
        $bp->primary('id');
        $bp->unique('email');
        $bp->index('name');

        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringContainsString('UNIQUE INDEX', $sql);
        $this->assertStringContainsString('INDEX `idx_users_name`', $sql);
    }

    public function testForeignKey(): void
    {
        $bp = new Blueprint('posts');
        $bp->foreignUuid('user_id');
        $bp->foreign('user_id', 'users', 'id');

        $sql = $bp->toCreateSql();
        $this->assertStringContainsString('CONSTRAINT `fk_posts_user_id`', $sql);
        $this->assertStringContainsString('FOREIGN KEY (`user_id`)', $sql);
        $this->assertStringContainsString('REFERENCES `users` (`id`)', $sql);
    }

    public function testToCreateSql(): void
    {
        $bp = new Blueprint('users');
        $bp->id();
        $bp->string('name', 100);
        $bp->timestamps();

        $sql = $bp->toCreateSql();

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `users`', $sql);
        $this->assertStringContainsString('ENGINE=InnoDB', $sql);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $sql);
        $this->assertStringContainsString('`id`', $sql);
        $this->assertStringContainsString('`name`', $sql);
    }

    public function testColumnDefinitionModifiers(): void
    {
        $col = new ColumnDefinition('email', 'VARCHAR(255)');
        $col->nullable()->default(null)->unique()->comment('User email')->after('name');

        $sql = $col->toSql();
        $this->assertStringContainsString('NULL', $sql);
        $this->assertStringContainsString('DEFAULT NULL', $sql);
        $this->assertStringContainsString('UNIQUE', $sql);
        $this->assertStringContainsString("COMMENT 'User email'", $sql);
        $this->assertStringContainsString('AFTER `name`', $sql);

        $col2 = new ColumnDefinition('status', 'VARCHAR(20)');
        $col2->notNull()->default('active');

        $sql2 = $col2->toSql();
        $this->assertStringContainsString('NOT NULL', $sql2);
        $this->assertStringContainsString("DEFAULT 'active'", $sql2);
    }
}
