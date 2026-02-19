<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Kernel\Context;
use Fabriq\Security\JwtAuthenticator;

/**
 * Authentication controller.
 *
 * Handles token issuance and auth-related endpoints.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly JwtAuthenticator $jwt,
    ) {}

    /**
     * POST /api/auth/token — Issue a JWT token.
     */
    public function issueToken(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $data = $request->json();
        $userId = $data['user_id'] ?? '';
        $roles = $data['roles'] ?? ['member'];

        if ($userId === '') {
            $response->error('user_id is required', 422);
            return;
        }

        $token = $this->jwt->encode([
            'sub' => $userId,
            'tenant_id' => $tenantId,
            'roles' => $roles,
        ]);

        $response->json([
            'token' => $token,
            'type' => 'Bearer',
            'expires_in' => 3600,
        ]);
    }
}

