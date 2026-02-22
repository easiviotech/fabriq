<?php

declare(strict_types=1);

namespace Fabriq\Http\Frontend;

use Fabriq\Tenancy\TenantContext;

/**
 * Async job handler for queue-based frontend builds.
 *
 * Registered on the Consumer as:
 *   $consumer->registerHandler('frontend:build', new BuildFrontendJob($builder, $tenantLookup));
 *
 * Dispatched via:
 *   $dispatcher->dispatch('default', 'frontend:build', ['tenant_slug' => 'acme']);
 */
final class BuildFrontendJob
{
    /** @var callable(string): ?TenantContext */
    private $tenantLookup;

    /**
     * @param FrontendBuilder $builder
     * @param callable(string): ?TenantContext $tenantLookup  fn(slug) → TenantContext|null
     */
    public function __construct(
        private readonly FrontendBuilder $builder,
        callable $tenantLookup,
    ) {
        $this->tenantLookup = $tenantLookup;
    }

    /**
     * Handle the queued job payload.
     *
     * @param array{tenant_slug: string, triggered_by?: string} $payload
     */
    public function __invoke(array $payload): void
    {
        $slug = $payload['tenant_slug'] ?? '';
        $triggeredBy = $payload['triggered_by'] ?? 'queue';

        if ($slug === '') {
            error_log("[Fabriq][BuildFrontendJob] Missing tenant_slug in payload");
            return;
        }

        $tenant = ($this->tenantLookup)($slug);
        if ($tenant === null) {
            error_log("[Fabriq][BuildFrontendJob] Tenant not found: {$slug}");
            return;
        }

        echo "[Fabriq][BuildFrontendJob] Building frontend for '{$slug}' (triggered by: {$triggeredBy})\n";

        $result = $this->builder->build($tenant);

        if ($result->status === 'success') {
            echo "[Fabriq][BuildFrontendJob] Build succeeded for '{$slug}' "
                . "({$result->commitHash}, {$result->durationMs}ms)\n";
        } else {
            error_log("[Fabriq][BuildFrontendJob] Build failed for '{$slug}': " . $result->log);
        }
    }
}
