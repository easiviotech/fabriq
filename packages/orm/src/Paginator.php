<?php

declare(strict_types=1);

namespace Fabriq\Orm;

/**
 * Paginated result set.
 *
 * Wraps a Collection with pagination metadata: current page,
 * per-page count, total items, and total pages.
 *
 * @template T
 */
final class Paginator
{
    /** @var Collection<T> */
    private Collection $items;

    public function __construct(
        Collection $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage,
    ) {
        $this->items = $items;
    }

    /**
     * Get the items for this page.
     *
     * @return Collection<T>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Total number of items across all pages.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Items per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Current page number (1-based).
     */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /**
     * Last (total) page number.
     */
    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    /**
     * Whether there are more pages after the current one.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Serialize to array (useful for JSON API responses).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data'         => $this->items->toArray(),
            'total'        => $this->total,
            'per_page'     => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage(),
            'has_more'     => $this->hasMorePages(),
        ];
    }
}

