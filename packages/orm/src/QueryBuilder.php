<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use Fabriq\Kernel\Context;
use RuntimeException;

/**
 * Fluent SQL query builder.
 *
 * Each method returns a NEW instance (immutable builder pattern) so
 * builders can be safely shared across coroutines.
 *
 * Connection lifecycle: borrow → execute → release happens entirely
 * within the terminal methods (get, first, insert, update, delete, paginate).
 * No connection is ever held on the builder instance.
 *
 * Tenant scoping: when $tenantScoped is true, all SELECT/UPDATE/DELETE
 * queries automatically append `WHERE tenant_id = ?` and INSERT injects
 * the tenant_id column.
 *
 * Usage:
 *   $users = DB::table('users')->where('status', 'active')->orderBy('name')->get();
 */
final class QueryBuilder
{
    private string $table = '';
    private string $pool = 'app';
    private bool $tenantScoped = false;

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<array{type: string, column: string, operator: string, value: mixed, boolean: string}> */
    private array $wheres = [];

    /** @var list<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<array{column: string, direction: string}> */
    private array $orders = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{column: string, operator: string, value: mixed, boolean: string}> */
    private array $havings = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private bool $distinct = false;

    /** @var string|null Model class to hydrate results into */
    private ?string $modelClass = null;

    /** @var TenantDbRouter|null Injected by DB facade or Model */
    private ?TenantDbRouter $router = null;

    // ── Construction ────────────────────────────────────────────────

    public function __construct(?TenantDbRouter $router = null)
    {
        $this->router = $router;
    }

    /**
     * Clone helper — ensures each chained call is a new instance.
     */
    private function clone(): self
    {
        return clone $this;
    }

    // ── Table & Pool ────────────────────────────────────────────────

    public function table(string $table): self
    {
        $q = $this->clone();
        $q->table = $table;
        return $q;
    }

    public function from(string $table): self
    {
        return $this->table($table);
    }

    /**
     * Set the connection pool (default: 'app').
     */
    public function on(string $pool): self
    {
        $q = $this->clone();
        $q->pool = $pool;
        return $q;
    }

    /**
     * Enable automatic tenant_id scoping.
     */
    public function tenantScoped(bool $scoped = true): self
    {
        $q = $this->clone();
        $q->tenantScoped = $scoped;
        return $q;
    }

    /**
     * Set model class for result hydration.
     *
     * @param class-string $class
     */
    public function setModelClass(string $class): self
    {
        $q = $this->clone();
        $q->modelClass = $class;
        return $q;
    }

    // ── SELECT ──────────────────────────────────────────────────────

    /**
     * @param string|list<string> ...$columns
     */
    public function select(string ...$columns): self
    {
        $q = $this->clone();
        $q->columns = array_values($columns);
        return $q;
    }

    public function addSelect(string ...$columns): self
    {
        $q = $this->clone();
        if ($q->columns === ['*']) {
            $q->columns = [];
        }
        $q->columns = array_merge($q->columns, array_values($columns));
        return $q;
    }

    public function distinct(bool $distinct = true): self
    {
        $q = $this->clone();
        $q->distinct = $distinct;
        return $q;
    }

