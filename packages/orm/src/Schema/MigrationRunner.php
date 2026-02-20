<?php

declare(strict_types=1);

namespace Fabriq\Orm\Schema;

use Fabriq\Orm\TenantDbRouter;
use RuntimeException;

/**
 * Migration runner.
 *
 * Discovers migration files in a directory, tracks which have been
 * applied in a `migrations` table, and runs pending migrations in order.
 *
 * Migration files must follow the naming convention:
 *   NNN_description.php  (e.g., 001_create_users.php)
 *
 * Each file should return or define a class that extends Migration.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly TenantDbRouter $router,
        private readonly string $migrationPath,
        private readonly string $migrationTable = 'migrations',
        private readonly string $pool = 'app',
    ) {}

    /**
     * Run all pending migrations.
     *
     * @return list<string> List of migration files that were run
     */
    public function migrate(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        $ran = [];

        foreach ($files as $file) {
            $migrationName = basename($file, '.php');

            if (in_array($migrationName, $applied, true)) {
                continue;
            }

            $migration = $this->resolveMigration($file);
            $migration->up();

            $this->recordMigration($migrationName);
            $ran[] = $migrationName;
        }

        return $ran;
    }

    /**
     * Rollback the last batch of migrations.
     *
     * @param int $steps Number of migrations to roll back
     * @return list<string> List of migration files that were rolled back
     */
    public function rollback(int $steps = 1): array
    {
        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        $rolledBack = [];

        // Reverse order, take $steps
        $toRollback = array_slice(array_reverse($applied), 0, $steps);

        foreach ($toRollback as $migrationName) {
            $file = $this->findMigrationFile($files, $migrationName);
            if ($file === null) {
                continue;
            }

            $migration = $this->resolveMigration($file);
            $migration->down();

            $this->removeMigrationRecord($migrationName);
            $rolledBack[] = $migrationName;
        }

        return $rolledBack;
    }

    /**
     * Get the list of pending (not yet run) migrations.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        $pending = [];

        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (!in_array($name, $applied, true)) {
                $pending[] = $name;
            }
        }

        return $pending;
    }

    // ── Migration Table ─────────────────────────────────────────────

    private function ensureMigrationTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS `{$this->migrationTable}` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `migration` VARCHAR(255) NOT NULL,
            `ran_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX `idx_migration_name` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            $conn->query($sql);
        } finally {
            $this->router->release($handle);
        }
    }

    /**
     * @return list<string>
     */
    private function getAppliedMigrations(): array
    {
        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            $result = $conn->query(
                "SELECT `migration` FROM `{$this->migrationTable}` ORDER BY `id` ASC"
            );

            if (!is_array($result)) {
                return [];
            }

            return array_map(fn($row) => $row['migration'], $result);
        } finally {
            $this->router->release($handle);
        }
    }

    private function recordMigration(string $name): void
    {
        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            $stmt = $conn->prepare(
                "INSERT INTO `{$this->migrationTable}` (`migration`, `ran_at`) VALUES (?, NOW())"
            );
            if ($stmt !== false) {
                $stmt->execute([$name]);
            }
        } finally {
            $this->router->release($handle);
        }
    }

    private function removeMigrationRecord(string $name): void
    {
        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            $stmt = $conn->prepare(
                "DELETE FROM `{$this->migrationTable}` WHERE `migration` = ?"
            );
            if ($stmt !== false) {
                $stmt->execute([$name]);
            }
        } finally {
            $this->router->release($handle);
        }
    }

    // ── File Discovery ──────────────────────────────────────────────

    /**
     * Get all migration files sorted by name.
     *
     * @return list<string> Full file paths
     */
    private function getMigrationFiles(): array
    {
        if (!is_dir($this->migrationPath)) {
            return [];
        }

        $files = glob($this->migrationPath . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            return [];
        }

        sort($files);
        return $files;
    }

    private function findMigrationFile(array $files, string $name): ?string
    {
        foreach ($files as $file) {
            if (basename($file, '.php') === $name) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Require a migration file and return the Migration instance.
     */
    private function resolveMigration(string $file): Migration
    {
        $result = require $file;

        if ($result instanceof Migration) {
            return $result;
        }

        // If the file defines a class, find and instantiate it
        $declared = get_declared_classes();
        $className = null;

        // Check the last declared class that extends Migration
        foreach (array_reverse($declared) as $class) {
            if (is_subclass_of($class, Migration::class)) {
                $className = $class;
                break;
            }
        }

        if ($className !== null) {
            return new $className();
        }

        throw new RuntimeException("Migration file [{$file}] did not return or define a Migration class.");
    }
}

