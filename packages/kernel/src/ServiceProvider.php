<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

/**
 * Base class for all Fabriq service providers.
 *
 * Providers follow a two-phase lifecycle:
 *   1. register() — Bind services into the container. Do NOT resolve other services here.
 *   2. boot()     — Cross-wire services. All bindings are available; safe to resolve.
 *
 * List providers in config/app.php → 'providers' for auto-registration.
 */
abstract class ServiceProvider
{
    public function __construct(
        protected readonly Application $app,
    ) {}

    /**
     * Register bindings into the container.
     *
     * Called once during application bootstrap. Do NOT resolve
     * other services here — use boot() for cross-service wiring.
     */
    public function register(): void
    {
        // Override in subclass
    }

    /**
     * Boot the service provider.
     *
     * Called after ALL providers have been registered.
     * Safe to resolve any service from the container.
     */
    public function boot(): void
    {
        // Override in subclass
    }
}

