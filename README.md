# Fabriq

> **Unified Swoole-powered backend platform** — HTTP APIs, WebSocket realtime, background jobs, and event bus with first-class multi-tenancy.

**Brand:** Fabrq • **Runtime:** PHP 8.2+ / Swoole • **License:** Proprietary

---

## Architecture

Fabriq is a **long-running Swoole server** that hosts HTTP, WebSocket, queue workers, and event consumers in a single process. Every execution path enforces `TenantContext` — tenant isolation is guaranteed at the kernel level.

```
Client → HTTP/WS → Middleware Chain → Route Handler → DB/Redis (tenant-scoped)
                                    → Push to WS rooms (Redis Pub/Sub)
                                    → Dispatch job (Redis Streams)
                                    → Emit event (Redis Streams)
```

### Packages

| Package | Path | Description |
|---------|------|-------------|
| **Kernel** | `packages/kernel/` | Swoole server bootstrap, Context (coroutine-local), Config, Container, Application |
| **Tenancy** | `packages/tenancy/` | TenantResolver, TenantContext, TenantConfigCache |
| **Storage** | `packages/storage/` | Connection pools (Channel-based), DbManager, TenantAwareRepository |
| **HTTP** | `packages/http/` | Router, Request/Response wrappers, MiddlewareChain, Validator |
| **Realtime** | `packages/realtime/` | Gateway (fd mapping), Presence, PushService, RealtimeSubscriber |
| **Queue** | `packages/queue/` | Dispatcher, Consumer (retry/DLQ), Scheduler, IdempotencyStore |
| **Events** | `packages/events/` | EventBus, EventConsumer (dedupe), EventSchema |
| **Security** | `packages/security/` | JwtAuthenticator, ApiKeyAuthenticator, PolicyEngine (RBAC+ABAC), RateLimiter |
| **Observability** | `packages/observability/` | Logger (structured JSON), MetricsCollector (Prometheus), TraceContext |

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
| `routes/api.php` | HTTP API route definitions |
| `routes/channels.php` | WebSocket channel definitions |
| `config/` | Individual config files (app, server, database, redis, auth, etc.) |
| `bootstrap/app.php` | Application bootstrap (creates app, registers providers) |

---

## Tech Stack

| Component | Version |
|-----------|---------|
| PHP | ≥ 8.2 (target 8.3) |
| Swoole | Latest stable |
| MySQL | 8.0 |
| Redis | 7.x |
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
| `bin/fabriq serve` | Start HTTP + WS server |
| `bin/fabriq worker` | Start queue consumers |
| `bin/fabriq scheduler` | Start job scheduler |

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
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | System overview with component diagrams |
| [TENANCY.md](docs/TENANCY.md) | Tenant resolution, enforcement, config caching |
| [DATABASE.md](docs/DATABASE.md) | Connection pooling, transactions, tenancy strategy |
| [REALTIME_FABRIC.md](docs/REALTIME_FABRIC.md) | WS gateway, push API, cross-worker routing |
| [IDEMPOTENCY.md](docs/IDEMPOTENCY.md) | Idempotency keys, dedupe, storage layers |
| [SECURITY.md](docs/SECURITY.md) | JWT/API key auth, RBAC/ABAC policy engine |
| [OPERATIONS.md](docs/OPERATIONS.md) | Health, metrics, logging, tracing |

---

## Safety Rules

- All connections use per-worker pools (never global/shared)
- Context is reset per request/message/job (no state leakage)
- All DB queries enforce `tenant_id` via repository base class
- No pool-per-tenant (prevents connection explosion)
- Borrow → use → release in same coroutine (no stored connections)
