<?php

declare(strict_types=1);

namespace Fabriq\Realtime;

use Swoole\Http\Request as SwooleRequest;
use Swoole\WebSocket\Server as WsServer;
use Fabriq\Kernel\Context;
use Fabriq\Security\JwtAuthenticator;
use Fabriq\Tenancy\TenantResolver;

/**
 * WebSocket authentication handler.
 *
 * Authenticates during the WS upgrade handshake (onOpen).
 * Extracts JWT from query param (?token=xxx) and resolves tenant + actor.
 *
 * On failure: disconnects with 1008 (Policy Violation).
 */
final class WsAuthHandler
{
    public function __construct(
        private readonly JwtAuthenticator $jwt,
        private readonly TenantResolver $tenantResolver,
        private readonly Gateway $gateway,
        private readonly Presence $presence,
    ) {}

    /**
     * Handle WebSocket open event — authenticate and register connection.
     */
    public function onOpen(WsServer $server, SwooleRequest $request): void
    {
        $fd = $request->fd;

        // Extract token from query string
        $token = $request->get['token'] ?? null;

        if ($token === null || $token === '') {
            $server->disconnect($fd, 1008, 'Authentication required: provide ?token=<jwt>');
            return;
        }

        // Decode JWT
        $claims = $this->jwt->decode($token);
        if ($claims === null) {
            $server->disconnect($fd, 1008, 'Invalid or expired token');
            return;
        }

        $actorId = (string) ($claims['sub'] ?? $claims['actor_id'] ?? '');
        $tenantId = (string) ($claims['tenant_id'] ?? '');

        if ($actorId === '' || $tenantId === '') {
            $server->disconnect($fd, 1008, 'Token must contain sub and tenant_id claims');
            return;
        }

        // Set context for this connection
        Context::setTenantId($tenantId);
        Context::setActorId($actorId);

        if (isset($claims['roles'])) {
            Context::setExtra('roles', (array) $claims['roles']);
        }

        // Register in gateway
        $this->gateway->addConnection($fd, $tenantId, $actorId);

        // Track presence
        $this->presence->online($tenantId, $actorId);

        // Send welcome message
        $server->push($fd, json_encode([
            'type' => 'connected',
            'tenant_id' => $tenantId,
            'user_id' => $actorId,
            'fd' => $fd,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Handle WebSocket close event — clean up connection.
     */
    public function onClose(WsServer $server, int $fd): void
    {
        $meta = $this->gateway->getFdMeta($fd);

        if ($meta !== null) {
            $this->presence->offline($meta['tenant_id'], $meta['user_id']);
        }

        $this->gateway->removeConnection($fd);
    }
}