    // ── WHERE ───────────────────────────────────────────────────────

    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null, string $boolean = 'AND'): self
    {
        $q = $this->clone();

        if ($value === null && $operatorOrValue !== null) {
            // Two-argument form: where('col', 'value') → col = value
            $q->wheres[] = [
                'type'     => 'basic',
                'column'   => $column,
                'operator' => '=',
                'value'    => $operatorOrValue,
                'boolean'  => $boolean,
            ];
        } else {
            $q->wheres[] = [
                'type'     => 'basic',
                'column'   => $column,
                'operator' => (string) $operatorOrValue,
                'value'    => $value,
                'boolean'  => $boolean,
            ];
        }

        return $q;
    }

    public function orWhere(string $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        return $this->where($column, $operatorOrValue, $value, 'OR');
    }

    public function whereIn(string $column, array $values, string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'in',
            'column'   => $column,
            'operator' => 'IN',
            'value'    => $values,
            'boolean'  => $boolean,
        ];
        return $q;
    }

    public function whereNotIn(string $column, array $values): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'not_in',
            'column'   => $column,
            'operator' => 'NOT IN',
            'value'    => $values,
            'boolean'  => 'AND',
        ];
        return $q;
    }

    public function whereNull(string $column, string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'null',
            'column'   => $column,
            'operator' => 'IS NULL',
            'value'    => null,
            'boolean'  => $boolean,
        ];
        return $q;
    }

    public function whereNotNull(string $column, string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'not_null',
            'column'   => $column,
            'operator' => 'IS NOT NULL',
            'value'    => null,
            'boolean'  => $boolean,
        ];
        return $q;
    }

    public function whereBetween(string $column, mixed $min, mixed $max, string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'between',
            'column'   => $column,
            'operator' => 'BETWEEN',
            'value'    => [$min, $max],
            'boolean'  => $boolean,
        ];
        return $q;
    }

    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->wheres[] = [
            'type'     => 'raw',
            'column'   => $sql,
            'operator' => '',
            'value'    => $bindings,
            'boolean'  => $boolean,
        ];
        return $q;
    }

    // ── JOINS ───────────────────────────────────────────────────────

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $q = $this->clone();
        $q->joins[] = [
            'type'     => 'INNER',
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
        ];
        return $q;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        $q = $this->clone();
        $q->joins[] = [
            'type'     => 'LEFT',
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
        ];
        return $q;
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        $q = $this->clone();
        $q->joins[] = [
            'type'     => 'RIGHT',
            'table'    => $table,
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
        ];
        return $q;
    }

    // ── ORDER BY ────────────────────────────────────────────────────

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $q = $this->clone();
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $q->orders[] = ['column' => $column, 'direction' => $direction];
        return $q;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    // ── GROUP BY / HAVING ───────────────────────────────────────────

    public function groupBy(string ...$columns): self
    {
        $q = $this->clone();
        $q->groups = array_merge($q->groups, array_values($columns));
        return $q;
    }

    public function having(string $column, string $operator, mixed $value, string $boolean = 'AND'): self
    {
        $q = $this->clone();
        $q->havings[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => $boolean,
        ];
        return $q;
    }

    // ── LIMIT / OFFSET ─────────────────────────────────────────────

    public function limit(int $limit): self
    {
        $q = $this->clone();
        $q->limitValue = $limit;
        return $q;
    }

    public function offset(int $offset): self
    {
        $q = $this->clone();
        $q->offsetValue = $offset;
        return $q;
    }

    public function take(int $count): self
    {
        return $this->limit($count);
    }

    public function skip(int $count): self
    {
        return $this->offset($count);
    }

    // ── Terminal: SELECT ────────────────────────────────────────────

    /**
     * Execute SELECT and return all rows as a Collection.
     *
     * @return Collection<array<string, mixed>>|Collection<Model>
     */
    public function get(): Collection
    {
        [$sql, $params] = $this->compileSelect();
        $rows = $this->execute($sql, $params);

        if ($this->modelClass !== null && class_exists($this->modelClass)) {
            $class = $this->modelClass;
            $models = array_map(fn(array $row) => $class::fromRow($row), $rows);
            return new Collection($models);
        }

        return new Collection($rows);
    }

    /**
     * Execute SELECT and return the first row.
     *
     * @return array<string, mixed>|Model|null
     */
    public function first(): mixed
    {
        $result = $this->limit(1)->get();
        return $result->first();
    }

    /**
     * Execute SELECT and return the value of a single column from the first row.
     */
    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();

        if ($row === null) {
            return null;
        }

        if (is_array($row)) {
            return $row[$column] ?? null;
        }

        return $row->{$column} ?? null;
    }

    /**
     * Execute COUNT(*) and return the count.
     */
    public function count(string $column = '*'): int
    {
        $q = $this->clone();
        $q->columns = ["COUNT({$column}) AS aggregate"];
        [$sql, $params] = $q->compileSelect();
        $rows = $q->execute($sql, $params);
        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Check if any rows exist.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Execute a paginated SELECT.
     *
     * @return Paginator<array<string, mixed>>|Paginator<Model>
     */
    public function paginate(int $perPage = 15, int $page = 1): Paginator
    {
        $page = max(1, $page);
        $total = $this->count();

        $items = $this->limit($perPage)->offset(($page - 1) * $perPage)->get();

        return new Paginator($items, $total, $perPage, $page);
    }

    // ── Terminal: INSERT ────────────────────────────────────────────

    /**
     * Insert a single row.
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return int|string Insert ID (or 0 if none)
     */
    public function insert(array $data): int|string
    {
        $data = $this->injectTenantId($data);

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $params = array_values($data);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(fn($c) => "`{$c}`", $columns)),
            implode(', ', $placeholders),
        );

        return $this->executeWrite($sql, $params, returnInsertId: true);
    }

    /**
     * Insert multiple rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return int Number of rows inserted
     */
    public function insertMany(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $rows = array_map(fn($row) => $this->injectTenantId($row), $rows);
        $columns = array_keys($rows[0]);
        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES %s',
            $this->table,
            implode(', ', array_map(fn($c) => "`{$c}`", $columns)),
            $allPlaceholders,
        );

        return (int) $this->executeWrite($sql, $params);
    }

    // ── Terminal: UPDATE ────────────────────────────────────────────

    /**
     * Update rows matching the current WHERE clauses.
     *
     * @param array<string, mixed> $data Column => value pairs
     * @return int Number of affected rows
     */
    public function update(array $data): int
    {
        $setClauses = [];
        $params = [];

        foreach ($data as $column => $value) {
            $setClauses[] = "`{$column}` = ?";
            $params[] = $value;
        }

        $sql = sprintf('UPDATE `%s` SET %s', $this->table, implode(', ', $setClauses));

        // Append WHERE (including tenant scope)
        [$whereSql, $whereParams] = $this->compileWheresWithTenant();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
            $params = array_merge($params, $whereParams);
        }

        return (int) $this->executeWrite($sql, $params);
    }

    // ── Terminal: DELETE ────────────────────────────────────────────

    /**
     * Delete rows matching the current WHERE clauses.
     *
     * @return int Number of affected rows
     */
    public function delete(): int
    {
        $sql = sprintf('DELETE FROM `%s`', $this->table);

        [$whereSql, $whereParams] = $this->compileWheresWithTenant();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        return (int) $this->executeWrite($sql, $whereParams);
    }

    // ── Terminal: Raw ───────────────────────────────────────────────

    /**
     * Execute a raw SQL query.
     *
     * @param string $sql
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function raw(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params);
    }

    // ── SQL Compilation ─────────────────────────────────────────────

    /**
     * Compile a full SELECT statement.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function compileSelect(): array
    {
        $sql = 'SELECT ';

        if ($this->distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= implode(', ', $this->columns);
        $sql .= ' FROM `' . $this->table . '`';

        // Joins
        foreach ($this->joins as $join) {
            $sql .= " {$join['type']} JOIN `{$join['table']}` ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        // WHERE (with tenant scope)
        [$whereSql, $params] = $this->compileWheresWithTenant();
        if ($whereSql !== '') {
            $sql .= ' WHERE ' . $whereSql;
        }

        // GROUP BY
        if (!empty($this->groups)) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        // HAVING
        if (!empty($this->havings)) {
            $havingParts = [];
            foreach ($this->havings as $i => $having) {
                $prefix = $i === 0 ? '' : " {$having['boolean']} ";
                $havingParts[] = $prefix . "{$having['column']} {$having['operator']} ?";
                $params[] = $having['value'];
            }
            $sql .= ' HAVING ' . implode('', $havingParts);
        }

        // ORDER BY
        if (!empty($this->orders)) {
            $orderParts = array_map(
                fn($o) => "{$o['column']} {$o['direction']}",
                $this->orders
            );
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }

        // LIMIT / OFFSET
        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }
        if ($this->offsetValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }

        return [$sql, $params];
    }

    /**
     * Compile WHERE clauses with automatic tenant scoping.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function compileWheresWithTenant(): array
    {
        $wheres = $this->wheres;
        $params = [];

        // Auto-inject tenant_id WHERE clause
        if ($this->tenantScoped) {
            $tenantId = Context::tenantId();
            if ($tenantId !== null && $tenantId !== '') {
                array_unshift($wheres, [
                    'type'     => 'basic',
                    'column'   => 'tenant_id',
                    'operator' => '=',
                    'value'    => $tenantId,
                    'boolean'  => 'AND',
                ]);
            }
        }

        if (empty($wheres)) {
            return ['', []];
        }

        $parts = [];
        foreach ($wheres as $i => $w) {
            $prefix = $i === 0 ? '' : " {$w['boolean']} ";

            switch ($w['type']) {
                case 'basic':
                    $parts[] = $prefix . "`{$w['column']}` {$w['operator']} ?";
                    $params[] = $w['value'];
                    break;

                case 'in':
                case 'not_in':
                    $placeholders = implode(', ', array_fill(0, count($w['value']), '?'));
                    $parts[] = $prefix . "`{$w['column']}` {$w['operator']} ({$placeholders})";
                    $params = array_merge($params, array_values($w['value']));
                    break;

                case 'null':
                case 'not_null':
                    $parts[] = $prefix . "`{$w['column']}` {$w['operator']}";
                    break;

                case 'between':
                    $parts[] = $prefix . "`{$w['column']}` BETWEEN ? AND ?";
                    $params[] = $w['value'][0];
                    $params[] = $w['value'][1];
                    break;

                case 'raw':
                    $parts[] = $prefix . $w['column'];
                    if (is_array($w['value'])) {
                        $params = array_merge($params, $w['value']);
                    }
                    break;
            }
        }

        return [implode('', $parts), $params];
    }

    // ── Tenant ID Injection ─────────────────────────────────────────

    /**
     * Inject tenant_id into INSERT data if scoped.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function injectTenantId(array $data): array
    {
        if (!$this->tenantScoped) {
            return $data;
        }

        $tenantId = Context::tenantId();
        if ($tenantId !== null && $tenantId !== '' && !isset($data['tenant_id'])) {
            $data['tenant_id'] = $tenantId;
        }

        return $data;
    }

    // ── Execution ───────────────────────────────────────────────────

    /**
     * Execute a SELECT query and return result rows.
     *
     * @param string $sql
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function execute(string $sql, array $params): array
    {
        if ($this->router === null) {
            throw new RuntimeException('QueryBuilder requires a TenantDbRouter. Use DB::table() or set a router.');
        }

        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            if (empty($params)) {
                $result = $conn->query($sql);
            } else {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new RuntimeException("MySQL prepare failed: {$conn->error} (SQL: {$sql})");
                }
                $result = $stmt->execute($params);
            }

            if ($result === false) {
                throw new RuntimeException("MySQL query failed: {$conn->error} (SQL: {$sql})");
            }

            return is_array($result) ? $result : [];
        } finally {
            $this->router->release($handle);
        }
    }

    /**
     * Execute a write query (INSERT/UPDATE/DELETE).
     *
     * @param string $sql
     * @param list<mixed> $params
     * @param bool $returnInsertId
     * @return int|string Affected rows or insert ID
     */
    private function executeWrite(string $sql, array $params, bool $returnInsertId = false): int|string
    {
        if ($this->router === null) {
            throw new RuntimeException('QueryBuilder requires a TenantDbRouter. Use DB::table() or set a router.');
        }

        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            if (empty($params)) {
                $conn->query($sql);
            } else {
                $stmt = $conn->prepare($sql);
                if ($stmt === false) {
                    throw new RuntimeException("MySQL prepare failed: {$conn->error} (SQL: {$sql})");
                }
                $result = $stmt->execute($params);
                if ($result === false) {
                    throw new RuntimeException("MySQL execute failed: {$conn->error} (SQL: {$sql})");
                }
            }

            if ($returnInsertId) {
                return $conn->insert_id ?? 0;
            }

            return $conn->affected_rows ?? 0;
        } finally {
            $this->router->release($handle);
        }
    }

    /**
     * Get the compiled SQL and params for debugging.
     *
     * @return array{sql: string, params: list<mixed>}
     */
    public function toSql(): array
    {
        [$sql, $params] = $this->compileSelect();
        return ['sql' => $sql, 'params' => $params];
    }
}

