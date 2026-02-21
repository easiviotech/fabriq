# Fabriq

> **Unified Swoole-powered backend platform** — HTTP APIs, WebSocket realtime, background jobs, event bus, live streaming, and game server with first-class multi-tenancy.

**Brand:** Fabrq • **Runtime:** PHP 8.2+ / Swoole • **License:** Proprietary

---

## Architecture

Fabriq is a **long-running Swoole server** that hosts HTTP, WebSocket, UDP, queue processors, event consumers, live streaming, and a game server engine in a single process. Every execution path enforces `TenantContext` — tenant isolation is guaranteed at the kernel level.

```
Client → HTTP/WS/UDP → Middleware Chain → Route Handler → DB/Redis (tenant-scoped)
                                        → Push to WS rooms (Redis Pub/Sub)
                                        → Dispatch job (Redis Streams)
                                        → Emit event (Redis Streams)
                                        → WebRTC signaling / HLS serving (Streaming)
                                        → Game room tick / state sync (Gaming)
```

### Packages

| Package | Path | Description |
|---------|------|-------------|
| **Kernel** | `packages/kernel/` | Swoole server bootstrap, Context (coroutine-local), Config, Container, Application, ServiceProvider |
| **Tenancy** | `packages/tenancy/` | TenantResolver, TenantContext, TenantConfigCache |
| **Storage** | `packages/storage/` | Connection pools (Channel-based), DbManager, TenantAwareRepository |
| **HTTP** | `packages/http/` | Router, Request/Response wrappers, MiddlewareChain, Validator |
| **Realtime** | `packages/realtime/` | Gateway (fd mapping), Presence, PushService, RealtimeSubscriber |
| **Queue** | `packages/queue/` | Dispatcher, Consumer (retry/DLQ), Scheduler, IdempotencyStore |
| **Events** | `packages/events/` | EventBus, EventConsumer (dedupe), EventSchema |
| **Security** | `packages/security/` | JwtAuthenticator, ApiKeyAuthenticator, PolicyEngine (RBAC+ABAC), RateLimiter |
| **Observability** | `packages/observability/` | Logger (structured JSON), MetricsCollector (Prometheus), TraceContext |
| **Streaming** | `packages/streaming/` | SignalingHandler (WebRTC), StreamManager, TranscodingPipeline (FFmpeg/HLS), ViewerTracker, ChatModerator |
| **Gaming** | `packages/gaming/` | GameLoop, GameRoom, GameRoomManager, Matchmaker (Redis ZSET), LobbyManager, UdpProtocol (MessagePack), StateSync, PlayerSession |

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
| `routes/api.php` | HTTP API route definitions |
| `routes/channels.php` | WebSocket channel definitions |
| `config/` | Individual config files (app, server, database, redis, auth, streaming, gaming, etc.) |
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

### 1. Clone the project

```bash
git clone <repo-url> myapp
cd myapp
```

### 2. Install Composer dependencies

```bash
# If you have PHP locally (any platform):
composer install --ignore-platform-reqs

# Or skip this — Docker will install dependencies during the build.
```

### 3. Start the full stack

From the **project root**, run:

```bash
docker compose -f infra/docker-compose.yml up -d --build
```

This builds the app image (PHP 8.3 + Swoole + Redis extension) and starts **six containers**:

| Container | Service | URL / Port |
|-----------|---------|------------|
| `fabriq-app` | Fabriq HTTP + WS server | [http://localhost:8000](http://localhost:8000) |
| `fabriq-processor` | Queue/event processor | *(background process)* |
| `fabriq-scheduler` | Cron-like job scheduler | *(background process)* |
| `fabriq-mysql` | MySQL 8.0 | `localhost:3306` |
| `fabriq-redis` | Redis 7 | `localhost:6379` |
| `fabriq-adminer` | Adminer (DB GUI) | [http://localhost:8080](http://localhost:8080) |

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

---

## Key Features

### Live Streaming *(opt-in — disabled by default)*

Enable by setting `STREAMING_ENABLED=1` (or `'enabled' => true` in `config/streaming.php`) and uncommenting `StreamingServiceProvider` in `config/app.php`.

- **WebRTC signaling** — SDP offer/answer and ICE candidate exchange over WebSocket
- **FFmpeg transcoding** — RTMP → HLS with configurable segment duration and playlist size
- **Viewer tracking** — Redis-backed concurrent viewer counting with heartbeats
- **Chat moderation** — Slow mode, word filters, per-stream ban lists
- **CDN-ready** — HLS segments served with appropriate `Cache-Control` headers

### Game Server *(opt-in — disabled by default)*

Enable by setting `GAMING_ENABLED=1` (or `'enabled' => true` in `config/gaming.php`), uncommenting `GamingServiceProvider` in `config/app.php`, and installing `composer require rybakit/msgpack`.

- **Fixed tick-rate game loop** — 10 Hz (casual), 30 Hz (realtime), 60 Hz (competitive) via `Swoole\Timer`
- **UDP protocol** — Low-latency binary communication using MessagePack
- **Matchmaking** — Redis ZSET-based skill ranking with expanding search range
- **Pre-game lobbies** — Ready checks, countdowns, auto-start
- **Player reconnection** — Configurable grace period preserving session and room state
- **Delta state sync** — Only changed state is sent to each player

---

## Prometheus Metrics

Exposed at `GET /metrics`. Key built-in metrics include:

| Metric | Type | Description |
|--------|------|-------------|
| `http_requests_total` | counter | Total HTTP requests |
| `http_latency_ms` | histogram | Request latency |
| `ws_connections` | gauge | Active WebSocket connections |
| `queue_processed_total` | counter | Jobs processed |
| `streams_active` | gauge | Currently live streams |
| `stream_viewers_current` | gauge | Total concurrent viewers |
| `stream_transcodes_active` | gauge | Active FFmpeg processes |
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

Fabriq's core packages are published individually on [Packagist](https://packagist.org), so you can install only what you need:

```bash
composer require fabriq/kernel
composer require fabriq/storage
composer require fabriq/observability
composer require fabriq/tenancy
composer require fabriq/streaming
composer require fabriq/gaming
```

| Package | Packagist | Description |
|---------|-----------|-------------|
| [`fabriq/kernel`](https://packagist.org/packages/fabriq/kernel) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/kernel)](https://packagist.org/packages/fabriq/kernel) | Core container, config, context, server, service providers |
| [`fabriq/storage`](https://packagist.org/packages/fabriq/storage) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/storage)](https://packagist.org/packages/fabriq/storage) | Connection pools, DbManager, tenant-aware repositories |
| [`fabriq/observability`](https://packagist.org/packages/fabriq/observability) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/observability)](https://packagist.org/packages/fabriq/observability) | Structured logging, metrics, tracing |
| [`fabriq/tenancy`](https://packagist.org/packages/fabriq/tenancy) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/tenancy)](https://packagist.org/packages/fabriq/tenancy) | Multi-tenant context, resolution, config caching |
| [`fabriq/streaming`](https://packagist.org/packages/fabriq/streaming) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/streaming)](https://packagist.org/packages/fabriq/streaming) | WebRTC signaling, HLS transcoding, viewer tracking, chat |
| [`fabriq/gaming`](https://packagist.org/packages/fabriq/gaming) | [![Latest Version](https://img.shields.io/packagist/v/fabriq/gaming)](https://packagist.org/packages/fabriq/gaming) | Game loop, matchmaking, lobbies, UDP protocol, state sync |

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
