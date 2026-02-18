<?php

declare(strict_types=1);

/**
 * Application bootstrap — wires the example-chat app into SwooleFabric.
 *
 * Called by the CLI entry point after creating the Application.
 * Registers routes, middleware, WebSocket handlers, and worker-start hooks.
 */

use SwooleFabric\Kernel\Application;
use SwooleFabric\Kernel\Config;
use SwooleFabric\Kernel\Container;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Http\Router;
use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Http\MiddlewareChain;
use SwooleFabric\Http\Middleware\CorrelationMiddleware;
use SwooleFabric\Http\Middleware\AuthMiddleware;
use SwooleFabric\Http\Middleware\TenancyMiddleware;
use SwooleFabric\Security\JwtAuthenticator;
use SwooleFabric\Security\ApiKeyAuthenticator;
use SwooleFabric\Security\PolicyEngine;
use SwooleFabric\Security\RateLimiter;
use SwooleFabric\Storage\DbManager;
use SwooleFabric\Tenancy\TenantContext;
use SwooleFabric\Tenancy\TenantResolver;
use SwooleFabric\Tenancy\TenantConfigCache;
use SwooleFabric\Realtime\Gateway;
use SwooleFabric\Realtime\Presence;
use SwooleFabric\Realtime\PushService;
use SwooleFabric\Realtime\WsAuthHandler;
use SwooleFabric\Realtime\RealtimeSubscriber;
use SwooleFabric\Events\EventBus;
use SwooleFabric\Queue\Dispatcher;
use SwooleFabric\Observability\Logger;
use SwooleFabric\Observability\MetricsCollector;
use SwooleFabric\ExampleChat\ChatRepository;
use SwooleFabric\ExampleChat\ChatRoutes;
use SwooleFabric\ExampleChat\WsHandler;

