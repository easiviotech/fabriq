<?php

declare(strict_types=1);

namespace SwooleFabric\Kernel;

use Closure;
use RuntimeException;

/**
 * Minimal PSR-11-like dependency injection container.
 *
 * Supports bind (factory), singleton, and instance registration.
 * Built per-worker in onWorkerStart — never shared across workers.
 */
final class Container
{
    /** @var array<string, Closure> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Register a factory closure.
     */
    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Register a singleton — factory runs once, result cached.
     */
    public function singleton(string $id, Closure $factory): void
    {
        $this->bindings[$id] = function () use ($id, $factory) {
            if (!isset($this->instances[$id])) {
                $this->instances[$id] = $factory($this);
            }
            return $this->instances[$id];
        };
    }

    /**
     * Register a pre-built instance.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
        unset($this->bindings[$id]);
    }

    /**
     * Resolve a binding.
     *
     * @throws RuntimeException if binding not found
     */
    public function make(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            return ($this->bindings[$id])($this);
        }

        throw new RuntimeException("Container: no binding for [{$id}]");
    }

    /**
     * Check if a binding or instance exists.
     */
    public function has(string $id): bool
    {
        return isset($this->bindings[$id]) || isset($this->instances[$id]);
    }
}
