<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Validator;
use Fabriq\Security\ApiKeyAuthenticator;
use App\Repositories\ChatRepository;

/**
 * Tenant management controller.
 *
 * Handles platform-level tenant operations (no tenant context required).
 */
final class TenantController extends Controller
{
    public function __construct(
        private readonly ChatRepository $repo,
    ) {}

    /**
     * POST /api/tenants — Create a new tenant.
     */
    public function store(Request $request, Response $response, array $params): void
    {
        $data = $request->json();
        $errors = Validator::validate($data, [
            'name' => 'required|string|min:2',
            'slug' => 'required|string|min:2|max:64',
        ]);

        if (!empty($errors)) {
            $response->error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        // Check uniqueness
        if ($this->repo->findTenantBySlug($data['slug']) !== null) {
            $response->error('Tenant slug already exists', 409);
            return;
        }

        $tenantId = $this->generateUuid();
        $apiKey = ApiKeyAuthenticator::generateKey();

        $this->repo->createTenant(
            [
                'id' => $tenantId,
                'slug' => $data['slug'],
                'name' => $data['name'],
                'plan' => $data['plan'] ?? 'free',
                'status' => 'active',
            ],
            [
                'prefix' => $apiKey['prefix'],
                'hash' => $apiKey['hash'],
            ],
        );

        $response->json([
            'tenant_id' => $tenantId,
            'slug' => $data['slug'],
            'api_key' => $apiKey['key'],
            'message' => 'Tenant created. Save the API key — it will not be shown again.',
        ], 201);
    }
}

