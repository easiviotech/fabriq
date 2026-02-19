<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Validator;
use Fabriq\Kernel\Context;
use App\Repositories\ChatRepository;

/**
 * User controller.
 *
 * Handles user management (tenant-scoped).
 */
final class UserController extends Controller
{
    public function __construct(
        private readonly ChatRepository $repo,
    ) {}

    /**
     * POST /api/users — Create a user.
     */
    public function store(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $data = $request->json();
        $errors = Validator::validate($data, [
            'name' => 'required|string|min:1',
            'email' => 'required|email',
        ]);

        if (!empty($errors)) {
            $response->error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $userId = $this->generateUuid();
        $this->repo->createUser([
            'id' => $userId,
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        $response->json([
            'id' => $userId,
            'name' => $data['name'],
            'email' => $data['email'],
            'tenant_id' => $tenantId,
        ], 201);
    }
}

