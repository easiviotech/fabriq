<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Orm;

use PHPUnit\Framework\TestCase;
use Fabriq\Orm\Seeder;

final class SeederTest extends TestCase
{
    public function testRunIsCalled(): void
    {
        $seeder = new class extends Seeder {
            public int $count = 0;

            public function run(): void
            {
                $this->count++;
            }
        };

        $seeder->run();
        $this->assertSame(1, $seeder->count);
    }

    public function testCallInvokesChildSeeders(): void
    {
        $parent = new class extends Seeder {
            public bool $ran = false;

            public function run(): void
            {
                $this->ran = true;
                $this->call(CountingSeeder::class);
            }
        };

        ob_start();
        $parent->run();
        ob_end_clean();

        $this->assertTrue($parent->ran);
    }
}

class CountingSeeder extends Seeder
{
    public static int $count = 0;

    public function run(): void
    {
        self::$count++;
    }
}
