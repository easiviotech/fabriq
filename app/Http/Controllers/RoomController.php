<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Validator;
use Fabriq\Kernel\Context;
use App\Repositories\ChatRepository;

/**
 * Room controller.
 *
 * Handles chat room CRUD operations (tenant-scoped).
 */
final class RoomController extends Controller
{
    public function __construct(
        private readonly ChatRepository $repo,
    ) {}

    /**
     * POST /api/rooms — Create a room.
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
            'name' => 'required|string|min:1|max:255',
        ]);

        if (!empty($errors)) {
            $response->error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $roomId = $this->generateUuid();
        $this->repo->createRoom([
            'id' => $roomId,
            'tenant_id' => $tenantId,
            'name' => $data['name'],
            'created_by' => Context::actorId() ?? 'system',
        ]);

        $response->json([
            'id' => $roomId,
            'name' => $data['name'],
            'tenant_id' => $tenantId,
        ], 201);
    }

    /**
     * GET /api/rooms — List all rooms for the current tenant.
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $rooms = $this->repo->listRooms($tenantId);

        $response->json([
            'rooms' => $rooms,
            'count' => count($rooms),
        ]);
    }

    /**
     * POST /api/rooms/{roomId}/join — Join a room.
     */
    public function join(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $roomId = $params['roomId'] ?? '';
        $userId = Context::actorId() ?? ($request->json()['user_id'] ?? '');

        if ($roomId === '' || $userId === '') {
            $response->error('roomId and user_id are required', 422);
            return;
        }

        $this->repo->joinRoom($tenantId, $roomId, $userId);

        $response->json([
            'room_id' => $roomId,
            'user_id' => $userId,
            'status' => 'joined',
        ]);
    }
}

