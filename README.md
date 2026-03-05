# Fabriq

> **Unified Swoole-powered application platform** — serves HTTP APIs, per-tenant frontend builds, WebSocket realtime, background jobs, and event bus with first-class multi-tenancy. Built-in CI/CD deploys any frontend framework (React, Vue, Svelte, Angular, etc.) from Git. Optional add-on packages for live streaming and game server.

**Brand:** Fabrq • **Runtime:** PHP 8.2+ / Swoole • **License:** Proprietary

---

## Architecture

Fabriq is a **long-running Swoole server** that hosts HTTP, frontend static files, WebSocket, queue processors, and event consumers in a single process. Every execution path enforces `TenantContext` — tenant isolation is guaranteed at the kernel level, including per-tenant frontend builds.

The platform serves both your **API** and your **frontend** from a single port — no Nginx or Apache required. Each tenant can have its own frontend build (React, Vue, Svelte, Angular, or any framework), cloned from Git and built automatically via CLI, API, or webhook.

Optional add-on packages extend the platform with **live streaming** (`fabriq/streaming`) and a **real-time game server** (`fabriq/gaming`) — install only what you need.

```
Client → HTTP/WS/UDP → Middleware Chain → Route Handler → DB/Redis (tenant-scoped)
                                        → Static File Handler → Per-tenant frontend (Swoole sendfile)
                                        → Push to WS rooms (Redis Pub/Sub)
                                        → Dispatch job (Redis Streams)
                                        → Emit event (Redis Streams)
                                        → Frontend Builder (git clone → npm build → deploy)
                                        → WebRTC signaling / HLS serving (fabriq/streaming)
                                        → Game room tick / state sync (fabriq/gaming)
```

### Core Packages

These packages are always active and form the foundation of every Fabriq application:

| Package | Path | Description |
|---------|------|-------------|
| **Kernel** | `packages/kernel/` | Swoole server bootstrap, Context (coroutine-local), Config, Container, Application, ServiceProvider |
| **Tenancy** | `packages/tenancy/` | TenantResolver, TenantContext, TenantConfigCache |
| **Storage** | `packages/storage/` | Connection pools (Channel-based), DbManager, TenantAwareRepository |
| **HTTP** | `packages/http/` | Router, Request/Response wrappers, MiddlewareChain, Validator, StaticFileMiddleware, FrontendBuilder (CI/CD) |
| **Realtime** | `packages/realtime/` | Gateway (fd mapping), Presence, PushService, RealtimeSubscriber |
| **Queue** | `packages/queue/` | Dispatcher, Consumer (retry/DLQ), Scheduler, IdempotencyStore |
| **Events** | `packages/events/` | EventBus, EventConsumer (dedupe), EventSchema |
| **Security** | `packages/security/` | JwtAuthenticator, ApiKeyAuthenticator, PolicyEngine (RBAC+ABAC), RateLimiter |
| **Observability** | `packages/observability/` | Logger (structured JSON), MetricsCollector (Prometheus), TraceContext |
| **ORM** | `packages/orm/` | Active Record models, fluent QueryBuilder, stored procedures, schema migrations, tenant DB routing |

### Optional Add-on Packages

These packages are **disabled by default** and installed separately. Enable them only if your application needs streaming or gaming capabilities:

| Package | Install | Description |
|---------|---------|-------------|
| **Streaming** | `composer require fabriq/streaming` | WebRTC signaling, FFmpeg transcoding (RTMP→HLS), viewer tracking, chat moderation |
| **Gaming** | `composer require fabriq/gaming` | Game loop, matchmaking, lobbies, UDP protocol (MessagePack), delta state sync |

