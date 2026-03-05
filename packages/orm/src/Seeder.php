<?php

declare(strict_types=1);

namespace Fabriq\Orm;

/**
 * Base class for database seeders.
 *
 * Subclasses implement run() to insert seed data.
 * Use call() to invoke child seeders for organized seeding.
 *
 * Example:
 *   class DatabaseSeeder extends Seeder {
 *       public function run(): void {
 *           $this->call(TenantSeeder::class, UserSeeder::class);
 *       }
 *   }
 */
abstract class Seeder
{
    /**
     * Run the seeder.
     */
    abstract public function run(): void;

    /**
     * Run one or more child seeders.
     *
     * @param class-string<Seeder> ...$seederClasses
     */
    public function call(string ...$seederClasses): void
    {
        foreach ($seederClasses as $class) {
            echo "  Seeding: {$class}\n";
            $seeder = new $class();
            $seeder->run();
            echo "  Seeded:  {$class}\n";
        }
    }
}
