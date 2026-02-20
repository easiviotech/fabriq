<?php

declare(strict_types=1);

namespace Fabriq\Orm\Schema;

/**
 * Table schema definition DSL.
 *
 * Used inside Schema::create() and Schema::alter() callbacks to
 * define columns, indexes, and constraints.
 *
 * Usage:
 *   Schema::create('users', function (Blueprint $table) {
 *       $table->uuid('id')->primary();
 *       $table->string('name', 100);
 *       $table->string('email', 255)->unique();
 *       $table->tenantId();
 *       $table->timestamps();
 *   });
 */
final class Blueprint
{
    /** @var string Table name */
    private string $table;

    /** @var list<array<string, mixed>> Column definitions */
    private array $columns = [];

    /** @var list<string> SQL commands to append (indexes, constraints) */
    private array $commands = [];

    /** @var bool Whether this is a CREATE or ALTER blueprint */
    private bool $isCreate;

    public function __construct(string $table, bool $isCreate = true)
    {
        $this->table = $table;
        $this->isCreate = $isCreate;
    }

    // ── Column Types ────────────────────────────────────────────────

    /**
     * Add a CHAR(36) UUID column.
     */
    public function uuid(string $name): ColumnDefinition
    {
        return $this->addColumn('CHAR(36)', $name);
    }

    /**
     * Add a BIGINT UNSIGNED AUTO_INCREMENT column.
     */
    public function id(string $name = 'id'): ColumnDefinition
    {
        return $this->addColumn('BIGINT UNSIGNED AUTO_INCREMENT', $name)->primary();
    }