> See [Enabling Add-on Packages](#enabling-add-on-packages) for setup instructions.

### Application Structure

| Directory | Description |
|-----------|-------------|
| `app/Http/Controllers/` | HTTP request controllers |
| `app/Providers/` | Service providers (register + boot lifecycle) |
| `app/Repositories/` | Data access layer (replaces Eloquent in Swoole context) |
| `app/Events/` | Domain event classes |
| `app/Listeners/` | Event listener handlers |
| `app/Jobs/` | Queued job classes |
| `app/Realtime/` | WebSocket message handlers |
| `app/Streaming/` | Stream controllers and custom streaming logic |
| `public/` | Frontend build output, per-tenant subdirectories |
| `public/_default/` | Default frontend (login page, marketing site) |
| `public/{tenant-slug}/` | Tenant-specific frontend builds |
| `storage/builds/` | Build workspace (git clone + npm build temp files) |
| `routes/api.php` | HTTP API route definitions |
| `routes/channels.php` | WebSocket channel definitions |
| `config/` | Individual config files (app, server, database, redis, auth, static, streaming, gaming, etc.) |
| `bootstrap/app.php` | Application bootstrap (creates app, registers providers) |

### Config Files

| File | Description |
|------|-------------|
| `config/app.php` | Application name & service providers list |
| `config/server.php` | Swoole host, port, workers, UDP settings |
| `config/database.php` | MySQL connection pools (platform + app) |
| `config/redis.php` | Redis connection settings |
| `config/auth.php` | JWT settings + RBAC role definitions |
| `config/tenancy.php` | Resolver chain + cache TTL |
| `config/queue.php` | Queue consumer group + retry policy |
| `config/events.php` | Event consumer group |
| `config/observability.php` | Log level |
| `config/static.php` | Frontend static file serving, SPA fallback, build automation |
| `config/streaming.php` | Live streaming — FFmpeg path, HLS settings, chat moderation |
| `config/gaming.php` | Game server — tick rates, matchmaking, UDP, reconnection |

---

## Tech Stack

| Component | Version |
|-----------|---------|
| PHP | ≥ 8.2 (target 8.3) |
| Swoole | Latest stable |
| MySQL | 8.0 |
| Redis | 7.x |
| FFmpeg | Latest (for streaming transcoding) |
| Docker Compose | v2 |

---

## Quick Start (Docker — Recommended)

> **Note:** Swoole does not run natively on Windows. Docker is the recommended way to run Fabriq on all platforms.

### 1. Create a new project

```bash
composer create-project easiviotech/fabriq-skeleton myapp
cd myapp
```

> Or clone the skeleton directly: `git clone https://github.com/easiviotech/fabriq-skeleton myapp && cd myapp`

### 2. Start the full stack

```bash
docker compose -f infra/docker-compose.yml up -d --build
```

Container names and ports are controlled by `infra/.env`:

```env
# infra/.env — customise per project
COMPOSE_PROJECT_NAME=fabriq
APP_PORT=8000
MYSQL_PORT=3306
REDIS_PORT=6379
ADMINER_PORT=8080
```

This builds the app image (PHP 8.3 + Swoole + Redis extension) and starts **six containers**:

| Container | Service | URL / Port |
|-----------|---------|------------|
| `{project}-app-1` | Fabriq HTTP + WS server | [http://localhost:8000](http://localhost:8000) |
| `{project}-processor-1` | Queue/event processor | *(background process)* |
| `{project}-scheduler-1` | Cron-like job scheduler | *(background process)* |
| `{project}-mysql-1` | MySQL 8.0 | `localhost:3306` |
| `{project}-redis-1` | Redis 7 | `localhost:6379` |
| `{project}-adminer-1` | Adminer (DB GUI) | [http://localhost:8080](http://localhost:8080) |

> **Multiple projects:** To run multiple Fabriq-based projects side by side, give each a unique `COMPOSE_PROJECT_NAME` and different ports in `infra/.env`. Docker Compose auto-namespaces containers, volumes, and networks — no conflicts.

All application containers (app, processor, scheduler) start automatically. The processor and scheduler have `restart: unless-stopped` for crash recovery. Wait for MySQL to pass its health check (~15–30 seconds) before the services connect.

### 4. Verify the server is running

```bash
curl http://localhost:8000/health
# {"status":"ok","service":"Fabriq","timestamp":...,"worker_id":0}
```

### 5. Access the database via Adminer

Open [http://localhost:8080](http://localhost:8080) and log in with:

| Field | Value |
|-------|-------|
| System | MySQL / MariaDB |
| Server | `mysql` |
| Username | `fabriq` |
| Password | `sfpass` |
| Database | `sf_platform` or `sf_app` |

### 6. View logs

```bash
# All containers
docker compose -f infra/docker-compose.yml logs -f

# Individual services
docker compose -f infra/docker-compose.yml logs -f app
docker compose -f infra/docker-compose.yml logs -f processor
docker compose -f infra/docker-compose.yml logs -f scheduler
```

### 7. Create a tenant

```bash
curl -X POST http://localhost:8000/api/tenants \
  -H "Content-Type: application/json" \
  -d '{"name":"Acme","slug":"acme"}'
```

### Stopping the stack

```bash
docker compose -f infra/docker-compose.yml down

# To also remove all data volumes (full reset):
docker compose -f infra/docker-compose.yml down -v
```

---

## Quick Start (Local — Linux / macOS only)

> Requires PHP 8.2+, Swoole extension, MySQL 8.0+, and Redis 7.x installed locally.

```bash
composer install

# Start MySQL and Redis (or use Docker for just the infrastructure):
docker compose -f infra/docker-compose.yml up -d mysql redis

# Start the HTTP + WebSocket server
php bin/fabriq serve

# In another terminal — start the queue processor
php bin/fabriq processor

# In another terminal — start the scheduler (optional)
php bin/fabriq scheduler
```

### Health check
```bash
curl http://localhost:8000/health
# {"status":"ok","timestamp":...,"worker_id":0}
```

---

## CLI Commands

| Command | Description |
|---------|-------------|
| `bin/fabriq serve` | Start HTTP + WS + UDP server (with streaming & gaming if enabled) |
| `bin/fabriq processor` | Start queue/event processor |
| `bin/fabriq scheduler` | Start job scheduler |
| `bin/fabriq frontend:build <slug>` | Build & deploy a tenant's frontend from their Git repo |
| `bin/fabriq frontend:status <slug>` | Show frontend deployment status for a tenant |

---

## Key Features

### Frontend Serving & Build Automation *(built-in)*

Fabriq serves per-tenant frontend builds directly through Swoole — no Nginx or Apache required. Use any frontend framework (React, Vue, Svelte, Angular, Next.js, etc.) and Fabriq handles the rest.

**Why serve frontends through Fabriq instead of Nginx, Vercel, or a CDN?**

| Traditional Setup | With Fabriq |
|---|---|
| Nginx + PHP-FPM + CDN + separate CI/CD | Single Swoole process serves API + frontend |
| Separate Nginx config per tenant | Tenants resolved automatically — zero server config |
| CORS headers, preflight requests, proxy hops | Same origin — no CORS, no extra round trips |
| Manage DNS + TLS + CDN per custom domain | Custom domains resolved via config or DB |
| Deploy frontend and API independently | Frontend and API ship together, atomic per-tenant deploys |
| No tenant awareness in the web server | Same tenant resolution shared between API and frontend |

**Features:**

- **Per-tenant builds** — Each tenant gets their own frontend at `public/{tenant-slug}/`, resolved automatically from subdomain, header, JWT, or custom domain
- **High performance** — Swoole's `sendfile()` delivers files via zero-copy kernel transfer, no PHP memory allocation
- **SPA fallback** — Client-side routing works out of the box (`index.html` served for unmatched paths)
- **Smart caching** — Fingerprinted assets get immutable/1-year cache; HTML gets no-cache (CDN-ready)
- **Custom domains** — 3-tier resolution: static `domain_map` (O(1)), TenantResolver, DB lookup — add a domain by adding a DB row, no server restart
- **Built-in CI/CD** — Clone a tenant's Git repo, run `npm build`, deploy atomically with zero downtime
- **Three trigger methods** — CLI (`frontend:build`), API (`POST /api/tenants/{id}/frontend/deploy`), webhook (GitHub/GitLab push)
- **Atomic deployments** — Old build is swapped out atomically; users never see a half-built state
- **Framework agnostic** — Tenant A on React, tenant B on Vue, tenant C on plain HTML — all served from the same process
- **Fallback directory** — `public/_default/` serves as the shared frontend (login, marketing) when a tenant has no custom build

> See [FRONTEND.md](docs/FRONTEND.md) for the full guide.

### `fabriq/streaming` — Live Streaming *(add-on package)*

> Install: `composer require fabriq/streaming` — [View on Packagist](https://packagist.org/packages/fabriq/streaming)

- **WebRTC signaling** — SDP offer/answer and ICE candidate exchange over WebSocket
- **FFmpeg transcoding** — RTMP → HLS with configurable segment duration and playlist size
- **Viewer tracking** — Redis-backed concurrent viewer counting with heartbeats
- **Chat moderation** — Slow mode, word filters, per-stream ban lists
- **CDN-ready** — HLS segments served with appropriate `Cache-Control` headers

### `fabriq/gaming` — Game Server *(add-on package)*

> Install: `composer require fabriq/gaming` — [View on Packagist](https://packagist.org/packages/fabriq/gaming)

- **Fixed tick-rate game loop** — 10 Hz (casual), 30 Hz (realtime), 60 Hz (competitive) via `Swoole\Timer`
- **UDP protocol** — Low-latency binary communication using MessagePack
- **Matchmaking** — Redis ZSET-based skill ranking with expanding search range
- **Pre-game lobbies** — Ready checks, countdowns, auto-start
- **Player reconnection** — Configurable grace period preserving session and room state
- **Delta state sync** — Only changed state is sent to each player

---

## Prometheus Metrics

Exposed at `GET /metrics`. Core metrics are always available; add-on metrics appear when the package is enabled.

**Core metrics:**

| Metric | Type | Description |
|--------|------|-------------|
| `http_requests_total` | counter | Total HTTP requests |
| `http_latency_ms` | histogram | Request latency |
| `ws_connections` | gauge | Active WebSocket connections |
| `queue_processed_total` | counter | Jobs processed |

**Streaming metrics** *(when `fabriq/streaming` is enabled):*

| Metric | Type | Description |
|--------|------|-------------|
| `streams_active` | gauge | Currently live streams |
| `stream_viewers_current` | gauge | Total concurrent viewers |
| `stream_transcodes_active` | gauge | Active FFmpeg processes |

**Gaming metrics** *(when `fabriq/gaming` is enabled):*

| Metric | Type | Description |
|--------|------|-------------|
| `game_rooms_active` | gauge | Active game rooms |
| `game_players_connected` | gauge | Connected game players |
| `game_tick_latency_ms` | histogram | Game loop tick timing |
| `udp_packets_total` | counter | UDP packets processed |
| `matchmaking_queue_size` | gauge | Players waiting for match |

---

## Testing

```bash
# Run all unit tests (inside Docker)
docker compose -f infra/docker-compose.yml exec app vendor/bin/phpunit

# Specific test
docker compose -f infra/docker-compose.yml exec app vendor/bin/phpunit tests/Unit/Kernel/ContextTest.php

# Or if you have PHP + Swoole locally:
vendor/bin/phpunit
```

---

## Documentation

| Doc | Description |
|-----|-------------|
| [DEVELOPERS_GUIDE.md](docs/DEVELOPERS_GUIDE.md) | Comprehensive developer guide covering all subsystems |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | System overview with component diagrams |
| [FRONTEND.md](docs/FRONTEND.md) | Frontend serving, per-tenant builds, CI/CD pipeline |
| [TENANCY.md](docs/TENANCY.md) | Tenant resolution, enforcement, config caching |
| [DATABASE.md](docs/DATABASE.md) | Connection pooling, transactions, tenancy strategy |
| [REALTIME_FABRIC.md](docs/REALTIME_FABRIC.md) | WS gateway, push API, cross-worker routing |
| [IDEMPOTENCY.md](docs/IDEMPOTENCY.md) | Idempotency keys, dedupe, storage layers |
| [SECURITY.md](docs/SECURITY.md) | JWT/API key auth, RBAC/ABAC policy engine |
| [OPERATIONS.md](docs/OPERATIONS.md) | Health, metrics, logging, tracing |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment guide (Docker, K8s, cloud, TLS) |

### Docs Site

The `docs-site/` directory contains a full HTML documentation site with syntax-highlighted code examples, search, and navigation:

| Page | Topic |
|------|-------|
| `index.html` | Getting Started (prerequisites, installation, project structure) |
| `architecture.html` | Architecture (component diagram, bootstrap lifecycle, key classes) |
| `configuration.html` | Configuration (all config files, dot-notation access, service providers) |
| `comparison.html` | Why Fabriq? (feature comparison matrix vs Hyperf, Octane, MixPHP) |
| `http.html` | HTTP Routing (routes, middleware, validation) |
| `tenancy.html` | Multi-Tenancy (resolution chain, enforcement) |
| `security.html` | Security (JWT, API keys, RBAC+ABAC, rate limiting) |
| `database.html` | Database (pools, DbManager, transactions, repositories) |
| `realtime.html` | Real-Time (WebSocket auth, push API, presence) |
| `async.html` | Async Processing (jobs, events, scheduling, idempotency) |
| `streaming.html` | Live Streaming (WebRTC, HLS, transcoding, chat moderation) |
| `gaming.html` | Game Server (tick loop, matchmaking, lobbies, state sync) |
| `operations.html` | Operations (logging, metrics, testing, deployment) |
| `deployment.html` | Production Deployment (Docker, Kubernetes, cloud, TLS, checklist) |

---

## Packagist Packages

Fabriq's packages are published individually on [Packagist](https://packagist.org). The **core packages** power every Fabriq application. The **add-on packages** are optional and installed only when needed.

### Core Packages

| Package | Packagist | Description |
|---------|-----------|-------------|
| [`fabriq/kernel`](https://packagist.org/packages/fabriq/kernel) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/kernel)](https://packagist.org/packages/fabriq/kernel) | Core container, config, context, server, service providers |
| [`fabriq/storage`](https://packagist.org/packages/fabriq/storage) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/storage)](https://packagist.org/packages/fabriq/storage) | Connection pools, DbManager, tenant-aware repositories |
| [`fabriq/observability`](https://packagist.org/packages/fabriq/observability) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/observability)](https://packagist.org/packages/fabriq/observability) | Structured logging, metrics, tracing |
| [`fabriq/tenancy`](https://packagist.org/packages/fabriq/tenancy) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/tenancy)](https://packagist.org/packages/fabriq/tenancy) | Multi-tenant context, resolution, config caching |

### Add-on Packages

| Package | Packagist | Description |
|---------|-----------|-------------|
| [`fabriq/streaming`](https://packagist.org/packages/fabriq/streaming) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/streaming)](https://packagist.org/packages/fabriq/streaming) | WebRTC signaling, HLS transcoding, viewer tracking, chat |
| [`fabriq/gaming`](https://packagist.org/packages/fabriq/gaming) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/gaming)](https://packagist.org/packages/fabriq/gaming) | Game loop, matchmaking, lobbies, UDP protocol, state sync |

### Enabling Add-on Packages

**`fabriq/streaming`:**

```bash
composer require fabriq/streaming
```

Then in your project:
1. Set `STREAMING_ENABLED=1` environment variable (or `'enabled' => true` in `config/streaming.php`)
2. Uncomment `\App\Providers\StreamingServiceProvider::class` in `config/app.php`

**`fabriq/gaming`:**

```bash
composer require fabriq/gaming
```

Then in your project:
1. Set `GAMING_ENABLED=1` environment variable (or `'enabled' => true` in `config/gaming.php`)
2. Uncomment `\App\Providers\GamingServiceProvider::class` in `config/app.php`

### Dependency Graph

```
fabriq/streaming ──→ fabriq/kernel
                 ──→ fabriq/storage ──→ fabriq/kernel
                 ──→ fabriq/observability ──→ fabriq/kernel

fabriq/gaming ──→ fabriq/kernel
              ──→ fabriq/storage
              ──→ fabriq/observability
              ──→ rybakit/msgpack

fabriq/tenancy ──→ fabriq/kernel (optional, suggested by kernel)
```

When you `composer require fabriq/streaming`, Composer automatically pulls in `fabriq/kernel`, `fabriq/storage`, and `fabriq/observability`.

---

## Safety Rules

- All connections use per-worker pools (never global/shared)
- Context is reset per request/message/job (no state leakage)
- All DB queries enforce `tenant_id` via repository base class
- No pool-per-tenant (prevents connection explosion)
- Borrow → use → release in same coroutine (no stored connections)
- Streaming and gaming services are tenant-scoped — no cross-tenant data leakage
- Game state is per-worker; cross-worker coordination uses Redis
- FFmpeg child processes are tracked and cleaned up on stream end
