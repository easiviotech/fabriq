<?php

declare(strict_types=1);

namespace Fabriq\Orm;

/**
 * Result wrapper for stored procedure calls.
 *
 * Provides access to:
 *  - Result sets (rows returned by SELECT inside the procedure)
 *  - OUT parameter values (read via MySQL session variables)
 */
final class ProcedureResult
{
    /**
     * @param list<array<string, mixed>> $rows     Result set rows
     * @param array<string, mixed>       $outParams OUT parameter values
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly array $outParams = [],
    ) {}

    /**
     * Get all result set rows.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * Get the rows as a Collection.
     *
     * @return Collection<array<string, mixed>>
     */
    public function collect(): Collection
    {
        return new Collection($this->rows);
    }

    /**
     * Get the first row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->rows[0] ?? null;
    }

    /**
     * Get a single OUT parameter value.
     */
    public function out(string $name): mixed
    {
        return $this->outParams[$name] ?? null;
    }

    /**
     * Get all OUT parameters.
     *
     * @return array<string, mixed>
     */
    public function allOut(): array
    {
        return $this->outParams;
    }

    /**
     * Check if the result has any rows.
     */
    public function hasRows(): bool
    {
        return !empty($this->rows);
    }

    /**
     * Get the number of rows.
     */
    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * Serialize to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'out' => $this->outParams,
        ];
    }
}

