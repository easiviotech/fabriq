<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Orm;

use PHPUnit\Framework\TestCase;
use Fabriq\Orm\Collection;
use Fabriq\Orm\Paginator;

final class PaginatorTest extends TestCase
{
    public function testBasicPagination(): void
    {
        $items = new Collection(['a', 'b', 'c']);
        $paginator = new Paginator($items, total: 10, perPage: 3, currentPage: 1);

        $this->assertSame($items, $paginator->items());
        $this->assertSame(10, $paginator->total());
        $this->assertSame(3, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
    }

    public function testLastPage(): void
    {
        $items = new Collection([]);

        $this->assertSame(4, (new Paginator($items, total: 10, perPage: 3, currentPage: 1))->lastPage());
        $this->assertSame(5, (new Paginator($items, total: 10, perPage: 2, currentPage: 1))->lastPage());
        $this->assertSame(1, (new Paginator($items, total: 3, perPage: 5, currentPage: 1))->lastPage());
        $this->assertSame(1, (new Paginator($items, total: 0, perPage: 5, currentPage: 1))->lastPage());
    }

    public function testHasMorePages(): void
    {
        $items = new Collection([]);

        $this->assertTrue((new Paginator($items, total: 10, perPage: 3, currentPage: 1))->hasMorePages());
        $this->assertTrue((new Paginator($items, total: 10, perPage: 3, currentPage: 3))->hasMorePages());
        $this->assertFalse((new Paginator($items, total: 10, perPage: 3, currentPage: 4))->hasMorePages());
        $this->assertFalse((new Paginator($items, total: 3, perPage: 5, currentPage: 1))->hasMorePages());
    }

    public function testToArray(): void
    {
        $items = new Collection([1, 2, 3]);
        $paginator = new Paginator($items, total: 9, perPage: 3, currentPage: 2);

        $array = $paginator->toArray();

        $this->assertSame([1, 2, 3], $array['data']);
        $this->assertSame(9, $array['total']);
        $this->assertSame(3, $array['per_page']);
        $this->assertSame(2, $array['current_page']);
        $this->assertSame(3, $array['last_page']);
        $this->assertTrue($array['has_more']);
    }
}
