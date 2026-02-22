<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Frontend\FrontendBuilder;
use Fabriq\Kernel\Config;
use Fabriq\Queue\Dispatcher;
use App\Repositories\ChatRepository;

/**
 * Handles frontend build & deploy operations.
 *
 * Endpoints:
 *   POST /api/tenants/{tenantId}/frontend/deploy  — trigger a build via the queue
 *   GET  /api/tenants/{tenantId}/frontend/status   — check build / deployment status
 *   POST /api/webhooks/frontend/deploy              — webhook trigger (GitHub, GitLab, etc.)
 */
final class FrontendDeployController extends Controller
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly FrontendBuilder $builder,
        private readonly ChatRepository $repo,
        private readonly Config $config,
    ) {}

    /**
     * POST /api/tenants/{tenantId}/frontend/deploy
     *
     * Dispatches an async build job for the given tenant.
     */
    public function deploy(Request $request, Response $response, array $params): void
    {
        $tenantId = $params['tenantId'] ?? '';
        if ($tenantId === '') {
            $response->error('Missing tenantId', 400);
            return;
        }

        $tenant = $this->repo->findTenantById($tenantId);
        if ($tenant === null) {
            $response->error('Tenant not found', 404);
            return;
        }

        $frontendConfig = $tenant['config'] ?? [];
        if (is_string($frontendConfig)) {
            $frontendConfig = json_decode($frontendConfig, true) ?? [];
        }

        if (empty($frontendConfig['frontend']['repository'] ?? '')) {
            $response->error('No frontend.repository configured for this tenant', 422);
            return;
        }

        $this->dispatcher->dispatch('default', 'frontend:build', [
            'tenant_slug' => $tenant['slug'],
            'triggered_by' => 'api',
        ]);

        $response->json([
            'message' => 'Build queued',
            'tenant_slug' => $tenant['slug'],
            'status_url' => "/api/tenants/{$tenantId}/frontend/status",
        ], 202);
    }

    /**
     * GET /api/tenants/{tenantId}/frontend/status
     *
     * Returns the current/last build status and deployment info.
     */
    public function status(Request $request, Response $response, array $params): void
    {
        $tenantId = $params['tenantId'] ?? '';
        if ($tenantId === '') {
            $response->error('Missing tenantId', 400);
            return;
        }

        $tenant = $this->repo->findTenantById($tenantId);
        if ($tenant === null) {
            $response->error('Tenant not found', 404);
            return;
        }

        $slug = $tenant['slug'];

        $buildStatus = $this->builder->status($slug);

        $docRoot = (string) $this->config->get('static.document_root', 'public');
        $basePath = dirname(__DIR__, 3);
        $deployDir = $basePath . DIRECTORY_SEPARATOR . $docRoot . DIRECTORY_SEPARATOR . $slug;
        $indexFile = (string) $this->config->get('static.index', 'index.html');

        $deployed = is_dir($deployDir);
        $hasIndex = $deployed && is_file($deployDir . DIRECTORY_SEPARATOR . $indexFile);

        $data = [
            'tenant_slug' => $slug,
            'deployed' => $deployed,
            'has_index' => $hasIndex,
        ];

        if ($buildStatus !== null) {
            $data['last_build'] = $buildStatus->toArray();
        }

        $response->json($data);
    }

    /**
     * POST /api/webhooks/frontend/deploy
     *
     * Webhook endpoint for external CI systems (GitHub, GitLab, etc.).
     * Requires X-Webhook-Secret header for authentication.
     */
    public function webhook(Request $request, Response $response, array $params): void
    {
        $secret = (string) $this->config->get('static.build.webhook_secret', '');
        if ($secret === '') {
            $response->error('Webhook not configured', 503);
            return;
        }

        $providedSecret = $request->header('x-webhook-secret');
        if ($providedSecret === null || !hash_equals($secret, $providedSecret)) {
            $response->error('Unauthorized', 401);
            return;
        }

        $body = $request->json();
        $tenantSlug = $body['tenant_slug'] ?? '';

        if ($tenantSlug === '') {
            $response->error('Missing tenant_slug in payload', 400);
            return;
        }

        $tenant = $this->repo->findTenantBySlug($tenantSlug);
        if ($tenant === null) {
            $response->error('Tenant not found', 404);
            return;
        }

        $this->dispatcher->dispatch('default', 'frontend:build', [
            'tenant_slug' => $tenantSlug,
            'triggered_by' => 'webhook',
        ]);

        $response->json([
            'message' => 'Build queued',
            'tenant_slug' => $tenantSlug,
        ], 202);
    }
}
