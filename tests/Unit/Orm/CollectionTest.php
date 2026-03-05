<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Orm;

use PHPUnit\Framework\TestCase;
use Fabriq\Orm\Collection;

final class CollectionTest extends TestCase
{
    public function testConstructor(): void
    {
        $c = new Collection([10, 20, 30]);

        $this->assertSame([10, 20, 30], $c->all());
    }

    public function testFirst(): void
    {
        $this->assertSame(1, (new Collection([1, 2, 3]))->first());
        $this->assertSame('fallback', (new Collection())->first('fallback'));
        $this->assertNull((new Collection())->first());
    }

    public function testLast(): void
    {
        $this->assertSame(3, (new Collection([1, 2, 3]))->last());
        $this->assertSame('fallback', (new Collection())->last('fallback'));
        $this->assertNull((new Collection())->last());
    }

    public function testGet(): void
    {
        $c = new Collection(['a', 'b', 'c']);

        $this->assertSame('b', $c->get(1));
        $this->assertNull($c->get(99));
    }

    public function testMap(): void
    {
        $c = new Collection([1, 2, 3]);
        $mapped = $c->map(fn(int $v) => $v * 2);

        $this->assertSame([2, 4, 6], $mapped->all());
        $this->assertInstanceOf(Collection::class, $mapped);
    }

    public function testFilter(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $even = $c->filter(fn(int $v) => $v % 2 === 0);

        $this->assertSame([2, 4], $even->all());
    }

    public function testPluck(): void
    {
        $items = [
            ['name' => 'Alice', 'age' => 30],
            ['name' => 'Bob', 'age' => 25],
        ];
        $names = (new Collection($items))->pluck('name');

        $this->assertSame(['Alice', 'Bob'], $names->all());

        $objects = [
            (object) ['role' => 'admin'],
            (object) ['role' => 'user'],
        ];
        $roles = (new Collection($objects))->pluck('role');

        $this->assertSame(['admin', 'user'], $roles->all());
    }

    public function testKeyBy(): void
    {
        $items = [
            ['id' => 'a', 'val' => 1],
            ['id' => 'b', 'val' => 2],
        ];
        $keyed = (new Collection($items))->keyBy('id');

        $this->assertArrayHasKey('a', $keyed);
        $this->assertArrayHasKey('b', $keyed);
        $this->assertSame(1, $keyed['a']['val']);
    }

    public function testGroupBy(): void
    {
        $items = [
            ['dept' => 'eng', 'name' => 'Alice'],
            ['dept' => 'eng', 'name' => 'Bob'],
            ['dept' => 'hr', 'name' => 'Carol'],
        ];
        $grouped = (new Collection($items))->groupBy('dept');

        $this->assertCount(2, $grouped['eng']);
        $this->assertCount(1, $grouped['hr']);
    }

    public function testReduce(): void
    {
        $sum = (new Collection([1, 2, 3, 4]))->reduce(fn(int $carry, int $item) => $carry + $item, 0);

        $this->assertSame(10, $sum);
    }

    public function testEach(): void
    {
        $log = [];
        $c = new Collection(['a', 'b']);
        $result = $c->each(function (string $item, int $idx) use (&$log): void {
            $log[] = "{$idx}:{$item}";
        });

        $this->assertSame(['0:a', '1:b'], $log);
        $this->assertSame($c, $result);
    }

    public function testContains(): void
    {
        $c = new Collection([1, 2, 3]);

        $this->assertTrue($c->contains(fn(int $v) => $v === 2));
        $this->assertFalse($c->contains(fn(int $v) => $v === 99));
    }

    public function testUnique(): void
    {
        $c = new Collection([1, 2, 2, 3, 3, 3]);

        $this->assertSame([1, 2, 3], $c->unique()->all());
    }

    public function testUniqueByKey(): void
    {
        $items = [
            ['role' => 'admin', 'name' => 'A'],
            ['role' => 'admin', 'name' => 'B'],
            ['role' => 'user', 'name' => 'C'],
        ];
        $unique = (new Collection($items))->unique('role');

        $this->assertCount(2, $unique);
        $this->assertSame('A', $unique->first()['name']);
    }

    public function testSortByString(): void
    {
        $items = [
            ['name' => 'Charlie'],
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ];
        $sorted = (new Collection($items))->sortBy('name');

        $this->assertSame('Alice', $sorted->first()['name']);
        $this->assertSame('Charlie', $sorted->last()['name']);
    }

    public function testSortByCallable(): void
    {
        $c = new Collection([3, 1, 2]);
        $sorted = $c->sortBy(fn(int $a, int $b) => $a <=> $b);

        $this->assertSame([1, 2, 3], $sorted->all());
    }

    public function testSlice(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);

        $this->assertSame([3, 4], $c->slice(2, 2)->all());
        $this->assertSame([4, 5], $c->slice(3)->all());
    }

    public function testChunk(): void
    {
        $c = new Collection([1, 2, 3, 4, 5]);
        $chunks = $c->chunk(2);

        $this->assertCount(3, $chunks);
        $this->assertSame([1, 2], $chunks[0]->all());
        $this->assertSame([3, 4], $chunks[1]->all());
        $this->assertSame([5], $chunks[2]->all());
    }

    public function testIsEmptyIsNotEmpty(): void
    {
        $this->assertTrue((new Collection())->isEmpty());
        $this->assertFalse((new Collection())->isNotEmpty());

        $this->assertFalse((new Collection([1]))->isEmpty());
        $this->assertTrue((new Collection([1]))->isNotEmpty());
    }

    public function testCount(): void
    {
        $this->assertSame(0, (new Collection())->count());
        $this->assertSame(3, (new Collection([1, 2, 3]))->count());
    }

    public function testCountable(): void
    {
        $c = new Collection([1, 2, 3]);

        $this->assertCount(3, $c);
    }

    public function testIteratorAggregate(): void
    {
        $c = new Collection([10, 20, 30]);
        $values = [];

        foreach ($c as $item) {
            $values[] = $item;
        }

        $this->assertSame([10, 20, 30], $values);
    }

    public function testArrayAccess(): void
    {
        $c = new Collection([1, 2, 3]);

        $this->assertTrue(isset($c[0]));
        $this->assertFalse(isset($c[10]));
        $this->assertSame(2, $c[1]);

        $c[1] = 99;
        $this->assertSame(99, $c[1]);

        $c[] = 100;
        $this->assertSame(100, $c[3]);

        unset($c[0]);
        $this->assertSame(99, $c[0]);
    }

    public function testToArray(): void
    {
        $this->assertSame([1, 2], (new Collection([1, 2]))->toArray());

        $obj = new class {
            public function toArray(): array
            {
                return ['converted' => true];
            }
        };

        $c = new Collection([$obj]);
        $result = $c->toArray();

        $this->assertSame([['converted' => true]], $result);
    }
}
