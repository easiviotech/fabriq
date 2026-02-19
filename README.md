# Fabriq

> **Unified Swoole-powered backend platform** — HTTP APIs, WebSocket realtime, background jobs, event bus, live streaming, and game server with first-class multi-tenancy.

**Brand:** Fabrq • **Runtime:** PHP 8.2+ / Swoole • **License:** Proprietary

---

## Architecture

Fabriq is a **long-running Swoole server** that hosts HTTP, WebSocket, UDP, queue workers, event consumers, live streaming, and a game server engine in a single process. Every execution path enforces `TenantContext` — tenant isolation is guaranteed at the kernel level.

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

## Quick Start

### 1. Start infrastructure
```bash
cd infra
docker compose up -d
```

### 2. Start server
```bash
docker exec -it fabriq-app php bin/fabriq serve
```

### 3. Start worker (separate terminal)
```bash
docker exec -it fabriq-app php bin/fabriq worker
```

### 4. Create a tenant
```bash
curl -X POST http://localhost:8000/api/tenants \
  -H "Content-Type: application/json" \
  -d '{"name":"Acme","slug":"acme"}'
```

### 5. Health check
```bash
curl http://localhost:8000/health
# {"status":"ok","timestamp":...,"worker_id":0}
```

---

## CLI Commands

| Command | Description |
|---------|-------------|
| `bin/fabriq serve` | Start HTTP + WS + UDP server (with streaming & gaming if enabled) |
| `bin/fabriq worker` | Start queue consumers |
| `bin/fabriq scheduler` | Start job scheduler |

---

## Key Features

### Live Streaming
- **WebRTC signaling** — SDP offer/answer and ICE candidate exchange over WebSocket
- **FFmpeg transcoding** — RTMP → HLS with configurable segment duration and playlist size
- **Viewer tracking** — Redis-backed concurrent viewer counting with heartbeats
- **Chat moderation** — Slow mode, word filters, per-stream ban lists
- **CDN-ready** — HLS segments served with appropriate `Cache-Control` headers

### Game Server
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
# Run all unit tests
docker compose exec app vendor/bin/phpunit

# Specific test
docker compose exec app vendor/bin/phpunit tests/Unit/Kernel/ContextTest.php
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
