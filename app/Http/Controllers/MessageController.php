<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Validator;
use Fabriq\Kernel\Context;
use Fabriq\Events\EventBus;
use App\Repositories\ChatRepository;

/**
 * Message controller.
 *
 * Handles chat message operations (tenant-scoped).
 */
final class MessageController extends Controller
{
    private ?EventBus $eventBus = null;

    public function __construct(
        private readonly ChatRepository $repo,
    ) {}

    /**
     * Set the EventBus (injected after worker boot when DB pools are ready).
     */
    public function setEventBus(EventBus $eventBus): void
    {
        $this->eventBus = $eventBus;
    }

    /**
     * POST /api/rooms/{roomId}/messages — Send a message.
     */
    public function store(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $roomId = $params['roomId'] ?? '';
        $data = $request->json();
        $errors = Validator::validate($data, [
            'body' => 'required|string|min:1',
        ]);

        if (!empty($errors)) {
            $response->error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $messageId = $this->generateUuid();
        $userId = Context::actorId() ?? ($data['user_id'] ?? 'anonymous');

        $message = [
            'id' => $messageId,
            'tenant_id' => $tenantId,
            'room_id' => $roomId,
            'user_id' => $userId,
            'body' => $data['body'],
            'created_at' => date('c'),
        ];

        // Persist message
        $this->repo->createMessage($message);

        // Emit MessageSent event (if event bus is available)
        if ($this->eventBus !== null) {
            $this->eventBus->emit('message.sent', $message, "msg:{$messageId}");
        }

        $response->json($message, 201);
    }

    /**
     * GET /api/rooms/{roomId}/messages — List messages in a room.
     */
    public function index(Request $request, Response $response, array $params): void
    {
        $tenantId = Context::tenantId();
        if ($tenantId === null) {
            $response->error('Tenant context required', 400);
            return;
        }

        $roomId = $params['roomId'] ?? '';
        $messages = $this->repo->listMessages($tenantId, $roomId);

        $response->json([
            'messages' => $messages,
            'count' => count($messages),
            'room_id' => $roomId,
        ]);
    }
}

