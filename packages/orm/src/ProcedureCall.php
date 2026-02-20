<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use RuntimeException;

/**
 * Fluent builder for stored procedure calls.
 *
 * Handles IN parameters (via prepared statement placeholders) and OUT
 * parameters (via MySQL session variables with a follow-up SELECT).
 *
 * Automatically routes through TenantDbRouter so the CALL executes
 * on the correct database for the current tenant.
 *
 * Usage:
 *   $result = DB::call('sp_get_user_stats')
 *       ->in('user_id', $userId)
 *       ->out('total_orders')
 *       ->out('total_spent')
 *       ->exec();
 *
 *   $totalOrders = $result->out('total_orders');
 *   $rows        = $result->rows();
 */
final class ProcedureCall
{
    private string $procedure;
    private string $pool = 'app';

    /** @var list<array{name: string, value: mixed}> IN parameters */
    private array $inParams = [];

    /** @var list<string> OUT parameter names */
    private array $outParams = [];

    private ?TenantDbRouter $router = null;

    public function __construct(string $procedure, ?TenantDbRouter $router = null)
    {
        $this->procedure = $procedure;
        $this->router = $router;
    }

    /**
     * Set the connection pool.
     */
    public function on(string $pool): self
    {
        $this->pool = $pool;
        return $this;
    }

    /**
     * Add an IN parameter.
     */
    public function in(string $name, mixed $value): self
    {
        $this->inParams[] = ['name' => $name, 'value' => $value];
        return $this;
    }

    /**
     * Register an OUT parameter.
     */
    public function out(string $name): self
    {
        $this->outParams[] = $name;
        return $this;
    }

    /**
     * Add multiple IN parameters at once.
     *
     * @param array<string, mixed> $params
     */
    public function withParams(array $params): self
    {
        foreach ($params as $name => $value) {
            $this->inParams[] = ['name' => $name, 'value' => $value];
        }
        return $this;
    }

    /**
     * Execute the stored procedure call.
     *
     * @return ProcedureResult
     */
    public function exec(): ProcedureResult
    {
        if ($this->router === null) {
            throw new RuntimeException('ProcedureCall requires a TenantDbRouter. Use DB::call().');
        }

        $handle = $this->router->acquire($this->pool);
        $conn = $handle['conn'];

        try {
            return $this->executeOnConnection($conn);
        } finally {
            $this->router->release($handle);
        }
    }

    /**
     * Execute the procedure on a given connection.
     */
    private function executeOnConnection(mixed $conn): ProcedureResult
    {
        // Build the CALL argument list
        $callArgs = [];
        $inValues = [];

        foreach ($this->inParams as $param) {
            $callArgs[] = '?';
            $inValues[] = $param['value'];
        }

        // OUT params use session variables: @out_name
        foreach ($this->outParams as $outName) {
            $callArgs[] = '@' . $outName;
        }

        $argList = implode(', ', $callArgs);
        $callSql = "CALL `{$this->procedure}`({$argList})";

        // Execute the CALL statement
        $rows = [];
        if (empty($inValues)) {
            $result = $conn->query($callSql);
            if (is_array($result)) {
                $rows = $result;
            }
        } else {
            $stmt = $conn->prepare($callSql);
            if ($stmt === false) {
                throw new RuntimeException(
                    "MySQL prepare failed for procedure [{$this->procedure}]: {$conn->error}"
                );
            }
            $result = $stmt->execute($inValues);
            if ($result === false) {
                throw new RuntimeException(
                    "MySQL execute failed for procedure [{$this->procedure}]: {$conn->error}"
                );
            }
            if (is_array($result)) {
                $rows = $result;
            }
        }

        // Read OUT parameters via SELECT @name, @name2, ...
        $outValues = [];
        if (!empty($this->outParams)) {
            $selectParts = array_map(fn($n) => "@{$n} AS `{$n}`", $this->outParams);
            $selectSql = 'SELECT ' . implode(', ', $selectParts);

            $outResult = $conn->query($selectSql);
            if (is_array($outResult) && !empty($outResult)) {
                $outValues = $outResult[0];
            }
        }

        return new ProcedureResult($rows, $outValues);
    }
}

