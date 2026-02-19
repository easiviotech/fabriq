# Fabriq — Developer's Guide

> Build multi-tenant, real-time backend applications on a unified Swoole runtime.

This guide walks you through building a production backend on Fabriq. By the end you will know how to create a project, define routes, add middleware, work with tenancy, push real-time messages, dispatch background jobs, emit events, and observe your application — all within a single long-running PHP process.

---

## Table of Contents

0. [Why Fabriq?](#0-why-fabriq)
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
18. [Live Streaming](#18-live-streaming)
19. [Game Server](#19-game-server)
20. [Testing](#20-testing)
21. [Deployment](#21-deployment)
22. [Full Example — Building a Todo API](#22-full-example--building-a-todo-api)

---

## 0. Why Fabriq?

The [Swoole ecosystem](https://github.com/swoole/awesome-swoole) has many excellent projects — from general-purpose frameworks like Hyperf and Swoft to single-purpose libraries for connection pools, MQTT, and gRPC. Fabriq sits in a unique position: a **unified, multi-tenant backend platform** with a Laravel-familiar developer experience.

### Unified Runtime — Not Just an HTTP Framework

Most Swoole frameworks focus primarily on HTTP. WebSocket, queues, and events are add-on packages you wire together yourself. Fabriq ships **six workloads in a single process** out of the box:

| Workload | How It Works in Fabriq |
|---|---|
| **HTTP API** | Middleware chain → Router → Controllers |
| **WebSocket Gateway** | Same port, JWT auth on upgrade, rooms, presence, cross-worker push via Redis Pub/Sub |
| **Background Jobs** | Redis Streams, consumer groups, retry with backoff, dead-letter queue |
| **Event Bus** | Redis Streams, publish/subscribe, built-in deduplication |
| **Live Streaming** | WebRTC signaling (SDP/ICE), FFmpeg transcoding (RTMP → HLS), viewer tracking, chat moderation |
| **Game Server** | Fixed tick-rate game loop, UDP protocol (MessagePack), Redis ZSET matchmaking, lobby system, delta state sync |

In Hyperf, for example, you combine `hyperf/http-server` + `hyperf/websocket-server` + `hyperf/async-queue` + `hyperf/event` — all from different packages with different paradigms. Fabriq's subsystems are **designed together** and share the same `Context`, `DbManager`, and tenant scoping.

### First-Class Multi-Tenancy at the Kernel Level

This is Fabriq's biggest differentiator. **No Swoole framework in the ecosystem offers built-in multi-tenancy.** In Fabriq, tenancy isn't a plugin — it's woven into every layer:

| Component | What It Does |
|---|---|
| `TenantResolver` | Configurable chain resolution (host subdomain, `X-Tenant` header, JWT claim) |
| `TenantContext` | Immutable value object with plan, status, custom domain, per-tenant config overrides |
| `TenantAwareRepository` | Base class that *fails fast* if `tenant_id` isn't set — you cannot write a query that leaks across tenants |
| `TenantConfigCache` | Per-worker in-memory cache with TTL and LRU eviction |
| Context propagation | When a job or event is dispatched, `tenant_id` is automatically serialized into the message and restored by the consumer |

Even in the Laravel ecosystem, multi-tenancy requires third-party packages (Tenancy for Laravel, Spatie multitenancy). In Fabriq it's kernel-level — every HTTP request, WebSocket connection, queue job, and event consumer carries `tenant_id` through the full lifecycle.

### Coroutine-Safe Context Isolation

Fabriq's `Context` class uses `Swoole\Coroutine::getContext()` to create a per-coroutine state bag. Most Swoole frameworks have request context, but Fabriq extends this to **every execution path** (HTTP, WS messages, queue jobs, event consumers) with automatic `Context::reset()` and context propagation across async boundaries. The correlation ID flows from HTTP → job dispatch → event emit, enabling end-to-end distributed tracing.

### Built-In Idempotency (HTTP, Queues, Events)

The Swoole ecosystem has no idempotency library. Fabriq has a dedicated `IdempotencyStore` with:

- **Redis-first** for speed (SETNX), **DB fallback** for durability
- **`once()` method** — check-execute-store in one call
- Used across HTTP (`Idempotency-Key` header), queue jobs (skip if already processed), and event consumers (dedupe keys with 24h TTL)

This is critical for financial and SaaS applications and isn't offered by any Swoole framework.

### Hybrid RBAC + ABAC Policy Engine

Swoole frameworks typically offer authentication (JWT), but authorization is left to you. Fabriq ships a declarative `PolicyEngine` with RBAC role → permission mappings, ABAC attribute-based conditions, wildcards (`rooms:*`, `*:*`), and full audit logging.

### Connection Pool Safety

Fabriq's `ConnectionPool` is integrated with strict safety rules designed for long-running processes:

- **Per-worker pools** — initialized in `onWorkerStart`, never shared across workers
- **Borrow → use → release** — enforced in same coroutine by `TenantAwareRepository` and `DbManager`
- **Health check on every borrow** — `SELECT 1` (MySQL), `PING` (Redis)
- **Idle timeout eviction** — stale connections automatically replaced
- **No pool-per-tenant** — one shared pool with tenant scoping at query level, preventing connection explosion

### Cross-Worker WebSocket Delivery

Fabriq's realtime subsystem handles a hard problem most frameworks punt on: what happens when user A is on worker 1 and user B is on worker 2?

- `PushService` publishes to Redis Pub/Sub channels (`sf:push:user:{tenantId}:{userId}`, `sf:push:room:{tenantId}:{roomId}`)
- `RealtimeSubscriber` on each worker listens and delivers to local file descriptors via `Gateway`
- `Presence` tracking uses Redis Sets for a global view of who's online
- All of this is **tenant-scoped** — room memberships, presence sets, and push channels are namespaced by tenant

### Laravel-Familiar Developer Experience

Unlike Hyperf (Spring Boot / Java annotations), Swoft (annotation-heavy), or MixPHP (minimal), Fabriq is explicitly designed for **Laravel developers**:

| Concept | Fabriq | Laravel |
|---|---|---|
| Controllers | `app/Http/Controllers/` | `app/Http/Controllers/` |
| Service Providers | `app/Providers/` with `register()` / `boot()` | `app/Providers/` with `register()` / `boot()` |
| Routes | `routes/api.php` | `routes/api.php` |
| Config | `config/*.php` returning arrays | `config/*.php` returning arrays |
| Bootstrap | `bootstrap/app.php` | `bootstrap/app.php` |
| DI Container | `bind()`, `singleton()`, `instance()`, `make()` | `bind()`, `singleton()`, `instance()`, `make()` |
| Middleware | `app/Http/Middleware/` | `app/Http/Middleware/` |

### Feature Comparison Matrix

| Feature | Hyperf | Laravel Octane | MixPHP | **Fabriq** |
|---|---|---|---|---|
| HTTP Server | ✅ | ✅ | ✅ | **✅** |
| WebSocket (same port) | Separate | ❌ | Plugin | **✅ Built-in** |
| Cross-worker WS routing | Manual | N/A | ❌ | **✅ Redis Pub/Sub** |
| Presence tracking | ❌ | ❌ | ❌ | **✅ Redis Sets** |
| Background Jobs | Plugin | Via Laravel | Plugin | **✅ Redis Streams** |
| Event Bus w/ dedup | ❌ | Via Laravel | ❌ | **✅ Built-in** |
| Multi-tenancy | ❌ | Plugin | ❌ | **✅ Kernel-level** |
| Tenant-scoped repos | ❌ | ❌ | ❌ | **✅ TenantAwareRepository** |
| RBAC + ABAC engine | ❌ | Plugin | ❌ | **✅ PolicyEngine** |
| Idempotency | ❌ | ❌ | ❌ | **✅ IdempotencyStore** |
| Coroutine Context | ✅ | Partial | ✅ | **✅ + propagation** |
| Connection pool safety | Basic | Via Swoole | Basic | **✅ Health + idle + tenant** |
| Laravel-style DX | ❌ (Spring-like) | ✅ (is Laravel) | ❌ | **✅** |
| Single unified runtime | Partial | ❌ (FPM bridge) | Partial | **✅** |
| Live Streaming (WebRTC + HLS) | ❌ | ❌ | ❌ | **✅ Built-in** |
| Game Server (tick loop + matchmaking) | ❌ | ❌ | ❌ | **✅ Built-in** |
| UDP Protocol (MessagePack) | ❌ | ❌ | ❌ | **✅ Built-in** |

**In short:** Fabriq is the only Swoole platform that ships multi-tenancy, realtime, queues, events, idempotency, RBAC+ABAC, **live streaming, and a game server engine** as a **unified, coherent system** — with a Laravel-familiar developer experience.

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

### Installation

Fabriq runs on PHP with the Swoole extension. Below are instructions for installing both on common platforms.

#### 1. Install PHP 8.2+

**Ubuntu / Debian:**

```bash
sudo apt update
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install -y php8.3-cli php8.3-dev php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-mysql
```

**macOS (Homebrew):**

```bash
brew install php@8.3
# Ensure it's on your PATH
echo 'export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"' >> ~/.zshrc
source ~/.zshrc
```

**Windows:**

```bash
# Option 1: Download from https://windows.php.net/download
# Extract to C:\php and add to your System PATH

# Option 2: Using Chocolatey
choco install php --version=8.3

# Option 3: Using Scoop
scoop install php
```

Verify:

```bash
php -v
# PHP 8.3.x (cli) ...
```

#### 2. Install the Swoole Extension

Swoole is a high-performance coroutine-based PHP extension that powers Fabriq's async HTTP server, WebSockets, connection pools, and background workers — all in a single long-running process.

**Via PECL (Linux / macOS):**

```bash
# Install build dependencies (Ubuntu/Debian)
sudo apt install -y php8.3-dev gcc make libcurl4-openssl-dev libssl-dev

# Install Swoole
sudo pecl install swoole

# Enable the extension
echo "extension=swoole" | sudo tee /etc/php/8.3/cli/conf.d/20-swoole.ini

# Verify
php -m | grep swoole
php --ri swoole
```

> **PECL Prompts:** During `pecl install swoole` you'll be asked about optional features. Recommended answers for Fabriq:
> - **enable sockets support?** → `yes`
> - **enable openssl support?** → `yes`
> - **enable http2 support?** → `yes`
> - **enable curl support?** → `yes`
> - **enable mysqlnd support?** → `yes`

**Via Docker (Recommended for Production):**

The included `infra/Dockerfile` already handles everything:

```dockerfile
FROM php:8.3-cli

# System dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev libssl-dev unzip git \
    && rm -rf /var/lib/apt/lists/*

# Install Swoole
RUN pecl install swoole-5.1.5 \
    && docker-php-ext-enable swoole

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Install Redis extension
RUN pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
```

**Compiling from Source (Advanced):**

```bash
git clone https://github.com/swoole/swoole-src.git
cd swoole-src
git checkout v5.1.5

phpize
./configure \
    --enable-openssl \
    --enable-http2 \
    --enable-sockets \
    --enable-mysqlnd
make -j$(nproc)
sudo make install

echo "extension=swoole" | sudo tee /etc/php/8.3/cli/conf.d/20-swoole.ini
```

#### 3. Verify Your Environment

```bash
# Check PHP version (must be >= 8.2)
php -v

# Check Swoole is loaded
php -m | grep swoole

# Check Swoole version details
php --ri swoole | head -5

# Quick smoke test — starts and immediately shuts down a Swoole HTTP server
php -r "
\$server = new Swoole\Http\Server('127.0.0.1', 0);
\$server->on('request', function() {});
echo 'Swoole ' . swoole_version() . ' is working!' . PHP_EOL;
"
```

> **Important:** Swoole replaces PHP's traditional request lifecycle. Do **not** install it on a server already running php-fpm for other projects unless you understand the implications. Use Docker or a dedicated VM/container.

#### 4. Install Project Dependencies

Once PHP and Swoole are ready, install Composer and the project:

```bash
# Install Composer (if not already installed)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Fabriq dependencies
cd myapp
composer install
```

### Quick Start (Docker)

```bash
# Clone the project
git clone <repo-url> myapp && cd myapp

# Start MySQL, Redis, and the app container
cd infra
docker compose up -d

# The server is now running on http://localhost:8000
curl http://localhost:8000/health
# → {"status":"ok","service":"Fabriq","timestamp":1740000000,...}
```

### Quick Start (Local)

```bash
composer install

# Start the HTTP + WebSocket server
php bin/fabriq serve

# In another terminal — start the queue worker
php bin/fabriq worker

# In another terminal — start the scheduler (optional)
php bin/fabriq scheduler
```

### CLI Commands

| Command | Description |
|---|---|
| `php bin/fabriq serve [config]` | Start HTTP + WebSocket server |
| `php bin/fabriq worker [config]` | Start queue consumer workers |
| `php bin/fabriq scheduler [config]` | Start the cron-like job scheduler |
| `php bin/fabriq help` | Show help |

Configuration is loaded automatically from the `config/` directory. Each file (e.g. `config/server.php`, `config/database.php`) returns an array and is accessible via dot notation.

---

## 2. Project Structure

```
myapp/
├── app/                           # Your application code
│   ├── Http/
│   │   ├── Controllers/           # HTTP request controllers
│   │   └── Middleware/            # Custom middleware
│   ├── Providers/                 # Service providers (register + boot)
│   ├── Repositories/              # Data access layer
│   ├── Events/                    # Domain event classes
│   ├── Listeners/                 # Event listener handlers
│   ├── Jobs/                      # Queued job classes
│   └── Realtime/                  # WebSocket message handlers
├── bin/
│   └── fabriq                     # CLI entry point
├── bootstrap/
│   └── app.php                    # Application bootstrap
├── config/
│   ├── app.php                    # App name + service providers list
│   ├── server.php                 # Swoole server host, port, workers
│   ├── database.php               # MySQL connection pools
│   ├── redis.php                  # Redis connection settings
│   ├── auth.php                   # JWT + RBAC roles
│   ├── tenancy.php                # Resolver chain + cache TTL
│   ├── queue.php                  # Queue consumer groups + retry
│   ├── events.php                 # Event consumer groups
│   ├── observability.php          # Log level
│   ├── streaming.php              # FFmpeg, HLS, chat moderation
│   └── gaming.php                 # Tick rates, matchmaking, UDP
├── routes/
│   ├── api.php                    # HTTP API route definitions
│   └── channels.php               # WebSocket channel definitions
├── database/
│   └── migrations/                # SQL migration files
├── infra/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   └── mysql/init.sql
├── packages/                      # Framework packages
│   ├── kernel/                    # Application, Server, Config, Container, Context
│   ├── http/                      # Router, Request, Response, Middleware, Validator
│   ├── tenancy/                   # TenantResolver, TenantContext, Cache
│   ├── storage/                   # Connection pools, DbManager, TenantAwareRepository
│   ├── realtime/                  # Gateway, Presence, PushService, Subscriber
│   ├── queue/                     # Dispatcher, Consumer, Scheduler, Idempotency
│   ├── events/                    # EventBus, EventConsumer, EventSchema
│   ├── security/                  # JWT, API Keys, PolicyEngine, RateLimiter
│   ├── observability/             # Logger, MetricsCollector, TraceContext
│   ├── streaming/                 # StreamManager, Signaling, HLS, Transcoding
│   └── gaming/                    # GameLoop, Rooms, Matchmaker, UDP Protocol
├── tests/
├── composer.json
└── phpunit.xml
```

**Key convention:** Your application code lives in `app/` (PSR-4 namespace `App\`). The framework lives in `packages/`. Service providers in `app/Providers/` wire everything together via a register → boot lifecycle.

The `App\` namespace is already registered in `composer.json`:

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    }
}
```

---

## 3. Configuration

Configuration in Fabriq follows a convention familiar to Laravel developers: each concern lives in its own PHP file inside the `config/` directory. At boot time, `Config::fromDirectory()` loads every `.php` file. The filename becomes the top-level key.

| File | Top-Level Key | Description |
|---|---|---|
| `config/app.php` | `app.*` | Application name & service providers list |
| `config/server.php` | `server.*` | Swoole host, port, workers |
| `config/database.php` | `database.*` | MySQL connection pools (platform + app) |
| `config/redis.php` | `redis.*` | Redis connection settings |
| `config/auth.php` | `auth.*` | JWT settings + RBAC role definitions |
| `config/tenancy.php` | `tenancy.*` | Resolver chain + cache TTL |
| `config/queue.php` | `queue.*` | Queue consumer group + retry policy |
| `config/events.php` | `events.*` | Event consumer group |
| `config/observability.php` | `observability.*` | Log level |
| `config/streaming.php` | `streaming.*` | FFmpeg, HLS, chat moderation |
| `config/gaming.php` | `gaming.*` | Tick rates, matchmaking, UDP protocol |

### `config/server.php`

```php
<?php
declare(strict_types=1);

return [
    'host'         => '0.0.0.0',
    'port'         => 8000,
    'workers'      => 2,
    'task_workers' => 2,
    'log_level'    => 4,  // SWOOLE_LOG_WARNING
];
```

### `config/app.php`

```php
<?php
declare(strict_types=1);

return [
    'name' => 'Fabriq',

    'providers' => [
        \App\Providers\AppServiceProvider::class,
        \App\Providers\AuthServiceProvider::class,
        \App\Providers\RouteServiceProvider::class,
        \App\Providers\EventServiceProvider::class,
        \App\Providers\RealtimeServiceProvider::class,
    ],
];
```

### Dot-Notation Access

```php
$host = $config->get('server.host');              // '0.0.0.0'
$poolSize = $config->get('database.app.pool.max_size'); // 20
$missing = $config->get('foo.bar', 'default');    // 'default'
```

Use environment variables via `getenv()` in production config files to keep secrets out of version control.

---

## 4. Bootstrap — Wiring Your Application

The **bootstrap file** (`bootstrap/app.php`) creates the Application instance, registers service providers from `config/app.php`, and boots them. This is the single entry point that wires everything together.

### `bootstrap/app.php`

```php
<?php
declare(strict_types=1);

use Fabriq\Kernel\Application;

// Create the Application (loads all config/*.php files automatically)
$app = new Application(
    basePath: dirname(__DIR__),
);

// Register all providers listed in config/app.php 'providers' array
$app->registerConfiguredProviders();
$app->boot();

return $app;
```

### Writing a Service Provider

Service providers follow the same register → boot lifecycle as Laravel. Place them in `app/Providers/`:

```php
<?php
declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;
use Fabriq\Http\Router;

final class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the Router into the container
        $router = new Router();
        $this->app->container()->instance(Router::class, $router);
    }

    public function boot(): void
    {
        // Load route definitions (all providers have been registered)
        $router = $this->app->container()->make(Router::class);

        $routeFile = $this->app->routesPath('api.php');
        if (is_file($routeFile)) {
            (require $routeFile)($router, $this->app);
        }

        // Wire the middleware chain + router into the server
        $this->wireHttpHandler($router);
    }
}
```

**Lifecycle summary:**

```
bin/fabriq serve
  → bootstrap/app.php
      → Application::__construct()          loads config/, creates Server, Container
      → registerConfiguredProviders()       register() on each provider
      → boot()                              boot() on each provider
  → Application::run()                     starts the Swoole server
      → onWorkerStart                       DB pools boot, per-worker services
      → onRequest                           HTTP → Middleware → Router → Handler
      → onOpen / onMessage / onClose        WebSocket events
```

---

## 5. HTTP Routing

The `Router` supports static and parameterized routes.

### Defining Routes

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Fabriq\Http\Router;
use Fabriq\Http\Request;
use Fabriq\Http\Response;

final class UserController
{
    public static function routes(Router $router): void
    {
        $controller = new self();
        // Static routes
        $router->get('/api/users', $controller->index(...));
        $router->post('/api/users', $controller->store(...));

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

namespace App\Http\Middleware;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

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
use Fabriq\Http\MiddlewareChain;
use App\Http\Middleware\LoggingMiddleware;

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
use Fabriq\Http\Validator;

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

Fabriq enforces tenant isolation at the kernel level. Every HTTP request, WebSocket message, queue job, and event carries a `tenant_id`.

### How Tenant Resolution Works

The `TenantResolver` tries strategies in order until one matches:

| Strategy | How It Works |
|---|---|
| `host` | Extracts subdomain from `Host` header (e.g., `acme.myapp.com` → slug `acme`), or matches custom domain |
| `header` | Reads `X-Tenant: acme` header |
| `token` | Reads `tenant_id` claim from a decoded JWT (requires `AuthMiddleware` to run first) |

Configure the chain in `config/tenancy.php`:

```php
'tenancy' => [
    'resolver_chain' => ['header', 'host', 'token'],
],
```

### TenantContext

Once resolved, the tenant is available everywhere:

```php
use Fabriq\Kernel\Context;

$tenantId = Context::tenantId();   // UUID string

// Full tenant object (stored in extras by TenancyMiddleware)
$tenantData = Context::getExtra('tenant');
// ['id' => '...', 'slug' => 'acme', 'name' => 'Acme Corp', 'plan' => 'pro', ...]
```

### Creating the TenantContext

```php
use Fabriq\Tenancy\TenantContext;

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
use Fabriq\Storage\TenantAwareRepository;

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

Fabriq includes a pure-PHP JWT implementation (HS256/384/512).

**Issuing tokens:**

```php
use Fabriq\Security\JwtAuthenticator;

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
use Fabriq\Security\ApiKeyAuthenticator;

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
use Fabriq\Security\PolicyEngine;

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
use Fabriq\Http\Middleware\PolicyMiddleware;

$policy = new PolicyMiddleware($engine);
$policy->protect('POST', '/api/posts', 'posts', 'create');
$policy->protect('DELETE', '/api/posts/{id}', 'posts', 'delete');

$middlewareChain->add($policy);
```

### Rate Limiting

```php
use Fabriq\Http\Middleware\RateLimitMiddleware;
use Fabriq\Security\RateLimiter;

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

Fabriq uses **Swoole coroutine MySQL/Redis** clients with bounded connection pools.

### Architecture

- **Platform DB** (`sf_platform`): shared tables — tenants, api_keys, roles, idempotency_keys
- **App DB** (`sf_app`): tenant-scoped tables — users, orders, messages (every row has `tenant_id`)
- **Redis**: queues, events, rate limiting, presence, pub/sub, caching

Pools are created per-worker in `onWorkerStart` — never shared across workers.

### Using DbManager

```php
use Fabriq\Storage\DbManager;

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

namespace App\Repositories;

use Fabriq\Storage\TenantAwareRepository;
use Fabriq\Storage\DbManager;

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

Fabriq runs HTTP and WebSocket on the same port, same server.

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

namespace App\Realtime;

use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;
use Fabriq\Kernel\Context;
use Fabriq\Realtime\Gateway;
use Fabriq\Realtime\PushService;

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
use Fabriq\Realtime\PushService;

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
use Fabriq\Realtime\Presence;

$presence->isOnline($tenantId, $userId);   // bool
$presence->getOnlineUsers($tenantId);      // ['user-1', 'user-2']
$presence->onlineCount($tenantId);         // 42
```

---

## 13. Background Jobs & Queues

Jobs use **Redis Streams** with consumer groups, automatic retries, and dead-letter queues.

### Dispatching Jobs

```php
use Fabriq\Queue\Dispatcher;

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

Create `bootstrap/worker.php`:

```php
<?php
declare(strict_types=1);

use Fabriq\Queue\Consumer;
use Fabriq\Storage\DbManager;

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
php bin/fabriq worker
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
use Fabriq\Events\EventBus;

// Simple emit (auto-populates tenant_id, actor_id, correlation_id from Context)
$eventBus->emit('order.created', [
    'order_id' => 'order-123',
    'total'    => 199.99,
], 'dedupe:order-123');   // Optional dedupe key

// Or build a schema explicitly
use Fabriq\Events\EventSchema;

$event = EventSchema::create(
    eventType: 'order.created',
    tenantId: $tenantId,
    payload: ['order_id' => 'order-123'],
    dedupeKey: 'dedupe:order-123',
);
$eventBus->publish($event);
```

### Consuming Events

Create `bootstrap/events.php`:

```php
<?php
declare(strict_types=1);

use Fabriq\Events\EventConsumer;
use Fabriq\Events\EventSchema;
use Fabriq\Storage\DbManager;

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

Create `bootstrap/scheduler.php`:

```php
<?php
declare(strict_types=1);

use Fabriq\Queue\Scheduler;
use Fabriq\Storage\DbManager;

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
php bin/fabriq scheduler
```

The scheduler also promotes **delayed jobs** (dispatched via `dispatchDelayed()`) from the delay ZSET to the target stream when they're due.

---

## 16. Observability

### Structured Logging

```php
use Fabriq\Observability\Logger;

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
use Fabriq\Observability\MetricsCollector;

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
{"status": "ok", "service": "Fabriq", "timestamp": 1740000000, "request_id": "abc123"}
```

---

## 17. Context — The Coroutine-Local Bag

Every execution path (HTTP request, WS message, job, event) gets its own isolated `Context`. This prevents state leakage between concurrent requests.

```php
use Fabriq\Kernel\Context;

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

## 18. Live Streaming

Fabriq includes a built-in live streaming engine that runs inside the same Swoole process. It supports **WebRTC signaling**, **RTMP-to-HLS transcoding** via FFmpeg, **viewer tracking**, and **chat moderation** — all multi-tenant and coroutine-safe.

### Architecture

| Component | Responsibility |
|---|---|
| `SignalingHandler` | WebRTC SDP offer/answer and ICE candidate exchange via WebSocket |
| `StreamManager` | Stream lifecycle: start, stop, metadata, active streams |
| `StreamRepository` | Persistent storage for stream data (tenant-scoped) |
| `TranscodingPipeline` | Manages FFmpeg processes for RTMP/WHIP → HLS conversion |
| `HlsManager` | Serves `.m3u8` manifests and `.ts` segments via HTTP |
| `ViewerTracker` | Tracks concurrent viewers per stream using Redis Sets |
| `ChatModerator` | Slow mode, word filtering, ban lists for stream chat |

### Configuration

```php
<?php
// config/streaming.php
return [
    'enabled' => true,
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'hls' => [
        'segment_duration' => 4,
        'playlist_size' => 5,
        'storage_path' => '/tmp/fabriq-hls',
    ],
    'stream_key_ttl' => 86400,
    'max_concurrent_transcodes' => 4,
    'chat' => [
        'slow_mode_seconds' => 0,
        'max_message_length' => 500,
        'word_filters' => [],
    ],
];
```

### WebRTC Signaling

The `SignalingHandler` exchanges SDP offers/answers and ICE candidates over your existing WebSocket connection:

```php
// Client sends:
{"type": "webrtc_offer", "stream_id": "abc", "sdp": "v=0\r\n..."}

// Server forwards to viewers:
{"type": "webrtc_offer", "stream_id": "abc", "sdp": "...", "from_user_id": "user-1"}

// Viewer responds:
{"type": "webrtc_answer", "stream_id": "abc", "sdp": "v=0\r\n..."}

// ICE candidates exchanged:
{"type": "ice_candidate", "stream_id": "abc", "candidate": {...}, "target_user_id": "user-1"}
```

### HLS Delivery

FFmpeg transcodes incoming streams into HLS segments served directly by Fabriq:

```
GET /streams/{tenantId}/{streamId}/playlist.m3u8  → HLS manifest
GET /streams/{tenantId}/{streamId}/segment_0.ts   → Video segment
```

### Stream Lifecycle

```php
use Fabriq\Streaming\StreamManager;

$streamManager = $container->make(StreamManager::class);

// Start a stream
$stream = $streamManager->startStream($tenantId, $userId, $streamKey, $fd);

// Stop a stream
$streamManager->stopStream($tenantId, $streamId);

// Get active streams for a tenant
$streams = $streamManager->getActiveStreams($tenantId);
```

### Viewer Tracking

```php
use Fabriq\Streaming\ViewerTracker;

$tracker = $container->make(ViewerTracker::class);
$tracker->addViewer($tenantId, $streamId, $userId);
$count = $tracker->getViewerCount($tenantId, $streamId);
$tracker->removeViewer($tenantId, $streamId, $userId);
```

### Chat Moderation

```php
use Fabriq\Streaming\ChatModerator;

$moderator = $container->make(ChatModerator::class);

// Check if a message is allowed
$result = $moderator->canSendMessage($tenantId, $streamId, $userId, $message);
if (!$result['allowed']) {
    // $result['reason'] contains the reason
}

// Ban/unban users
$moderator->banUser($tenantId, $streamId, $userId);
$moderator->unbanUser($tenantId, $streamId, $userId);
```

### Service Provider

Register in `config/app.php`:

```php
'providers' => [
    // ... other providers
    \App\Providers\StreamingServiceProvider::class,
],
```

### Metrics

| Metric | Type | Description |
|---|---|---|
| `streams_active` | gauge | Active live streams |
| `stream_viewers` | gauge | Total concurrent viewers |
| `hls_segments_served` | counter | HLS segments served |
| `transcoding_processes` | gauge | Active FFmpeg processes |

---

## 19. Game Server

Fabriq includes a real-time game server engine that runs inside the same Swoole process. It supports **casual, .io-style, and competitive games** with configurable tick rates, matchmaking, lobbies, and state synchronization.

### Architecture

| Component | Responsibility |
|---|---|
| `GameLoop` | Fixed tick-rate engine using `Swoole\Timer` (10–60 Hz) |
| `GameRoom` | Single room: state, players, tick handler, lifecycle |
| `GameRoomManager` | Create, find, join, destroy rooms; cross-worker via Redis |
| `Matchmaker` | Redis ZSET-based skill matchmaking with O(log N) ranking |
| `PlayerSession` | Connection tracking with reconnection grace period |
| `LobbyManager` | Pre-game lobbies with ready checks and countdown |
| `StateSync` | Delta compression — only send changed state |
| `UdpProtocol` | Binary message encode/decode (MessagePack or JSON) |

### Configuration

```php
<?php
// config/gaming.php
return [
    'enabled' => true,
    'udp_port' => 8001,
    'tick_rates' => [
        'casual' => 10,      // Hz
        'realtime' => 30,    // Hz
        'competitive' => 60, // Hz
    ],
    'max_rooms_per_worker' => 100,
    'max_players_per_room' => 64,
    'matchmaking' => [
        'rating_range' => 100,
        'expand_after_seconds' => 10,
        'max_wait_seconds' => 60,
    ],
    'reconnection_window_seconds' => 30,
    'protocol' => 'msgpack', // 'json' | 'msgpack'
];
```

### Game Loop & Tick Rates

| Type | Tick Rate | Interval | Use Case |
|---|---|---|---|
| `casual` | 10 Hz | 100ms | Card games, trivia, turn-based |
| `realtime` | 30 Hz | ~33ms | .io games, action RPGs |
| `competitive` | 60 Hz | ~16ms | FPS, fighting games |

### Binary Protocol (MessagePack)

```php
use Fabriq\Gaming\UdpProtocol;

$protocol = new UdpProtocol($config);

// Encode game state
$binary = $protocol->encode(['type' => 'state_update', 'positions' => [...]]);

// Decode incoming packet
$data = $protocol->decode($rawData);
```

### Game Rooms

```php
use Fabriq\Gaming\GameRoomManager;

$roomManager = $container->make(GameRoomManager::class);

// Create a room
$room = $roomManager->createRoom($tenantId, 'battle', maxPlayers: 16);

// Join a player
$session = new PlayerSession($userId, $fd, $tenantId);
$roomManager->joinRoom($tenantId, $room->getRoomId(), $session);

// Start the game
$room->startGame();

// Destroy room when done
$roomManager->destroyRoom($tenantId, $room->getRoomId());
```

### Matchmaking

Players queue with a skill rating. The matchmaker uses Redis sorted sets for efficient range queries:

```php
use Fabriq\Gaming\Matchmaker;

$matchmaker = $container->make(Matchmaker::class);

// Queue a player
$matchmaker->queuePlayer($tenantId, $userId, skillRating: 1500, fd: $fd);

// Remove from queue
$matchmaker->dequeuePlayer($tenantId, $userId);

// Check queue size
$size = $matchmaker->getQueueSize($tenantId);
```

The algorithm:
1. Players are added to a Redis ZSET with skill rating as score
2. Background poll scans the ZSET every second
3. Nearby players (± `rating_range`) are matched
4. After `expand_after_seconds`, the range widens
5. After `max_wait_seconds`, the player times out

### Pre-Game Lobbies

```php
use Fabriq\Gaming\LobbyManager;

$lobbyManager = $container->make(LobbyManager::class);

// Create lobby
$lobbyId = $lobbyManager->createLobby($tenantId, 'battle', maxPlayers: 4);

// Join lobby
$lobbyManager->joinLobby($tenantId, $lobbyId, $playerSession);

// Ready up
$lobbyManager->setPlayerReady($tenantId, $lobbyId, $userId, ready: true);

// When all players ready → countdown → game starts automatically
```

### Player Reconnection

Players who disconnect have a grace period (default 30s) to reconnect:

```php
$session->disconnect();
$session->isInReconnectionWindow(30); // true for 30 seconds

// On reconnect
$session->reconnect($newFd);
```

### Service Provider

Register in `config/app.php`:

```php
'providers' => [
    // ... other providers
    \App\Providers\GamingServiceProvider::class,
],
```

### Metrics

| Metric | Type | Description |
|---|---|---|
| `game_rooms_active` | gauge | Active game rooms |
| `game_players_connected` | gauge | Connected game players |
| `game_tick_latency_ms` | histogram | Game loop tick timing |
| `udp_packets_total` | counter | UDP packets processed |
| `matchmaking_queue_size` | gauge | Players waiting for match |

---

## 20. Testing

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

namespace Fabriq\Tests\Unit\App;

use PHPUnit\Framework\TestCase;
use Fabriq\Http\Validator;

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

## 21. Deployment

### Docker Deployment

The included `Dockerfile` builds a production-ready image:

```dockerfile
FROM php:8.3-cli
# Swoole + Redis extensions installed
# Composer dependencies installed
EXPOSE 8000
CMD ["php", "bin/fabriq", "serve"]
```

### Running Multiple Processes

In production, run three process types (e.g., as separate containers or supervisord services):

| Process | Command | Scaling |
|---|---|---|
| **Web** | `php bin/fabriq serve` | Scale horizontally (load balancer) |
| **Worker** | `php bin/fabriq worker` | Scale by queue depth |
| **Scheduler** | `php bin/fabriq scheduler` | Run exactly ONE instance |

### docker-compose.yml (Production)

```yaml
services:
  web:
    image: myapp:latest
    command: ["php", "bin/fabriq", "serve", "config/production.php"]
    ports: ["8000:8000"]
    deploy:
      replicas: 3

  worker:
    image: myapp:latest
    command: ["php", "bin/fabriq", "worker", "config/production.php"]
    deploy:
      replicas: 2

  scheduler:
    image: myapp:latest
    command: ["php", "bin/fabriq", "scheduler", "config/production.php"]
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

## 22. Full Example — Building a Todo API

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

`app/Repositories/TodoRepository.php`:

```php
<?php
declare(strict_types=1);

namespace App\Repositories;

use Fabriq\Storage\TenantAwareRepository;
use Fabriq\Storage\DbManager;

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

`app/Http/Controllers/TodoController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Repositories\TodoRepository;
use Fabriq\Http\Router;
use Fabriq\Http\Request;
use Fabriq\Http\Response;
use Fabriq\Http\Validator;
use Fabriq\Kernel\Context;
use Fabriq\Events\EventBus;

final class TodoController
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

### Step 4: Register Routes

In `routes/api.php`, register the todo controller routes:

```php
<?php
declare(strict_types=1);

use Fabriq\Http\Router;
use App\Http\Controllers\TodoController;

return function (Router $router): void {
    TodoController::routes($router);
};
```

### Step 5: Test It

```bash
# Start the server
php bin/fabriq serve

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
│                    bin/fabriq serve                           │
├──────────────────────────────────────────────────────────────┤
│  Application()           → Load config, create Server        │
│  bootstrap/app.php       → Register & boot ServiceProviders  │
│  Application::run()      → Start Swoole                      │
│    ├─ onWorkerStart      → Boot DB pools, create services    │
│    ├─ onRequest          → Context::reset() → Middleware →   │
│    │                       Router → Handler → Response        │
│    ├─ onOpen             → WS auth → Gateway::addConnection  │
│    ├─ onMessage          → WS handler → PushService           │
│    └─ onClose            → Gateway::removeConnection          │
└──────────────────────────────────────────────────────────────┘

┌────────────────────────────┐  ┌─────────────────────────────┐
│  bin/fabriq worker         │  │  bin/fabriq scheduler        │
├────────────────────────────┤  ├─────────────────────────────┤
│  Boot DB pools             │  │  Boot DB pools               │
│  Load bootstrap/worker.php │  │  Load bootstrap/scheduler.php│
│  Load bootstrap/events.php │  │  Poll delayed ZSET           │
│  Consumer::consume()       │  │  Dispatch recurring jobs     │
│  EventConsumer::consume()  │  │  ∞ loop                      │
└────────────────────────────┘  └─────────────────────────────┘
```

### Bootstrap Files

| File | Receives | Purpose |
|---|---|---|
| `bootstrap/app.php` | `Application $app` | Register & boot ServiceProviders |
| `bootstrap/worker.php` | `Consumer $consumer, DbManager $db` | Register job handlers |
| `bootstrap/events.php` | `EventConsumer $consumer, DbManager $db` | Register event handlers |
| `bootstrap/scheduler.php` | `Scheduler $scheduler, DbManager $db` | Register scheduled jobs |

### Key Classes

| Class | Import | Purpose |
|---|---|---|
| `Application` | `Fabriq\Kernel\Application` | Main bootstrap, holds config/container/server |
| `Config` | `Fabriq\Kernel\Config` | Dot-notation config access |
| `Container` | `Fabriq\Kernel\Container` | DI container (bind, singleton, instance) |
| `Context` | `Fabriq\Kernel\Context` | Coroutine-local state bag |
| `Router` | `Fabriq\Http\Router` | HTTP routing |
| `Request` | `Fabriq\Http\Request` | HTTP request wrapper |
| `Response` | `Fabriq\Http\Response` | HTTP response wrapper |
| `Validator` | `Fabriq\Http\Validator` | Input validation |
| `MiddlewareChain` | `Fabriq\Http\MiddlewareChain` | Middleware pipeline |
| `DbManager` | `Fabriq\Storage\DbManager` | Connection pool manager |
| `TenantAwareRepository` | `Fabriq\Storage\TenantAwareRepository` | Base repo with tenant enforcement |
| `TenantResolver` | `Fabriq\Tenancy\TenantResolver` | Multi-tenant resolution |
| `TenantContext` | `Fabriq\Tenancy\TenantContext` | Tenant value object |
| `JwtAuthenticator` | `Fabriq\Security\JwtAuthenticator` | JWT encode/decode |
| `ApiKeyAuthenticator` | `Fabriq\Security\ApiKeyAuthenticator` | API key auth |
| `PolicyEngine` | `Fabriq\Security\PolicyEngine` | RBAC + ABAC |
| `RateLimiter` | `Fabriq\Security\RateLimiter` | Sliding window rate limiter |
| `EventBus` | `Fabriq\Events\EventBus` | Publish domain events |
| `EventConsumer` | `Fabriq\Events\EventConsumer` | Consume domain events |
| `Dispatcher` | `Fabriq\Queue\Dispatcher` | Dispatch background jobs |
| `Consumer` | `Fabriq\Queue\Consumer` | Process background jobs |
| `Scheduler` | `Fabriq\Queue\Scheduler` | Recurring job scheduler |
| `Gateway` | `Fabriq\Realtime\Gateway` | WS connection management |
| `PushService` | `Fabriq\Realtime\PushService` | Push to WS clients |
| `Presence` | `Fabriq\Realtime\Presence` | Online user tracking |
| `Logger` | `Fabriq\Observability\Logger` | Structured JSON logging |
| `MetricsCollector` | `Fabriq\Observability\MetricsCollector` | Prometheus metrics |
| `StreamManager` | `Fabriq\Streaming\StreamManager` | Live stream lifecycle management |
| `SignalingHandler` | `Fabriq\Streaming\SignalingHandler` | WebRTC SDP/ICE signaling via WebSocket |
| `TranscodingPipeline` | `Fabriq\Streaming\TranscodingPipeline` | FFmpeg RTMP→HLS transcoding |
| `HlsManager` | `Fabriq\Streaming\HlsManager` | HLS manifest and segment serving |
| `ViewerTracker` | `Fabriq\Streaming\ViewerTracker` | Concurrent viewer tracking (Redis Sets) |
| `ChatModerator` | `Fabriq\Streaming\ChatModerator` | Stream chat moderation |
| `GameLoop` | `Fabriq\Gaming\GameLoop` | Fixed tick-rate game engine (Swoole Timer) |
| `GameRoom` | `Fabriq\Gaming\GameRoom` | Single game room state and logic |
| `GameRoomManager` | `Fabriq\Gaming\GameRoomManager` | Room lifecycle and cross-worker sync |
| `Matchmaker` | `Fabriq\Gaming\Matchmaker` | Redis ZSET-based matchmaking |
| `PlayerSession` | `Fabriq\Gaming\PlayerSession` | Player connection and reconnection |
| `LobbyManager` | `Fabriq\Gaming\LobbyManager` | Pre-game lobbies with ready checks |
| `StateSync` | `Fabriq\Gaming\StateSync` | Delta compression state sync |
| `UdpProtocol` | `Fabriq\Gaming\UdpProtocol` | MessagePack/JSON binary protocol |

