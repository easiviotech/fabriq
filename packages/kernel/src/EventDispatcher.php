<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

/**
 * Lightweight event dispatcher for lifecycle hooks.
 *
 * Supplements the middleware chain with before/after hooks
 * for cross-cutting concerns (logging, profiling, cleanup).
 *
 * Built-in events:
 *   - request.received  — fired when an HTTP request arrives
 *   - request.handled   — fired after a route handler returns
 *   - request.error     — fired when an unhandled exception occurs
 *   - server.booted     — fired after all service providers are booted
 *   - worker.started    — fired when a Swoole worker process starts
 */
final class EventDispatcher
{
    /** @var array<string, list<callable(mixed...): void>> */
    private array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * @param string $event   Event name (e.g. 'request.received')
     * @param callable $listener  Receives event-specific arguments
     */
    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Dispatch an event to all registered listeners.
     *
     * @param string $event   Event name
     * @param array<int, mixed> $payload  Arguments passed to each listener
     */
    public function dispatch(string $event, array $payload = []): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            $listener(...$payload);
        }
    }

    /**
     * Check if an event has any registered listeners.
     */
    public function hasListeners(string $event): bool
    {
        return !empty($this->listeners[$event]);
    }

    /**
     * Remove all listeners for an event, or all listeners entirely.
     */
    public function forget(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }
}