    /**
     * Add a BIGINT UNSIGNED column (for foreign key references to auto-increment IDs).
     */
    public function unsignedBigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('BIGINT UNSIGNED', $name);
    }

    /**
     * Add a VARCHAR column.
     */
    public function string(string $name, int $length = 255): ColumnDefinition
    {
        return $this->addColumn("VARCHAR({$length})", $name);
    }

    /**
     * Add a TEXT column.
     */
    public function text(string $name): ColumnDefinition
    {
        return $this->addColumn('TEXT', $name);
    }

    /**
     * Add a LONGTEXT column.
     */
    public function longText(string $name): ColumnDefinition
    {
        return $this->addColumn('LONGTEXT', $name);
    }

    /**
     * Add an INT column.
     */
    public function integer(string $name): ColumnDefinition
    {
        return $this->addColumn('INT', $name);
    }

    /**
     * Add a BIGINT column.
     */
    public function bigInteger(string $name): ColumnDefinition
    {
        return $this->addColumn('BIGINT', $name);
    }

    /**
     * Add a TINYINT(1) boolean column.
     */
    public function boolean(string $name): ColumnDefinition
    {
        return $this->addColumn('TINYINT(1)', $name)->default(0);
    }

    /**
     * Add a DECIMAL column.
     */
    public function decimal(string $name, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->addColumn("DECIMAL({$precision},{$scale})", $name);
    }

    /**
     * Add a FLOAT column.
     */
    public function float(string $name): ColumnDefinition
    {
        return $this->addColumn('FLOAT', $name);
    }

    /**
     * Add a DOUBLE column.
     */
    public function double(string $name): ColumnDefinition
    {
        return $this->addColumn('DOUBLE', $name);
    }

    /**
     * Add a DATE column.
     */
    public function date(string $name): ColumnDefinition
    {
        return $this->addColumn('DATE', $name);
    }

    /**
     * Add a DATETIME column.
     */
    public function dateTime(string $name): ColumnDefinition
    {
        return $this->addColumn('DATETIME', $name);
    }

    /**
     * Add a TIMESTAMP column.
     */
    public function timestamp(string $name): ColumnDefinition
    {
        return $this->addColumn('TIMESTAMP', $name);
    }

    /**
     * Add a JSON column.
     */
    public function json(string $name): ColumnDefinition
    {
        return $this->addColumn('JSON', $name);
    }

    /**
     * Add a BLOB column.
     */
    public function blob(string $name): ColumnDefinition
    {
        return $this->addColumn('BLOB', $name);
    }

    /**
     * Add an ENUM column.
     *
     * @param list<string> $values
     */
    public function enum(string $name, array $values): ColumnDefinition
    {
        $escaped = array_map(fn($v) => "'{$v}'", $values);
        $type = 'ENUM(' . implode(', ', $escaped) . ')';
        return $this->addColumn($type, $name);
    }

    // ── Convenience Methods ─────────────────────────────────────────

    /**
     * Add a tenant_id CHAR(36) NOT NULL column with index.
     */
    public function tenantId(): ColumnDefinition
    {
        $col = $this->uuid('tenant_id')->notNull();
        $this->index('tenant_id');
        return $col;
    }

    /**
     * Add created_at and updated_at DATETIME columns.
     */
    public function timestamps(): void
    {
        $this->dateTime('created_at')->nullable();
        $this->dateTime('updated_at')->nullable();
    }

    /**
     * Add a soft-delete column (deleted_at).
     */
    public function softDeletes(): void
    {
        $this->dateTime('deleted_at')->nullable();
    }

    /**
     * Add a foreign key reference column.
     */
    public function foreignUuid(string $name): ColumnDefinition
    {
        return $this->uuid($name);
    }

    // ── Indexes & Constraints ───────────────────────────────────────

    /**
     * Add a PRIMARY KEY constraint.
     *
     * @param string|list<string> $columns
     */
    public function primary(string|array $columns): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $this->commands[] = "PRIMARY KEY ({$colList})";
    }

    /**
     * Add a UNIQUE index.
     *
     * @param string|list<string> $columns
     */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $idxName = $name ?? ('idx_' . $this->table . '_' . implode('_', $cols) . '_unique');
        $this->commands[] = "UNIQUE INDEX `{$idxName}` ({$colList})";
    }

    /**
     * Add an INDEX.
     *
     * @param string|list<string> $columns
     */
    public function index(string|array $columns, ?string $name = null): void
    {
        $cols = is_array($columns) ? $columns : [$columns];
        $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
        $idxName = $name ?? ('idx_' . $this->table . '_' . implode('_', $cols));
        $this->commands[] = "INDEX `{$idxName}` ({$colList})";
    }

    /**
     * Add a FOREIGN KEY constraint.
     */
    public function foreign(string $column, string $referencesTable, string $referencesColumn = 'id'): void
    {
        $fkName = "fk_{$this->table}_{$column}";
        $this->commands[] = "CONSTRAINT `{$fkName}` FOREIGN KEY (`{$column}`) REFERENCES `{$referencesTable}` (`{$referencesColumn}`)";
    }

    // ── ALTER Operations ────────────────────────────────────────────

    /**
     * Drop a column (ALTER TABLE only).
     */
    public function dropColumn(string $name): void
    {
        $this->commands[] = "DROP COLUMN `{$name}`";
    }

    /**
     * Drop an index (ALTER TABLE only).
     */
    public function dropIndex(string $name): void
    {
        $this->commands[] = "DROP INDEX `{$name}`";
    }

    /**
     * Rename a column (ALTER TABLE only).
     */
    public function renameColumn(string $from, string $to, string $type): void
    {
        $this->commands[] = "CHANGE COLUMN `{$from}` `{$to}` {$type}";
    }

    // ── SQL Generation ──────────────────────────────────────────────

    /**
     * Generate the CREATE TABLE SQL.
     */
    public function toCreateSql(string $charset = 'utf8mb4', string $engine = 'InnoDB'): string
    {
        $parts = [];

        foreach ($this->columns as $col) {
            $parts[] = $this->columnToSql($col);
        }

        // Append commands (indexes, constraints)
        foreach ($this->commands as $cmd) {
            $parts[] = $cmd;
        }

        $columnsSql = implode(",\n    ", $parts);

        return "CREATE TABLE IF NOT EXISTS `{$this->table}` (\n    {$columnsSql}\n) ENGINE={$engine} DEFAULT CHARSET={$charset}";
    }

    /**
     * Generate ALTER TABLE SQL statements.
     *
     * @return list<string>
     */
    public function toAlterSql(): array
    {
        $statements = [];

        foreach ($this->columns as $col) {
            $colSql = $this->columnToSql($col);
            $statements[] = "ALTER TABLE `{$this->table}` ADD COLUMN {$colSql}";
        }

        foreach ($this->commands as $cmd) {
            if (str_starts_with($cmd, 'DROP') || str_starts_with($cmd, 'CHANGE')) {
                $statements[] = "ALTER TABLE `{$this->table}` {$cmd}";
            } else {
                $statements[] = "ALTER TABLE `{$this->table}` ADD {$cmd}";
            }
        }

        return $statements;
    }

    // ── Internals ───────────────────────────────────────────────────

    private function addColumn(string $type, string $name): ColumnDefinition
    {
        $definition = new ColumnDefinition($name, $type);
        $this->columns[] = &$definition;

        // Store reference so that ColumnDefinition changes are reflected
        $idx = count($this->columns) - 1;

        // Use a wrapper to keep the reference updated
        $this->columns[$idx] = $definition;

        return $definition;
    }

    private function columnToSql(ColumnDefinition|array $col): string
    {
        if ($col instanceof ColumnDefinition) {
            return $col->toSql();
        }

        // Legacy array format (shouldn't happen)
        return "`{$col['name']}` {$col['type']}";
    }
}

