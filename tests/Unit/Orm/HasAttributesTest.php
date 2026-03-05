<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Orm;

use PHPUnit\Framework\TestCase;

/**
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property int $age
 * @property array $tags
 * @property string $secret
 */
class TestAttributeModel
{
    use \Fabriq\Orm\Concerns\HasAttributes;

    /** @var list<string> */
    protected array $fillable = ['name', 'email'];

    /** @var list<string> */
    protected array $guarded = [];

    /** @var array<string, string> */
    protected array $casts = [
        'is_admin' => 'bool',
        'age' => 'int',
        'tags' => 'json',
    ];
}

final class HasAttributesTest extends TestCase
{
    public function testFillRespectsGuarded(): void
    {
        $model = new TestAttributeModel();
        $model->fill(['name' => 'Alice', 'email' => 'a@b.com', 'secret' => 'password']);

        $this->assertSame('Alice', $model->getAttribute('name'));
        $this->assertSame('a@b.com', $model->getAttribute('email'));
        $this->assertNull($model->getAttribute('secret'));
    }

    public function testForceFillIgnoresGuard(): void
    {
        $model = new TestAttributeModel();
        $model->forceFill(['name' => 'Bob', 'secret' => 'hunter2']);

        $this->assertSame('Bob', $model->getAttribute('name'));
        $this->assertSame('hunter2', $model->getAttribute('secret'));
    }

    public function testGetSetAttribute(): void
    {
        $model = new TestAttributeModel();
        $model->setAttribute('name', 'Charlie');

        $this->assertSame('Charlie', $model->getAttribute('name'));
    }

    public function testMagicGetSet(): void
    {
        $model = new TestAttributeModel();
        $model->name = 'Diana';

        $this->assertSame('Diana', $model->name);
        $this->assertTrue(isset($model->name));
        $this->assertFalse(isset($model->nonexistent));
    }

    public function testDirtyTracking(): void
    {
        $model = new TestAttributeModel();
        $model->setRawAttributes(['name' => 'Eve', 'email' => 'eve@test.com']);

        $this->assertFalse($model->isDirty());
        $this->assertTrue($model->isClean());

        $model->setAttribute('name', 'Evelyn');

        $this->assertTrue($model->isDirty());
        $this->assertTrue($model->isDirty('name'));
        $this->assertFalse($model->isDirty('email'));
        $this->assertSame(['name' => 'Evelyn'], $model->getDirty());

        $model->syncOriginal();

        $this->assertFalse($model->isDirty());
        $this->assertTrue($model->isClean());
        $this->assertSame('Evelyn', $model->getOriginal('name'));
    }

    public function testCasting(): void
    {
        $model = new TestAttributeModel();

        $model->setAttribute('is_admin', 1);
        $this->assertTrue($model->getAttribute('is_admin'));

        $model->setAttribute('age', '25');
        $this->assertSame(25, $model->getAttribute('age'));

        $model->setAttribute('tags', ['php', 'swoole']);
        $tagsRaw = $model->getAttributes()['tags'];
        $this->assertIsString($tagsRaw);
        $this->assertSame(['php', 'swoole'], $model->getAttribute('tags'));
    }

    public function testCastingNullPassesThrough(): void
    {
        $model = new TestAttributeModel();
        $model->setAttribute('is_admin', null);

        $this->assertNull($model->getAttribute('is_admin'));
    }

    public function testSetRawAttributes(): void
    {
        $model = new TestAttributeModel();
        $model->setRawAttributes(['name' => 'Frank', 'email' => 'frank@test.com']);

        $this->assertSame('Frank', $model->getAttribute('name'));
        $this->assertFalse($model->isDirty());
        $this->assertSame('Frank', $model->getOriginal('name'));
    }

    public function testAttributesToArray(): void
    {
        $model = new TestAttributeModel();
        $model->setRawAttributes([
            'name' => 'Grace',
            'is_admin' => 1,
            'age' => '30',
            'tags' => '["a","b"]',
        ]);

        $array = $model->attributesToArray();

        $this->assertSame('Grace', $array['name']);
        $this->assertTrue($array['is_admin']);
        $this->assertSame(30, $array['age']);
        $this->assertSame(['a', 'b'], $array['tags']);
    }
}