return function (Application $app): void {
    $config = $app->config();
    $container = $app->container();
    $server = $app->server();

    // ── Core services ────────────────────────────────────────────────

    $jwtSecret = $config->get('auth.jwt.secret', 'swoolefabric-dev-secret-change-me');
    $jwt = new JwtAuthenticator(
        secret: is_string($jwtSecret) ? $jwtSecret : 'swoolefabric-dev-secret-change-me',
        algorithm: 'HS256',
        defaultTtl: (int) $config->get('auth.jwt.ttl', 3600),
    );
    $container->instance(JwtAuthenticator::class, $jwt);

    // ChatRepository (in-memory for demo)
    $chatRepo = new ChatRepository();
    $container->instance(ChatRepository::class, $chatRepo);

    // PolicyEngine with default roles
    $policyEngine = new PolicyEngine();
    $policyEngine->defineRole('admin', ['*:*']);
    $policyEngine->defineRole('member', [
        'rooms:create', 'rooms:read', 'rooms:join',
        'messages:create', 'messages:read',
    ]);
    $policyEngine->defineRole('viewer', ['rooms:read', 'messages:read']);
    $container->instance(PolicyEngine::class, $policyEngine);

    // Tenant resolver (using in-memory repo for demo)
    $tenantCache = new TenantConfigCache(
        ttl: (float) $config->get('tenancy.cache_ttl', 300),
    );
    $container->instance(TenantConfigCache::class, $tenantCache);

    $tenantResolver = new TenantResolver(
        chain: $config->get('tenancy.resolver_chain', ['host', 'header', 'token']),
        lookup: function (string $type, string $value) use ($chatRepo, $tenantCache): ?TenantContext {
            // Check cache first
            $cached = $tenantCache->get("{$type}:{$value}");
            if ($cached !== null) {
                return $cached;
            }

            // Look up from repo
            $row = match ($type) {
                'slug' => $chatRepo->findTenantBySlug($value),
                'id' => null, // Would query by ID in real implementation
                'domain' => null, // Would query by domain
                default => null,
            };

            if ($row === null) {
                return null;
            }

            $tenant = TenantContext::fromArray($row);
            $tenantCache->put($tenant);
            return $tenant;
        },
    );
    $container->instance(TenantResolver::class, $tenantResolver);

    // API key authenticator (using in-memory repo)
    $apiKeyAuth = new ApiKeyAuthenticator(function (string $prefix) use ($chatRepo): ?array {
        return $chatRepo->findApiKeyByPrefix($prefix);
    });
    $container->instance(ApiKeyAuthenticator::class, $apiKeyAuth);

    // Gateway (per-worker, but we register it globally; each worker gets its own state)
    $gateway = new Gateway();
    $container->instance(Gateway::class, $gateway);

    // ── HTTP Router + Middleware ──────────────────────────────────────

    $router = new Router();
    $container->instance(Router::class, $router);

    // Register chat routes (pass null for event bus/dispatcher until worker boot)
    $chatRoutes = new ChatRoutes(
        repo: $chatRepo,
        jwt: $jwt,
    );
    $chatRoutes->register($router);

    // Build middleware chain
    $authMiddleware = new AuthMiddleware($jwt, $apiKeyAuth);
    $authMiddleware->addPublicPath('/api/tenants');
    $authMiddleware->addPublicPath('/api/auth/token');

    $tenancyMiddleware = new TenancyMiddleware($tenantResolver);
    $tenancyMiddleware->addGlobalPath('/api/tenants');

    $middlewareChain = new MiddlewareChain();
    $middlewareChain->add(new CorrelationMiddleware());
    // Auth and Tenancy are optional for some routes — handled inside the middlewares
    $middlewareChain->add($authMiddleware);
    $middlewareChain->add($tenancyMiddleware);

    // Wire the Router into Application's route handler
    $app->addRoute(function (
        \Swoole\Http\Request $swooleRequest,
        \Swoole\Http\Response $swooleResponse,
    ) use ($router, $middlewareChain): bool {
        $method = strtoupper($swooleRequest->server['request_method'] ?? 'GET');
        $uri = $swooleRequest->server['request_uri'] ?? '/';

        $match = $router->match($method, $uri);
        if ($match === null) {
            return false; // Let Application's default 404 handle it
        }

        $request = new Request($swooleRequest);
        $request->setRouteParams($match['params']);
        $response = new Response($swooleResponse);

        // Wrap the route handler with middleware
        $handler = $middlewareChain->wrap(function (Request $req, Response $res) use ($match) {
            ($match['handler'])($req, $res, $match['params']);
        });

        $handler($request, $response);
        return true;
    });

    // ── WebSocket Handlers ───────────────────────────────────────────

    $app->onWorkerStart(function (Container $c) use ($gateway, $jwt, $tenantResolver, $chatRepo, $chatRoutes, $config, $server) {
        /** @var DbManager $db */
        $db = $c->make(DbManager::class);

        $presence = new Presence($db);
        $c->instance(Presence::class, $presence);

        $pushService = new PushService($db);
        $c->instance(PushService::class, $pushService);

        $eventBus = new EventBus($db);
        $c->instance(EventBus::class, $eventBus);

        // Wire EventBus + Dispatcher into ChatRoutes (they need DB pools which are ready now)
        $chatRoutes->setEventBus($eventBus);
        $chatRoutes->setDispatcher(new Dispatcher($db));

        // Wire WS auth handler
        $wsAuth = new WsAuthHandler($jwt, $tenantResolver, $gateway, $presence);

        $server->onWsOpen(function ($wsServer, $request) use ($wsAuth) {
            $wsAuth->onOpen($wsServer, $request);
        });

        $server->onWsClose(function ($wsServer, $fd) use ($wsAuth) {
            $wsAuth->onClose($wsServer, $fd);
        });

        // Wire WS message handler
        $wsHandler = new WsHandler($gateway, $pushService, $chatRepo, $eventBus);

        $server->onWsMessage(function ($wsServer, $frame) use ($wsHandler) {
            $wsHandler->onMessage($wsServer, $frame);
        });

        // Start realtime subscriber for cross-worker push
        $swooleServer = $server->getSwoole();
        $subscriber = new RealtimeSubscriber($gateway, $swooleServer, $db, $config);
        $subscriber->start();
    });
};

