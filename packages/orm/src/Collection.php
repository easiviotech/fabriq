<?php

declare(strict_types=1);

namespace Fabriq\Orm;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Typed array wrapper for query results.
 *
 * Provides functional helpers (map, filter, pluck, etc.) over an array
 * of Model instances or associative arrays.
 *
 * @template T
 * @implements IteratorAggregate<int, T>
 * @implements ArrayAccess<int, T>
 */
final class Collection implements Countable, IteratorAggregate, ArrayAccess
{
    /** @var list<T> */
    private array $items;

    /**
     * @param list<T> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = array_values($items);
    }

    // ── Accessors ────────────────────────────────────────────────────

    /**
     * Get all items as a plain array.
     *
     * @return list<T>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Alias for all().
     *
     * @return list<T>
     */
    public function toArray(): array
    {
        return array_map(function ($item) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                return $item->toArray();
            }
            return $item;
        }, $this->items);
    }

    /**
     * Get the first item, or default if empty.
     *
     * @param T|null $default
     * @return T|null
     */
    public function first(mixed $default = null): mixed
    {
        return $this->items[0] ?? $default;
    }

    /**
     * Get the last item, or default if empty.
     *
     * @param T|null $default
     * @return T|null
     */
    public function last(mixed $default = null): mixed
    {
        if (empty($this->items)) {
            return $default;
        }
        return $this->items[count($this->items) - 1];
    }

    /**
     * Get item at index.
     *
     * @return T|null
     */
    public function get(int $index): mixed
    {
        return $this->items[$index] ?? null;
    }

    // ── Functional Helpers ───────────────────────────────────────────

    /**
     * Map each item through a callback.
     *
     * @template U
     * @param callable(T, int): U $callback
     * @return self<U>
     */
    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items, array_keys($this->items)));
    }

    /**
     * Filter items using a callback.
     *
     * @param callable(T, int): bool $callback
     * @return self<T>
     */
    public function filter(callable $callback): self
    {
        return new self(array_values(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH)));
    }

    /**
     * Pluck a single column/property from each item.
     *
     * @return self<mixed>
     */
    public function pluck(string $key): self
    {
        return new self(array_map(function ($item) use ($key) {
            if (is_array($item)) {
                return $item[$key] ?? null;
            }
            if (is_object($item)) {
                return $item->{$key} ?? null;
            }
            return null;
        }, $this->items));
    }

    /**
     * Key the collection by a column/property.
     *
     * @return array<string|int, T>
     */
    public function keyBy(string $key): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
            if ($k !== null) {
                $result[$k] = $item;
            }
        }
        return $result;
    }

    /**
     * Group items by a column/property.
     *
     * @return array<string|int, list<T>>
     */
    public function groupBy(string $key): array
    {
        $result = [];
        foreach ($this->items as $item) {
            $k = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
            if ($k !== null) {
                $result[$k][] = $item;
            }
        }
        return $result;
    }

    /**
     * Reduce the collection to a single value.
     *
     * @template U
     * @param callable(U, T): U $callback
     * @param U $initial
     * @return U
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Execute a callback for each item.
     *
     * @param callable(T, int): void $callback
     */
    public function each(callable $callback): self
    {
        foreach ($this->items as $index => $item) {
            $callback($item, $index);
        }
        return $this;
    }

    /**
     * Check if any item matches a condition.
     *
     * @param callable(T): bool $callback
     */
    public function contains(callable $callback): bool
    {
        foreach ($this->items as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get unique items (based on serialized comparison).
     *
     * @return self<T>
     */
    public function unique(?string $key = null): self
    {
        if ($key !== null) {
            $seen = [];
            $result = [];
            foreach ($this->items as $item) {
                $value = is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
                $hash = serialize($value);
                if (!isset($seen[$hash])) {
                    $seen[$hash] = true;
                    $result[] = $item;
                }
            }
            return new self($result);
        }

        return new self(array_values(array_unique($this->items, SORT_REGULAR)));
    }

    /**
     * Sort items using a callback or key.
     *
     * @param callable(T, T): int|string $callbackOrKey
     * @return self<T>
     */
    public function sortBy(callable|string $callbackOrKey, bool $descending = false): self
    {
        $items = $this->items;

        if (is_string($callbackOrKey)) {
            $key = $callbackOrKey;
            usort($items, function ($a, $b) use ($key, $descending) {
                $va = is_array($a) ? ($a[$key] ?? null) : ($a->{$key} ?? null);
                $vb = is_array($b) ? ($b[$key] ?? null) : ($b->{$key} ?? null);
                $cmp = $va <=> $vb;
                return $descending ? -$cmp : $cmp;
            });
        } else {
            usort($items, $callbackOrKey);
            if ($descending) {
                $items = array_reverse($items);
            }
        }

        return new self($items);
    }

    /**
     * Slice the collection.
     *
     * @return self<T>
     */
    public function slice(int $offset, ?int $length = null): self
    {
        return new self(array_slice($this->items, $offset, $length));
    }

    /**
     * Chunk the collection into groups of the given size.
     *
     * @return list<self<T>>
     */
    public function chunk(int $size): array
    {
        return array_map(
            fn(array $chunk) => new self($chunk),
            array_chunk($this->items, max(1, $size))
        );
    }

    // ── State Checks ────────────────────────────────────────────────

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !empty($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    // ── IteratorAggregate ───────────────────────────────────────────

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    // ── ArrayAccess ─────────────────────────────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
        $this->items = array_values($this->items);
    }
}