/**
 * Fluent column definition builder.
 *
 * Returned by Blueprint column methods. Allows chaining modifiers:
 *   $table->string('name', 100)->notNull()->default('Guest');
 */
final class ColumnDefinition
{
    private string $name;
    private string $type;
    private bool $isNullable = false;
    private mixed $defaultValue = null;
    private bool $hasDefault = false;
    private bool $isPrimary = false;
    private bool $isUnique = false;
    private ?string $comment = null;
    private ?string $after = null;

    public function __construct(string $name, string $type)
    {
        $this->name = $name;
        $this->type = $type;
    }

    public function nullable(): self
    {
        $this->isNullable = true;
        return $this;
    }

    public function notNull(): self
    {
        $this->isNullable = false;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->defaultValue = $value;
        return $this;
    }

    public function primary(): self
    {
        $this->isPrimary = true;
        return $this;
    }

    public function unique(): self
    {
        $this->isUnique = true;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }

    public function after(string $column): self
    {
        $this->after = $column;
        return $this;
    }

    /**
     * Generate column SQL.
     */
    public function toSql(): string
    {
        $sql = "`{$this->name}` {$this->type}";

        if (!$this->isNullable) {
            $sql .= ' NOT NULL';
        } else {
            $sql .= ' NULL';
        }

        if ($this->hasDefault) {
            if ($this->defaultValue === null) {
                $sql .= ' DEFAULT NULL';
            } elseif (is_bool($this->defaultValue)) {
                $sql .= ' DEFAULT ' . ($this->defaultValue ? '1' : '0');
            } elseif (is_int($this->defaultValue) || is_float($this->defaultValue)) {
                $sql .= " DEFAULT {$this->defaultValue}";
            } else {
                $escaped = addslashes((string) $this->defaultValue);
                $sql .= " DEFAULT '{$escaped}'";
            }
        }

        if ($this->isPrimary) {
            $sql .= ' PRIMARY KEY';
        }

        if ($this->isUnique) {
            $sql .= ' UNIQUE';
        }

        if ($this->comment !== null) {
            $escaped = addslashes($this->comment);
            $sql .= " COMMENT '{$escaped}'";
        }

        if ($this->after !== null) {
            $sql .= " AFTER `{$this->after}`";
        }

        return $sql;
    }
}

