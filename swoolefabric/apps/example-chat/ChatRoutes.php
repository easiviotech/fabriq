<?php

declare(strict_types=1);

namespace SwooleFabric\ExampleChat;

use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Http\Router;
use SwooleFabric\Http\Validator;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Security\ApiKeyAuthenticator;
use SwooleFabric\Security\JwtAuthenticator;
use SwooleFabric\Events\EventBus;
use SwooleFabric\Queue\Dispatcher;

/**
 * Chat application HTTP routes.
 *
 * Registers all REST API endpoints for the example-chat app.
 */
final class ChatRoutes
{
    private ?EventBus $eventBus = null;
    private ?Dispatcher $dispatcher = null;

    public function __construct(
        private readonly ChatRepository $repo,
        private readonly JwtAuthenticator $jwt,
        ?EventBus $eventBus = null,
        ?Dispatcher $dispatcher = null,
    ) {
        $this->eventBus = $eventBus;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Set the EventBus (called from onWorkerStart once DB pools are ready).
     */
    public function setEventBus(EventBus $eventBus): void
    {
        $this->eventBus = $eventBus;
    }

    /**
     * Set the Dispatcher (called from onWorkerStart once DB pools are ready).
     */
    public function setDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Register all routes on a Router.
     */
    public function register(Router $router): void
    {
        // ── Platform routes (no tenant context required) ─────────────
        $router->post('/api/tenants', $this->createTenant(...));

        // ── Tenant-scoped routes ─────────────────────────────────────
        $router->post('/api/users', $this->createUser(...));
        $router->post('/api/auth/token', $this->issueToken(...));
        $router->post('/api/rooms', $this->createRoom(...));
        $router->get('/api/rooms', $this->listRooms(...));
        $router->post('/api/rooms/{roomId}/join', $this->joinRoom(...));
        $router->post('/api/rooms/{roomId}/messages', $this->sendMessage(...));
        $router->get('/api/rooms/{roomId}/messages', $this->listMessages(...));
    }

    // ── Route Handlers ───────────────────────────────────────────────

    private function createTenant(Request $request, Response $response, array $params): void
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

    private function createUser(Request $request, Response $response, array $params): void
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

    private function issueToken(Request $request, Response $response, array $params): void
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

    private function createRoom(Request $request, Response $response, array $params): void
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

    private function listRooms(Request $request, Response $response, array $params): void
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

    private function joinRoom(Request $request, Response $response, array $params): void
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

    private function sendMessage(Request $request, Response $response, array $params): void
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

    private function listMessages(Request $request, Response $response, array $params): void
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

    // ── Helpers ──────────────────────────────────────────────────────

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // v4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

