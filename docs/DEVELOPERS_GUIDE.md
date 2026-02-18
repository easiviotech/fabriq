# SwooleFabric — Developer's Guide

> Build multi-tenant, real-time backend applications on a unified Swoole runtime.

This guide walks you through building a production backend on SwooleFabric. By the end you will know how to create a project, define routes, add middleware, work with tenancy, push real-time messages, dispatch background jobs, emit events, and observe your application — all within a single long-running PHP process.

---

## Table of Contents

1. [Getting Started](#1-getting-started)
2. [Project Structure](#2-project-structure)
3. [Configuration](#3-configuration)
4. [Bootstrap — Wiring Your Application](#4-bootstrap--wiring-your-application)
5. [HTTP Routing](#5-http-routing)
6. [Request & Response](#6-request--response)
7. [Middleware](#7-middleware)
8. [Validation](#8-validation)
9. [Multi-Tenancy](#9-multi-tenancy)
10. [Authentication & Security](#10-authentication--security)
11. [Database & Connection Pools](#11-database--connection-pools)
12. [WebSocket / Real-Time](#12-websocket--real-time)
13. [Background Jobs & Queues](#13-background-jobs--queues)
14. [Event Bus](#14-event-bus)
15. [Scheduled Jobs](#15-scheduled-jobs)
16. [Observability](#16-observability)
17. [Context — The Coroutine-Local Bag](#17-context--the-coroutine-local-bag)
18. [Testing](#18-testing)
19. [Deployment](#19-deployment)
20. [Full Example — Building a Todo API](#20-full-example--building-a-todo-api)

---

## 1. Getting Started

### Prerequisites

| Dependency | Version |
|---|---|
| PHP | ≥ 8.2 |
| Swoole extension | ≥ 5.1 |
| MySQL | 8.0+ |
| Redis | 7.x |
| Docker + Compose (recommended) | v2 |

### Quick Start (Docker)

```bash
# Clone the project
git clone <repo-url> myapp && cd myapp

# Start MySQL, Redis, and the app container
cd infra
docker compose up -d

# The server is now running on http://localhost:8000
curl http://localhost:8000/health
# → {"status":"ok","service":"swoolefabric","timestamp":1740000000,...}
```

### Quick Start (Local)

```bash
composer install

# Start the HTTP + WebSocket server
php bin/swoolefabric serve

# In another terminal — start the queue worker
php bin/swoolefabric worker

# In another terminal — start the scheduler (optional)
php bin/swoolefabric scheduler
```

### CLI Commands

| Command | Description |
|---|---|
| `php bin/swoolefabric serve [config]` | Start HTTP + WebSocket server |
| `php bin/swoolefabric worker [config]` | Start queue consumer workers |
| `php bin/swoolefabric scheduler [config]` | Start the cron-like job scheduler |
| `php bin/swoolefabric help` | Show help |

The optional `[config]` argument defaults to `config/default.php`.

---

## 2. Project Structure

```
myapp/
├── apps/                          # Your application code
│   └── my-api/
│       ├── Routes.php             # HTTP route definitions
│       ├── WsHandler.php          # WebSocket message handler
│       ├── Repository.php         # Data access (tenant-scoped)
│       └── EventHandlers.php      # Event consumer handlers
├── bin/
│   └── swoolefabric               # CLI entry point
├── config/
│   ├── default.php                # Base configuration
│   ├── bootstrap.php              # Application bootstrap (wires everything)
│   ├── worker_bootstrap.php       # Queue job handler registration
│   ├── event_bootstrap.php        # Event handler registration
│   └── scheduler_bootstrap.php    # Scheduled job registration
├── infra/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   └── mysql/init.sql
├── packages/                      # Framework packages (you extend these)
│   ├── kernel/                    # Application, Server, Config, Container, Context
│   ├── http/                      # Router, Request, Response, Middleware, Validator
│   ├── tenancy/                   # TenantResolver, TenantContext, Cache
│   ├── storage/                   # Connection pools, DbManager, TenantAwareRepository
│   ├── realtime/                  # Gateway, Presence, PushService, Subscriber
│   ├── queue/                     # Dispatcher, Consumer, Scheduler, Idempotency
│   ├── events/                    # EventBus, EventConsumer, EventSchema
│   ├── security/                  # JWT, API Keys, PolicyEngine, RateLimiter
│   └── observability/             # Logger, MetricsCollector, TraceContext
├── composer.json
└── phpunit.xml
```

**Key convention:** Your application code lives in `apps/`. The framework lives in `packages/`. The `config/` directory contains bootstrap files that wire them together.

Register your app namespace in `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "MyApp\\": "apps/my-api/"
        }
    }
}
```

---

## 3. Configuration

Configuration is a PHP file that returns an associative array. Values are accessed with dot-notation.

### `config/default.php`

```php
<?php
declare(strict_types=1);

return [
    'server' => [
        'host'         => '0.0.0.0',
        'port'         => 8000,
        'workers'      => 4,        // Swoole worker processes
        'task_workers'  => 2,
        'log_level'    => 4,        // SWOOLE_LOG_WARNING
    ],

    'database' => [
        'platform' => [             // Shared tables: tenants, api_keys, roles
            'host'     => 'mysql',
            'port'     => 3306,
            'database' => 'sf_platform',
            'username' => 'swoolefabric',
            'password' => 'sfpass',
            'charset'  => 'utf8mb4',
            'pool'     => ['max_size' => 20, 'borrow_timeout' => 3.0, 'idle_timeout' => 60.0],
        ],
        'app' => [                  // Tenant-scoped tables: users, orders, etc.
            'host'     => 'mysql',
            'port'     => 3306,
            'database' => 'sf_app',
            'username' => 'swoolefabric',
            'password' => 'sfpass',
            'charset'  => 'utf8mb4',
            'pool'     => ['max_size' => 20, 'borrow_timeout' => 3.0, 'idle_timeout' => 60.0],
        ],
    ],

    'redis' => [
        'host'     => 'redis',
        'port'     => 6379,
        'password' => '',
        'database' => 0,
        'pool'     => ['max_size' => 20],
    ],

    'auth' => [
        'jwt' => [
            'secret'    => 'change-me-in-production',
            'algorithm' => 'HS256',
            'ttl'       => 3600,
        ],
    ],

    'tenancy' => [
        'resolver_chain' => ['host', 'header', 'token'],
        'cache_ttl'      => 300,
    ],

    'queue' => [
        'consumer_group' => 'sf_workers',
        'retry'          => ['max_attempts' => 3, 'backoff' => [1, 5, 30]],
    ],

    'events' => [
        'consumer_group' => 'sf_consumers',
    ],

    'rate_limit' => [
        'default' => ['max_requests' => 100, 'window' => 60],
    ],

    'observability' => [
        'log_level' => 'info',
    ],
];
```

Access in code:

```php
$host = $config->get('server.host');              // '0.0.0.0'
$poolSize = $config->get('database.app.pool.max_size'); // 20
$missing = $config->get('foo.bar', 'default');    // 'default'
```

Create environment-specific overrides by passing a different config file:

```bash
php bin/swoolefabric serve config/production.php
```

---

## 4. Bootstrap — Wiring Your Application

The **bootstrap file** is where you wire your application into SwooleFabric. It is a PHP file that returns a closure receiving the `Application` instance.

### `config/bootstrap.php`

```php
<?php
declare(strict_types=1);

use SwooleFabric\Kernel\Application;
use SwooleFabric\Kernel\Container;
use SwooleFabric\Http\Router;
use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Http\MiddlewareChain;
use SwooleFabric\Http\Middleware\CorrelationMiddleware;
use SwooleFabric\Http\Middleware\AuthMiddleware;
use SwooleFabric\Http\Middleware\TenancyMiddleware;
use SwooleFabric\Security\JwtAuthenticator;
use SwooleFabric\Security\ApiKeyAuthenticator;
use SwooleFabric\Tenancy\TenantResolver;
use SwooleFabric\Tenancy\TenantContext;
use SwooleFabric\Tenancy\TenantConfigCache;
use SwooleFabric\Storage\DbManager;
use SwooleFabric\Realtime\Gateway;
use SwooleFabric\Events\EventBus;
use SwooleFabric\Queue\Dispatcher;
use MyApp\Routes;

return function (Application $app): void {
    $config    = $app->config();
    $container = $app->container();
    $server    = $app->server();

    // ── 1. Create core services ──────────────────────────────────

    $jwt = new JwtAuthenticator(
        secret: $config->get('auth.jwt.secret', 'change-me'),
        defaultTtl: (int) $config->get('auth.jwt.ttl', 3600),
    );
    $container->instance(JwtAuthenticator::class, $jwt);

    // ── 2. Tenant resolution ─────────────────────────────────────

    $tenantCache = new TenantConfigCache(
        ttl: (float) $config->get('tenancy.cache_ttl', 300),
    );

    $tenantResolver = new TenantResolver(
        chain: $config->get('tenancy.resolver_chain', ['header', 'host', 'token']),
        lookup: function (string $type, string $value) use ($tenantCache): ?TenantContext {
            // Check cache
            $cached = $tenantCache->get("{$type}:{$value}");
            if ($cached !== null) return $cached;

            // Query your tenant table here (see §11 for DB access patterns)
            // $row = query tenants table WHERE $type = $value ...
            // if ($row === null) return null;
            // $tenant = TenantContext::fromArray($row);
            // $tenantCache->put($tenant);
            // return $tenant;

            return null;
        },
    );
    $container->instance(TenantResolver::class, $tenantResolver);

    // ── 3. HTTP Router ───────────────────────────────────────────

    $router = new Router();
    $container->instance(Router::class, $router);

    $routes = new Routes();
    $routes->register($router);

    // ── 4. Middleware chain ──────────────────────────────────────

    $apiKeyAuth = new ApiKeyAuthenticator(fn(string $prefix): ?array => null); // wire your lookup
    $authMiddleware = new AuthMiddleware($jwt, $apiKeyAuth);
    $authMiddleware->addPublicPath('/api/auth');   // paths that skip auth

    $tenancyMiddleware = new TenancyMiddleware($tenantResolver);
    $tenancyMiddleware->addGlobalPath('/api/auth'); // paths that skip tenancy

    $middlewareChain = new MiddlewareChain();
    $middlewareChain->add(new CorrelationMiddleware());
    $middlewareChain->add($authMiddleware);
    $middlewareChain->add($tenancyMiddleware);

    // ── 5. Wire router into the server ───────────────────────────

    $app->addRoute(function (
        \Swoole\Http\Request $swooleReq,
        \Swoole\Http\Response $swooleRes,
    ) use ($router, $middlewareChain): bool {
        $method = strtoupper($swooleReq->server['request_method'] ?? 'GET');
        $uri    = $swooleReq->server['request_uri'] ?? '/';

        $match = $router->match($method, $uri);
        if ($match === null) return false;

        $request  = new Request($swooleReq);
        $request->setRouteParams($match['params']);
        $response = new Response($swooleRes);

        $handler = $middlewareChain->wrap(function (Request $req, Response $res) use ($match) {
            ($match['handler'])($req, $res, $match['params']);
        });

        $handler($request, $response);
        return true;
    });

    // ── 6. Worker-start hooks (DB-dependent services) ────────────

    $app->onWorkerStart(function (Container $c) use ($routes, $config, $server) {
        $db = $c->make(DbManager::class);

        $eventBus   = new EventBus($db);
        $dispatcher = new Dispatcher($db);
        $c->instance(EventBus::class, $eventBus);
        $c->instance(Dispatcher::class, $dispatcher);

        // Late-wire services that need DB into your routes
        $routes->setEventBus($eventBus);
        $routes->setDispatcher($dispatcher);
    });
};
```

**Lifecycle summary:**

```
bin/swoolefabric serve
  → Application::__construct()     loads config, creates Server, Container, DbManager
  → require bootstrap.php          your closure runs — registers services, routes, middleware
  → Application::run()             starts the Swoole server
      → onWorkerStart              DB pools boot, your onWorkerStart callbacks run
      → onRequest                  HTTP requests flow through your middleware + routes
      → onOpen / onMessage / onClose   WebSocket events
```

---

## 5. HTTP Routing

The `Router` supports static and parameterized routes.

### Defining Routes

```php
<?php
declare(strict_types=1);

namespace MyApp;

use SwooleFabric\Http\Router;
use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;

final class Routes
{
    public function register(Router $router): void
    {
        // Static routes
        $router->get('/api/users', $this->listUsers(...));
        $router->post('/api/users', $this->createUser(...));

        // Parameterized routes — {name} segments become $params['name']
        $router->get('/api/users/{id}', $this->getUser(...));
        $router->put('/api/users/{id}', $this->updateUser(...));
        $router->delete('/api/users/{id}', $this->deleteUser(...));

        // Nested resources
        $router->get('/api/users/{userId}/orders', $this->listOrders(...));
        $router->post('/api/users/{userId}/orders', $this->createOrder(...));
    }

    private function listUsers(Request $request, Response $response, array $params): void
    {
        $page = (int) ($request->query('page', '1'));
        // ... fetch users ...
        $response->json(['users' => [], 'page' => $page]);
    }

    private function getUser(Request $request, Response $response, array $params): void
    {
        $userId = $params['id'];
        // ... fetch user by $userId ...
        $response->json(['id' => $userId, 'name' => 'Alice']);
    }

    private function createUser(Request $request, Response $response, array $params): void
    {
        $data = $request->json();
        // ... create user ...
        $response->json(['id' => 'new-id', 'name' => $data['name']], 201);
    }
}
```

### Available Methods

```php
$router->get(string $path, callable $handler): void;
$router->post(string $path, callable $handler): void;
$router->put(string $path, callable $handler): void;
$router->patch(string $path, callable $handler): void;
$router->delete(string $path, callable $handler): void;
$router->addRoute(string $method, string $path, callable $handler): void;
```

### Route Handler Signature

Every route handler receives three arguments:

```php
function (Request $request, Response $response, array $params): void
```

| Argument | Description |
|---|---|
| `$request` | Wrapped Swoole request with helpers |
| `$response` | Wrapped Swoole response with `json()`, `error()`, `text()` |
| `$params` | Route parameters extracted from `{name}` segments |

---

## 6. Request & Response

### Request

```php
// HTTP method and path
$request->method();                    // 'GET', 'POST', etc.
$request->uri();                       // '/api/users/123'

// Headers
$request->header('content-type');      // 'application/json'
$request->header('x-custom', 'fallback');
$request->headers();                   // ['content-type' => '...', ...]

// Query parameters  (?page=2&limit=10)
$request->query('page', '1');          // '2'
$request->queryAll();                  // ['page' => '2', 'limit' => '10']

// JSON body
$data = $request->json();             // Parsed array
$name = $request->input('name');       // Single key from JSON body

// Route parameters (from {id} segments)
$request->param('id');                 // '123'
$request->params();                    // ['id' => '123']

// Auth
$request->bearerToken();              // 'eyJ...' or null

// Client info
$request->ip();                        // '192.168.1.1'

// Raw body
$request->rawBody();                   // Raw string
```

### Response

```php
// JSON response (most common)
$response->json(['name' => 'Alice'], 200);

// Error response (shorthand)
$response->error('Not found', 404);
$response->error('Validation failed', 422, ['errors' => $errors]);

// Plain text
$response->text('Hello, World!');

// No content (204)
$response->noContent();

// Set headers + status before sending
$response->status(201);
$response->header('X-Custom', 'value');
$response->json($data);

// Check if already sent (prevents double-send)
if (!$response->isSent()) {
    $response->json($data);
}
```

---

## 7. Middleware

Middleware wraps route handlers in an onion-like chain. Each middleware receives `Request`, `Response`, and a `$next` callable.

### Writing Middleware

```php
<?php
declare(strict_types=1);

namespace MyApp\Middleware;

use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;

final class LoggingMiddleware
{
    public function __invoke(Request $request, Response $response, callable $next): void
    {
        $start = microtime(true);

        // Before the handler runs
        echo "[{$request->method()}] {$request->uri()}\n";

        // Call the next middleware (or the route handler)
        $next();

        // After the handler runs
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        echo "  → {$elapsed}ms\n";
    }
}
```

### Registering Middleware

```php
use SwooleFabric\Http\MiddlewareChain;
use MyApp\Middleware\LoggingMiddleware;

$chain = new MiddlewareChain();
$chain->add(new LoggingMiddleware());      // Runs first (outermost)
$chain->add(new CorrelationMiddleware());  // Runs second
$chain->add($authMiddleware);             // Runs third
$chain->add($tenancyMiddleware);          // Runs fourth (innermost)
```

Middleware execution order:

```
Request → Logging → Correlation → Auth → Tenancy → Route Handler
                                                    ↓
Response ← Logging ← Correlation ← Auth ← Tenancy ← Route Handler
```

### Built-in Middleware

| Middleware | What It Does |
|---|---|
| `CorrelationMiddleware` | Sets `correlation_id` and `request_id` on Context; reuses `X-Correlation-ID` header if present |
| `AuthMiddleware` | Extracts JWT or API key from `Authorization: Bearer <token>`, sets `actor_id` on Context |
| `TenancyMiddleware` | Resolves tenant from request (host, header, or token), sets `tenant_id` on Context |
| `PolicyMiddleware` | Enforces RBAC + ABAC policies for protected routes |
| `RateLimitMiddleware` | Redis-based sliding-window rate limiting, returns `429` when exceeded |

### Skipping Middleware for Specific Paths

```php
$authMiddleware->addPublicPath('/api/auth/login');
$authMiddleware->addPublicPath('/api/auth/register');
$tenancyMiddleware->addGlobalPath('/api/auth/login');
```

---

## 8. Validation

Use the `Validator` for declarative input validation:

```php
use SwooleFabric\Http\Validator;

$data   = $request->json();
$errors = Validator::validate($data, [
    'name'  => 'required|string|min:2|max:255',
    'email' => 'required|email',
    'role'  => 'required|in:admin,member,viewer',
    'age'   => 'int|min:18',
    'id'    => 'uuid',
]);

if (!empty($errors)) {
    $response->error('Validation failed', 422, ['errors' => $errors]);
    return;
}
```

### Supported Rules

| Rule | Description |
|---|---|
| `required` | Must be present and non-empty |
| `string` | Must be a string |
| `int` | Must be an integer |
| `email` | Must be a valid email address |
| `uuid` | Must be a valid UUID |
| `min:N` | Minimum length (string), value (int), or count (array) |
| `max:N` | Maximum length (string), value (int), or count (array) |
| `in:a,b,c` | Must be one of the listed values |

Rules are pipe-delimited. Validation stops at the first error per field.

---

## 9. Multi-Tenancy

SwooleFabric enforces tenant isolation at the kernel level. Every HTTP request, WebSocket message, queue job, and event carries a `tenant_id`.

### How Tenant Resolution Works

The `TenantResolver` tries strategies in order until one matches:

| Strategy | How It Works |
|---|---|
| `host` | Extracts subdomain from `Host` header (e.g., `acme.myapp.com` → slug `acme`), or matches custom domain |
| `header` | Reads `X-Tenant: acme` header |
| `token` | Reads `tenant_id` claim from a decoded JWT (requires `AuthMiddleware` to run first) |

Configure the chain in `config/default.php`:

```php
'tenancy' => [
    'resolver_chain' => ['header', 'host', 'token'],
],
```

### TenantContext

Once resolved, the tenant is available everywhere:

```php
use SwooleFabric\Kernel\Context;

$tenantId = Context::tenantId();   // UUID string

// Full tenant object (stored in extras by TenancyMiddleware)
$tenantData = Context::getExtra('tenant');
// ['id' => '...', 'slug' => 'acme', 'name' => 'Acme Corp', 'plan' => 'pro', ...]
```

### Creating the TenantContext

```php
use SwooleFabric\Tenancy\TenantContext;

// From a database row
$tenant = TenantContext::fromArray([
    'id'     => 'uuid-here',
    'slug'   => 'acme',
    'name'   => 'Acme Corp',
    'plan'   => 'pro',
    'status' => 'active',
    'domain' => 'acme.example.com',
    'config_json' => '{"feature_flags":{"dark_mode":true}}',
]);

$tenant->id;          // 'uuid-here'
$tenant->isActive();  // true
$tenant->configValue('feature_flags.dark_mode'); // true
```

### Tenant-Scoped Database Queries

Always include `tenant_id` in your queries. The `TenantAwareRepository` base class enforces this:

```php
use SwooleFabric\Storage\TenantAwareRepository;

final class OrderRepository extends TenantAwareRepository
{
    public function findAll(): array
    {
        return $this->withAppDb(function ($conn) {
            $tenantId = $this->tenantId(); // throws if missing
            $stmt = $conn->prepare('SELECT * FROM orders WHERE tenant_id = ?');
            return $stmt->execute([$tenantId]);
        });
    }
}
```

---

## 10. Authentication & Security

### JWT Authentication

SwooleFabric includes a pure-PHP JWT implementation (HS256/384/512).

**Issuing tokens:**

```php
use SwooleFabric\Security\JwtAuthenticator;

$jwt = new JwtAuthenticator(secret: 'my-secret', defaultTtl: 3600);

$token = $jwt->encode([
    'sub'       => $userId,
    'tenant_id' => $tenantId,
    'roles'     => ['admin', 'member'],
]);
// Returns: "eyJhbGci..."
```

**Decoding tokens (done automatically by `AuthMiddleware`):**

```php
$claims = $jwt->decode($token);
// ['sub' => '...', 'tenant_id' => '...', 'roles' => [...], 'iat' => ..., 'exp' => ...]
// Returns null if invalid or expired.
```

After `AuthMiddleware` runs, the actor is available:

```php
$actorId = Context::actorId();           // 'user-uuid'
$roles   = Context::getExtra('roles');   // ['admin']
```

### API Key Authentication

API keys use the format `sf_{prefix}_{secret}`:

```php
use SwooleFabric\Security\ApiKeyAuthenticator;

// Generate a new key
$key = ApiKeyAuthenticator::generateKey();
// ['key' => 'sf_a1b2c3d4_...', 'prefix' => 'a1b2c3d4', 'hash' => 'sha256...']
// Store prefix + hash in the api_keys table; give the full key to the user.

// Create authenticator with DB-backed lookup
$apiKeyAuth = ApiKeyAuthenticator::withDb($dbManager);

// Or with a custom lookup function
$apiKeyAuth = new ApiKeyAuthenticator(function (string $prefix) use ($repo): ?array {
    return $repo->findApiKeyByPrefix($prefix);
});
```

### RBAC + ABAC Policy Engine

```php
use SwooleFabric\Security\PolicyEngine;

$engine = new PolicyEngine();

// Define roles with permissions (format: "resource:action")
$engine->defineRole('admin', ['*:*']);                          // full access
$engine->defineRole('editor', ['posts:create', 'posts:read', 'posts:update']);
$engine->defineRole('viewer', ['posts:read']);

// Add ABAC conditions (checked AFTER RBAC allows)
$engine->addCondition('posts', 'delete', function (array $ctx): bool {
    // Only allow deletion during business hours
    $hour = (int) date('G');
    return $hour >= 9 && $hour < 17;
});

// Evaluate
$allowed = $engine->evaluate(
    actorId: 'user-123',
    roles: ['editor'],
    resource: 'posts',
    action: 'create',
    context: ['tenant_id' => '...', 'ip' => '...'],
);
// true
```

### Protecting Routes

```php
use SwooleFabric\Http\Middleware\PolicyMiddleware;

$policy = new PolicyMiddleware($engine);
$policy->protect('POST', '/api/posts', 'posts', 'create');
$policy->protect('DELETE', '/api/posts/{id}', 'posts', 'delete');

$middlewareChain->add($policy);
```

### Rate Limiting

```php
use SwooleFabric\Http\Middleware\RateLimitMiddleware;
use SwooleFabric\Security\RateLimiter;

$limiter = new RateLimiter($dbManager);

$rateLimitMiddleware = new RateLimitMiddleware(
    limiter: $limiter,
    maxRequests: 100,       // 100 requests
    windowSeconds: 60,      // per 60-second window
);

$middlewareChain->add($rateLimitMiddleware);
// Adds X-RateLimit-Limit, X-RateLimit-Remaining, and Retry-After headers automatically.
```

---

## 11. Database & Connection Pools

SwooleFabric uses **Swoole coroutine MySQL/Redis** clients with bounded connection pools.

### Architecture

- **Platform DB** (`sf_platform`): shared tables — tenants, api_keys, roles, idempotency_keys
- **App DB** (`sf_app`): tenant-scoped tables — users, orders, messages (every row has `tenant_id`)
- **Redis**: queues, events, rate limiting, presence, pub/sub, caching

Pools are created per-worker in `onWorkerStart` — never shared across workers.

### Using DbManager

```php
use SwooleFabric\Storage\DbManager;

// Borrow → use → release (always in the same coroutine!)

// Platform DB
$conn = $dbManager->platform();
try {
    $stmt = $conn->prepare('SELECT * FROM tenants WHERE slug = ?');
    $rows = $stmt->execute(['acme']);
} finally {
    $dbManager->releasePlatform($conn);
}

// App DB
$conn = $dbManager->app();
try {
    $stmt = $conn->prepare('SELECT * FROM orders WHERE tenant_id = ? ORDER BY created_at DESC');
    $rows = $stmt->execute([$tenantId]);
} finally {
    $dbManager->releaseApp($conn);
}

// Redis
$redis = $dbManager->redis();
try {
    $redis->set('key', 'value', 300);
    $value = $redis->get('key');
} finally {
    $dbManager->releaseRedis($redis);
}
```

### Transactions

```php
$result = $dbManager->transaction('app', function ($conn) use ($tenantId) {
    $conn->prepare('INSERT INTO orders (id, tenant_id, total) VALUES (?, ?, ?)')
         ->execute(['order-1', $tenantId, 99.99]);

    $conn->prepare('INSERT INTO order_items (order_id, product_id, qty) VALUES (?, ?, ?)')
         ->execute(['order-1', 'prod-1', 2]);

    return ['order_id' => 'order-1'];
});
// Commits on success, rolls back on exception, always releases the connection.
```

### TenantAwareRepository

Extend `TenantAwareRepository` for automatic tenant enforcement:

```php
<?php
declare(strict_types=1);

namespace MyApp;

use SwooleFabric\Storage\TenantAwareRepository;
use SwooleFabric\Storage\DbManager;

final class OrderRepository extends TenantAwareRepository
{
    public function __construct(DbManager $db)
    {
        parent::__construct($db);
    }

    public function findByRoom(string $roomId): array
    {
        return $this->withAppDb(function ($conn) use ($roomId) {
            $tenantId = $this->tenantId(); // Throws if no tenant set
            return $this->execute(
                $conn,
                'SELECT * FROM orders WHERE tenant_id = ? AND room_id = ? ORDER BY created_at DESC',
                [$tenantId, $roomId],
            );
        });
    }

    public function create(array $data): void
    {
        $data['tenant_id'] = $this->tenantId();
        $insert = $this->buildInsert('orders', $data);

        $this->withAppDb(function ($conn) use ($insert) {
            $this->execute($conn, $insert['sql'], $insert['params']);
        });
    }
}
```

### Pool Statistics

```php
$stats = $dbManager->stats();
// [
//   'mysql_platform' => ['current_size' => 5, 'max_size' => 20, 'idle' => 3, 'closed' => false],
//   'mysql_app'      => ['current_size' => 8, 'max_size' => 20, 'idle' => 5, 'closed' => false],
//   'redis'          => ['current_size' => 4, 'max_size' => 20, 'idle' => 2, 'closed' => false],
// ]
```

### Safety Rules

| Rule | Why |
|---|---|
| Always `release()` in a `finally` block | Prevents pool exhaustion |
| Never store a connection in a property | Connection belongs to the coroutine that borrowed it |
| Never share connections across coroutines | Swoole coroutine clients are not thread-safe |
| Use `TenantAwareRepository` for app tables | Prevents cross-tenant data leaks |

---

## 12. WebSocket / Real-Time

SwooleFabric runs HTTP and WebSocket on the same port, same server.

### WebSocket Authentication

Clients connect with a JWT token in the query string:

```
ws://localhost:8000?token=eyJhbGci...
```

The `WsAuthHandler` validates the token, extracts `tenant_id` and `actor_id`, and registers the connection in the `Gateway`.

### Writing a WebSocket Handler

```php
<?php
declare(strict_types=1);

namespace MyApp;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Realtime\Gateway;
use SwooleFabric\Realtime\PushService;

final class MyWsHandler
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly PushService $pushService,
    ) {}

    public function onMessage(WsServer $server, Frame $frame): void
    {
        $fd   = $frame->fd;
        $meta = $this->gateway->getFdMeta($fd);
        if ($meta === null) return;

        $tenantId = $meta['tenant_id'];
        $userId   = $meta['user_id'];

        Context::setTenantId($tenantId);
        Context::setActorId($userId);

        $data = json_decode($frame->data, true);
        $type = $data['type'] ?? '';

        match ($type) {
            'join_room' => $this->joinRoom($server, $fd, $tenantId, $userId, $data),
            'message'   => $this->sendMessage($tenantId, $userId, $data),
            default     => $server->push($fd, json_encode(['error' => "Unknown type: {$type}"])),
        };
    }

    private function joinRoom(WsServer $server, int $fd, string $tenantId, string $userId, array $data): void
    {
        $roomId = $data['room_id'];
        $this->gateway->joinRoom($fd, $tenantId, $roomId);
        $server->push($fd, json_encode(['type' => 'joined', 'room_id' => $roomId]));
    }

    private function sendMessage(string $tenantId, string $userId, array $data): void
    {
        // Push to all members of the room (across ALL workers via Redis Pub/Sub)
        $this->pushService->pushRoom($tenantId, $data['room_id'], [
            'type'    => 'new_message',
            'user_id' => $userId,
            'body'    => $data['body'],
        ]);
    }
}
```

### Wiring WebSocket in Bootstrap

```php
$app->onWorkerStart(function (Container $c) use ($gateway, $jwt, $tenantResolver, $config, $server) {
    $db       = $c->make(DbManager::class);
    $presence = new Presence($db);
    $push     = new PushService($db);

    // Auth handler
    $wsAuth = new WsAuthHandler($jwt, $tenantResolver, $gateway, $presence);
    $server->onWsOpen(fn($s, $req) => $wsAuth->onOpen($s, $req));
    $server->onWsClose(fn($s, $fd) => $wsAuth->onClose($s, $fd));

    // Message handler
    $handler = new MyWsHandler($gateway, $push);
    $server->onWsMessage(fn($s, $frame) => $handler->onMessage($s, $frame));

    // Cross-worker push subscriber
    $subscriber = new RealtimeSubscriber($gateway, $server->getSwoole(), $db, $config);
    $subscriber->start();
});
```

### Push API

Push messages from **any** code path (HTTP handler, job, event consumer):

```php
use SwooleFabric\Realtime\PushService;

// Push to a specific user (all their connections, all workers)
$pushService->pushUser($tenantId, $userId, [
    'type' => 'notification',
    'text' => 'You have a new order!',
]);

// Push to all members of a room
$pushService->pushRoom($tenantId, $roomId, [
    'type' => 'new_message',
    'body' => 'Hello everyone!',
]);

// Broadcast to a topic (all connected clients)
$pushService->pushTopic('system', [
    'type' => 'maintenance',
    'text' => 'Server restarting in 5 minutes',
]);
```

### Presence

```php
use SwooleFabric\Realtime\Presence;

$presence->isOnline($tenantId, $userId);   // bool
$presence->getOnlineUsers($tenantId);      // ['user-1', 'user-2']
$presence->onlineCount($tenantId);         // 42
```

---

## 13. Background Jobs & Queues

Jobs use **Redis Streams** with consumer groups, automatic retries, and dead-letter queues.

### Dispatching Jobs

```php
use SwooleFabric\Queue\Dispatcher;

$dispatcher = new Dispatcher($dbManager);

// Immediate dispatch
$messageId = $dispatcher->dispatch(
    queue: 'default',
    jobType: 'send_email',
    payload: ['to' => 'alice@example.com', 'subject' => 'Welcome!'],
);

// Delayed dispatch (runs after 300 seconds)
$dispatcher->dispatchDelayed(
    queue: 'default',
    jobType: 'send_reminder',
    payload: ['user_id' => 'user-123'],
    delaySeconds: 300,
);

// With idempotency key (prevents duplicate processing)
$dispatcher->dispatch(
    queue: 'default',
    jobType: 'process_payment',
    payload: ['order_id' => 'order-456', 'amount' => 99.99],
    idempotencyKey: 'payment:order-456',
);
```

Context (`tenant_id`, `actor_id`, `correlation_id`) is automatically propagated to the job.

### Handling Jobs

Create `config/worker_bootstrap.php`:

```php
<?php
declare(strict_types=1);

use SwooleFabric\Queue\Consumer;
use SwooleFabric\Storage\DbManager;

return function (Consumer $consumer, DbManager $db): void {

    $consumer->registerHandler('send_email', function (array $payload) {
        $to      = $payload['to'];
        $subject = $payload['subject'];
        // ... send the email ...
        echo "Email sent to {$to}: {$subject}\n";
    });

    $consumer->registerHandler('process_payment', function (array $payload) use ($db) {
        $orderId = $payload['order_id'];
        $amount  = $payload['amount'];
        // ... process payment via DB ...
    });

};
```

Start the worker:

```bash
php bin/swoolefabric worker
```

### Retry & Dead Letter Queue

| Config | Default | Description |
|---|---|---|
| `max_attempts` | 3 | Max retries before DLQ |
| `backoff` | `[1, 5, 30]` | Seconds to wait between retries |

Failed jobs are moved to `sf:dlq:{queueName}` after exhausting retries.

---

## 14. Event Bus

Domain events use **Redis Streams** with consumer group delivery and deduplication.

### Emitting Events

```php
use SwooleFabric\Events\EventBus;

// Simple emit (auto-populates tenant_id, actor_id, correlation_id from Context)
$eventBus->emit('order.created', [
    'order_id' => 'order-123',
    'total'    => 199.99,
], 'dedupe:order-123');   // Optional dedupe key

// Or build a schema explicitly
use SwooleFabric\Events\EventSchema;

$event = EventSchema::create(
    eventType: 'order.created',
    tenantId: $tenantId,
    payload: ['order_id' => 'order-123'],
    dedupeKey: 'dedupe:order-123',
);
$eventBus->publish($event);
```

### Consuming Events

Create `config/event_bootstrap.php`:

```php
<?php
declare(strict_types=1);

use SwooleFabric\Events\EventConsumer;
use SwooleFabric\Events\EventSchema;
use SwooleFabric\Storage\DbManager;

return function (EventConsumer $consumer, DbManager $db): void {

    $consumer->on('order.created', function (EventSchema $event) use ($db) {
        $orderId = $event->payload['order_id'] ?? '';
        $tenantId = $event->tenantId;
        // Update projections, send notifications, etc.
        echo "Order {$orderId} created for tenant {$tenantId}\n";
    });

    $consumer->on('message.sent', function (EventSchema $event) {
        // Update message counters, search index, etc.
    });

};
```

Events are consumed by the `worker` process (runs alongside queue consumption).

### Deduplication

Each event carries a `dedupeKey`. The `EventConsumer` tracks processed keys in a Redis SET and skips duplicates automatically. TTL is 24 hours.

---

## 15. Scheduled Jobs

The scheduler dispatches recurring jobs on a configurable interval.

### Defining Schedules

Create `config/scheduler_bootstrap.php`:

```php
<?php
declare(strict_types=1);

use SwooleFabric\Queue\Scheduler;
use SwooleFabric\Storage\DbManager;

return function (Scheduler $scheduler, DbManager $db): void {

    // Run every 60 seconds
    $scheduler->every(
        queue: 'default',
        jobType: 'cleanup_expired_tokens',
        payload: [],
        intervalSeconds: 60,
    );

    // Run every 5 minutes
    $scheduler->every(
        queue: 'default',
        jobType: 'sync_external_api',
        payload: ['source' => 'stripe'],
        intervalSeconds: 300,
    );

    // Run every hour
    $scheduler->every(
        queue: 'default',
        jobType: 'generate_daily_report',
        payload: [],
        intervalSeconds: 3600,
    );

};
```

Start the scheduler:

```bash
php bin/swoolefabric scheduler
```

The scheduler also promotes **delayed jobs** (dispatched via `dispatchDelayed()`) from the delay ZSET to the target stream when they're due.

---

## 16. Observability

### Structured Logging

```php
use SwooleFabric\Observability\Logger;

$logger = new Logger('info'); // min level: debug, info, warning, error

$logger->info('Order created', ['order_id' => 'ord-123', 'total' => 99.99]);
$logger->warning('Rate limit approaching', ['remaining' => 5]);
$logger->error('Payment failed', ['provider' => 'stripe', 'code' => 'card_declined']);
$logger->exception($e, 'Unexpected error during checkout');
```

Output (STDERR, one JSON per line):

```json
{"timestamp":"2026-02-17T12:00:00+00:00","level":"info","message":"Order created","tenant_id":"acme-uuid","correlation_id":"abc123","actor_id":"user-1","request_id":"def456","extra":{"order_id":"ord-123","total":99.99}}
```

Context fields (`tenant_id`, `correlation_id`, `actor_id`, `request_id`) are automatically included from the current coroutine's `Context`.

### Prometheus Metrics

Metrics are exposed at `GET /metrics` in Prometheus text format.

```php
use SwooleFabric\Observability\MetricsCollector;

$metrics = $container->make(MetricsCollector::class);

// Counters (only go up)
$metrics->registerCounter('orders_total', 'Total orders created');
$metrics->increment('orders_total');

// Gauges (go up and down)
$metrics->registerGauge('active_connections', 'Active WS connections');
$metrics->set('active_connections', 42.0);
$metrics->add('active_connections', 1.0);

// Histograms (distributions)
$metrics->registerHistogram('query_duration_ms', 'DB query duration');
$metrics->observe('query_duration_ms', 12.5);
```

Built-in metrics (registered automatically):

| Metric | Type | Description |
|---|---|---|
| `http_requests_total` | counter | Total HTTP requests |
| `http_latency_ms` | histogram | Request latency |
| `ws_connections` | gauge | Active WebSocket connections |
| `queue_processed_total` | counter | Jobs processed |
| `db_pool_in_use` | gauge | DB connections in use |

### Health Check

`GET /health` returns:

```json
{"status": "ok", "service": "swoolefabric", "timestamp": 1740000000, "request_id": "abc123"}
```

---

## 17. Context — The Coroutine-Local Bag

Every execution path (HTTP request, WS message, job, event) gets its own isolated `Context`. This prevents state leakage between concurrent requests.

```php
use SwooleFabric\Kernel\Context;

// Read (available after middleware runs)
Context::tenantId();        // 'tenant-uuid'
Context::actorId();         // 'user-uuid'
Context::correlationId();   // 'corr-abc123'
Context::requestId();       // 'req-def456'

// Write (typically done by middleware, not app code)
Context::setTenantId('tenant-uuid');
Context::setActorId('user-uuid');
Context::setCorrelationId('corr-abc123');

// Extras (arbitrary key-value)
Context::setExtra('roles', ['admin']);
Context::getExtra('roles', []);     // ['admin']

// All context (for logging)
Context::all();
// ['tenant_id' => '...', 'actor_id' => '...', 'correlation_id' => '...', ...]
```

**Important:** `Context::reset()` is called automatically at the start of every HTTP request, WebSocket event, and job. You do **not** need to call it manually.

---

## 18. Testing

### Running Tests

```bash
# All tests
vendor/bin/phpunit

# Specific test file
vendor/bin/phpunit tests/Unit/Http/RouterTest.php

# With Docker
docker compose exec app vendor/bin/phpunit
```

### Writing Unit Tests

```php
<?php
declare(strict_types=1);

namespace SwooleFabric\Tests\Unit\MyApp;

use PHPUnit\Framework\TestCase;
use SwooleFabric\Http\Validator;

final class ValidationTest extends TestCase
{
    public function test_required_fields(): void
    {
        $errors = Validator::validate([], [
            'name' => 'required|string',
            'email' => 'required|email',
        ]);

        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('email', $errors);
    }

    public function test_valid_input_passes(): void
    {
        $errors = Validator::validate(
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'required|string', 'email' => 'required|email'],
        );

        $this->assertEmpty($errors);
    }
}
```

### Testing Patterns

| What to Test | How |
|---|---|
| Validators, PolicyEngine, Config | Pure unit tests (no Swoole needed) |
| Router matching | Instantiate `Router`, call `match()`, assert results |
| Repositories | Mock `DbManager`, verify SQL + params |
| Middleware | Create mock Request/Response, call middleware, assert Context |
| Integration | Use Docker Compose to start full stack, use `curl`/HTTP client |

---

## 19. Deployment

### Docker Deployment

The included `Dockerfile` builds a production-ready image:

```dockerfile
FROM php:8.3-cli
# Swoole + Redis extensions installed
# Composer dependencies installed
EXPOSE 8000
CMD ["php", "bin/swoolefabric", "serve"]
```

### Running Multiple Processes

In production, run three process types (e.g., as separate containers or supervisord services):

| Process | Command | Scaling |
|---|---|---|
| **Web** | `php bin/swoolefabric serve` | Scale horizontally (load balancer) |
| **Worker** | `php bin/swoolefabric worker` | Scale by queue depth |
| **Scheduler** | `php bin/swoolefabric scheduler` | Run exactly ONE instance |

### docker-compose.yml (Production)

```yaml
services:
  web:
    image: myapp:latest
    command: ["php", "bin/swoolefabric", "serve", "config/production.php"]
    ports: ["8000:8000"]
    deploy:
      replicas: 3

  worker:
    image: myapp:latest
    command: ["php", "bin/swoolefabric", "worker", "config/production.php"]
    deploy:
      replicas: 2

  scheduler:
    image: myapp:latest
    command: ["php", "bin/swoolefabric", "scheduler", "config/production.php"]
    deploy:
      replicas: 1  # MUST be exactly 1
```

### Environment-Specific Config

Create `config/production.php`:

```php
<?php
return [
    'server' => [
        'host'    => '0.0.0.0',
        'port'    => 8000,
        'workers' => (int) (getenv('SWOOLE_WORKERS') ?: 8),
    ],
    'database' => [
        'platform' => [
            'host'     => getenv('DB_HOST') ?: 'mysql',
            'password' => getenv('DB_PASSWORD') ?: '',
            // ...
        ],
    ],
    'auth' => [
        'jwt' => ['secret' => getenv('JWT_SECRET') ?: ''],
    ],
];
```

---

## 20. Full Example — Building a Todo API

Let's build a complete tenant-scoped Todo API from scratch.

### Step 1: Database Migration

Add to `infra/mysql/init.sql`:

```sql
USE sf_app;

CREATE TABLE IF NOT EXISTS todos (
    id          CHAR(36) NOT NULL PRIMARY KEY,
    tenant_id   CHAR(36) NOT NULL,
    user_id     CHAR(36) NOT NULL,
    title       VARCHAR(255) NOT NULL,
    completed   TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_todos_tenant (tenant_id),
    INDEX idx_todos_user (tenant_id, user_id)
) ENGINE=InnoDB;
```

### Step 2: Repository

`apps/todo-api/TodoRepository.php`:

```php
<?php
declare(strict_types=1);

namespace MyApp\Todo;

use SwooleFabric\Storage\TenantAwareRepository;
use SwooleFabric\Storage\DbManager;

final class TodoRepository extends TenantAwareRepository
{
    public function __construct(DbManager $db)
    {
        parent::__construct($db);
    }

    public function listForUser(string $userId): array
    {
        return $this->withAppDb(function ($conn) use ($userId) {
            return $this->execute(
                $conn,
                'SELECT * FROM todos WHERE tenant_id = ? AND user_id = ? ORDER BY created_at DESC',
                [$this->tenantId(), $userId],
            );
        });
    }

    public function findById(string $id): ?array
    {
        return $this->withAppDb(function ($conn) use ($id) {
            $rows = $this->execute(
                $conn,
                'SELECT * FROM todos WHERE id = ? AND tenant_id = ?',
                [$id, $this->tenantId()],
            );
            return is_array($rows) && !empty($rows) ? $rows[0] : null;
        });
    }

    public function create(string $id, string $userId, string $title): void
    {
        $this->withAppDb(function ($conn) use ($id, $userId, $title) {
            $this->execute(
                $conn,
                'INSERT INTO todos (id, tenant_id, user_id, title) VALUES (?, ?, ?, ?)',
                [$id, $this->tenantId(), $userId, $title],
            );
        });
    }

    public function toggleComplete(string $id): void
    {
        $this->withAppDb(function ($conn) use ($id) {
            $this->execute(
                $conn,
                'UPDATE todos SET completed = NOT completed, updated_at = NOW() WHERE id = ? AND tenant_id = ?',
                [$id, $this->tenantId()],
            );
        });
    }

    public function delete(string $id): void
    {
        $this->withAppDb(function ($conn) use ($id) {
            $this->execute(
                $conn,
                'DELETE FROM todos WHERE id = ? AND tenant_id = ?',
                [$id, $this->tenantId()],
            );
        });
    }
}
```

### Step 3: Routes

`apps/todo-api/TodoRoutes.php`:

```php
<?php
declare(strict_types=1);

namespace MyApp\Todo;

use SwooleFabric\Http\Router;
use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Http\Validator;
use SwooleFabric\Kernel\Context;
use SwooleFabric\Events\EventBus;

final class TodoRoutes
{
    private ?TodoRepository $repo = null;
    private ?EventBus $eventBus = null;

    public function register(Router $router): void
    {
        $router->get('/api/todos', $this->list(...));
        $router->post('/api/todos', $this->create(...));
        $router->patch('/api/todos/{id}/toggle', $this->toggle(...));
        $router->delete('/api/todos/{id}', $this->delete(...));
    }

    public function setRepository(TodoRepository $repo): void
    {
        $this->repo = $repo;
    }

    public function setEventBus(EventBus $eventBus): void
    {
        $this->eventBus = $eventBus;
    }

    private function list(Request $request, Response $response, array $params): void
    {
        $userId = Context::actorId() ?? '';
        $todos  = $this->repo->listForUser($userId);
        $response->json(['todos' => $todos, 'count' => count($todos)]);
    }

    private function create(Request $request, Response $response, array $params): void
    {
        $data   = $request->json();
        $errors = Validator::validate($data, ['title' => 'required|string|min:1|max:255']);

        if (!empty($errors)) {
            $response->error('Validation failed', 422, ['errors' => $errors]);
            return;
        }

        $id     = $this->uuid();
        $userId = Context::actorId() ?? 'anonymous';

        $this->repo->create($id, $userId, $data['title']);

        $todo = ['id' => $id, 'title' => $data['title'], 'completed' => false];

        $this->eventBus?->emit('todo.created', $todo, "todo:{$id}");

        $response->json($todo, 201);
    }

    private function toggle(Request $request, Response $response, array $params): void
    {
        $id = $params['id'];
        $this->repo->toggleComplete($id);

        $todo = $this->repo->findById($id);
        $response->json($todo ?? ['id' => $id, 'toggled' => true]);
    }

    private function delete(Request $request, Response $response, array $params): void
    {
        $this->repo->delete($params['id']);
        $response->noContent();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### Step 4: Bootstrap

`config/bootstrap.php`:

```php
<?php
declare(strict_types=1);

use SwooleFabric\Kernel\Application;
use SwooleFabric\Kernel\Container;
use SwooleFabric\Http\Router;
use SwooleFabric\Http\Request;
use SwooleFabric\Http\Response;
use SwooleFabric\Http\MiddlewareChain;
use SwooleFabric\Http\Middleware\CorrelationMiddleware;
use SwooleFabric\Http\Middleware\AuthMiddleware;
use SwooleFabric\Http\Middleware\TenancyMiddleware;
use SwooleFabric\Security\JwtAuthenticator;
use SwooleFabric\Security\ApiKeyAuthenticator;
use SwooleFabric\Tenancy\TenantResolver;
use SwooleFabric\Storage\DbManager;
use SwooleFabric\Events\EventBus;
use MyApp\Todo\TodoRoutes;
use MyApp\Todo\TodoRepository;

return function (Application $app): void {
    $config    = $app->config();
    $container = $app->container();

    // JWT
    $jwt = new JwtAuthenticator(
        secret: $config->get('auth.jwt.secret', 'change-me'),
    );
    $container->instance(JwtAuthenticator::class, $jwt);

    // Router + Routes
    $router    = new Router();
    $todoRoutes = new TodoRoutes();
    $todoRoutes->register($router);

    // Middleware
    $apiKeyAuth = new ApiKeyAuthenticator(fn() => null);
    $auth       = new AuthMiddleware($jwt, $apiKeyAuth);
    $tenancy    = new TenancyMiddleware(new TenantResolver(
        chain: ['header'],
        lookup: fn() => null, // Wire your tenant lookup
    ));

    $chain = new MiddlewareChain();
    $chain->add(new CorrelationMiddleware());
    $chain->add($auth);
    $chain->add($tenancy);

    // Wire router
    $app->addRoute(function (\Swoole\Http\Request $req, \Swoole\Http\Response $res) use ($router, $chain): bool {
        $match = $router->match(
            strtoupper($req->server['request_method'] ?? 'GET'),
            $req->server['request_uri'] ?? '/',
        );
        if ($match === null) return false;

        $request  = new Request($req);
        $request->setRouteParams($match['params']);
        $response = new Response($res);

        $handler = $chain->wrap(fn(Request $r, Response $s) => ($match['handler'])($r, $s, $match['params']));
        $handler($request, $response);
        return true;
    });

    // Worker boot — wire DB-dependent services
    $app->onWorkerStart(function (Container $c) use ($todoRoutes) {
        $db = $c->make(DbManager::class);
        $todoRoutes->setRepository(new TodoRepository($db));
        $todoRoutes->setEventBus(new EventBus($db));
    });
};
```

### Step 5: Test It

```bash
# Start the server
php bin/swoolefabric serve

# Health check
curl http://localhost:8000/health

# Create a todo (with auth + tenant headers)
curl -X POST http://localhost:8000/api/todos \
  -H "Authorization: Bearer <your-jwt>" \
  -H "X-Tenant: acme" \
  -H "Content-Type: application/json" \
  -d '{"title": "Write documentation"}'

# List todos
curl http://localhost:8000/api/todos \
  -H "Authorization: Bearer <your-jwt>" \
  -H "X-Tenant: acme"

# Toggle completion
curl -X PATCH http://localhost:8000/api/todos/<id>/toggle \
  -H "Authorization: Bearer <your-jwt>" \
  -H "X-Tenant: acme"

# Delete
curl -X DELETE http://localhost:8000/api/todos/<id> \
  -H "Authorization: Bearer <your-jwt>" \
  -H "X-Tenant: acme"
```

---

## Quick Reference

### Lifecycle Diagram

```
┌──────────────────────────────────────────────────────────────┐
│                    bin/swoolefabric serve                     │
├──────────────────────────────────────────────────────────────┤
│  Application()           → Load config, create Server        │
│  bootstrap.php           → Register routes, middleware, DI    │
│  Application::run()      → Start Swoole                      │
│    ├─ onWorkerStart      → Boot DB pools, create services    │
│    ├─ onRequest          → Context::reset() → Middleware →   │
│    │                       Router → Handler → Response        │
│    ├─ onOpen             → WS auth → Gateway::addConnection  │
│    ├─ onMessage          → WS handler → PushService           │
│    └─ onClose            → Gateway::removeConnection          │
└──────────────────────────────────────────────────────────────┘

┌────────────────────────────┐  ┌─────────────────────────────┐
│  bin/swoolefabric worker   │  │  bin/swoolefabric scheduler  │
├────────────────────────────┤  ├─────────────────────────────┤
│  Boot DB pools             │  │  Boot DB pools               │
│  Load worker_bootstrap.php │  │  Load scheduler_bootstrap    │
│  Load event_bootstrap.php  │  │  Poll delayed ZSET           │
│  Consumer::consume()       │  │  Dispatch recurring jobs     │
│  EventConsumer::consume()  │  │  ∞ loop                      │
└────────────────────────────┘  └─────────────────────────────┘
```

### Bootstrap Files

| File | Receives | Purpose |
|---|---|---|
| `config/bootstrap.php` | `Application $app` | Wire routes, middleware, services |
| `config/worker_bootstrap.php` | `Consumer $consumer, DbManager $db` | Register job handlers |
| `config/event_bootstrap.php` | `EventConsumer $consumer, DbManager $db` | Register event handlers |
| `config/scheduler_bootstrap.php` | `Scheduler $scheduler, DbManager $db` | Register scheduled jobs |

### Key Classes

| Class | Import | Purpose |
|---|---|---|
| `Application` | `SwooleFabric\Kernel\Application` | Main bootstrap, holds config/container/server |
| `Config` | `SwooleFabric\Kernel\Config` | Dot-notation config access |
| `Container` | `SwooleFabric\Kernel\Container` | DI container (bind, singleton, instance) |
| `Context` | `SwooleFabric\Kernel\Context` | Coroutine-local state bag |
| `Router` | `SwooleFabric\Http\Router` | HTTP routing |
| `Request` | `SwooleFabric\Http\Request` | HTTP request wrapper |
| `Response` | `SwooleFabric\Http\Response` | HTTP response wrapper |
| `Validator` | `SwooleFabric\Http\Validator` | Input validation |
| `MiddlewareChain` | `SwooleFabric\Http\MiddlewareChain` | Middleware pipeline |
| `DbManager` | `SwooleFabric\Storage\DbManager` | Connection pool manager |
| `TenantAwareRepository` | `SwooleFabric\Storage\TenantAwareRepository` | Base repo with tenant enforcement |
| `TenantResolver` | `SwooleFabric\Tenancy\TenantResolver` | Multi-tenant resolution |
| `TenantContext` | `SwooleFabric\Tenancy\TenantContext` | Tenant value object |
| `JwtAuthenticator` | `SwooleFabric\Security\JwtAuthenticator` | JWT encode/decode |
| `ApiKeyAuthenticator` | `SwooleFabric\Security\ApiKeyAuthenticator` | API key auth |
| `PolicyEngine` | `SwooleFabric\Security\PolicyEngine` | RBAC + ABAC |
| `RateLimiter` | `SwooleFabric\Security\RateLimiter` | Sliding window rate limiter |
| `EventBus` | `SwooleFabric\Events\EventBus` | Publish domain events |
| `EventConsumer` | `SwooleFabric\Events\EventConsumer` | Consume domain events |
| `Dispatcher` | `SwooleFabric\Queue\Dispatcher` | Dispatch background jobs |
| `Consumer` | `SwooleFabric\Queue\Consumer` | Process background jobs |
| `Scheduler` | `SwooleFabric\Queue\Scheduler` | Recurring job scheduler |
| `Gateway` | `SwooleFabric\Realtime\Gateway` | WS connection management |
| `PushService` | `SwooleFabric\Realtime\PushService` | Push to WS clients |
| `Presence` | `SwooleFabric\Realtime\Presence` | Online user tracking |
| `Logger` | `SwooleFabric\Observability\Logger` | Structured JSON logging |
| `MetricsCollector` | `SwooleFabric\Observability\MetricsCollector` | Prometheus metrics |

