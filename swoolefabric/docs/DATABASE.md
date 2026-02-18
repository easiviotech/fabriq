# SwooleFabric — Database Architecture

## Overview

SwooleFabric uses coroutine-safe connection pooling for both MySQL and Redis, designed for Swoole's long-running, high-concurrency model.

## Connection Pool Design

### Pool Implementation

Every pool is backed by `Swoole\Coroutine\Channel`:

```
borrow() → pop from channel (or create new if < max_size)
           → health-check before returning
           → if unhealthy: discard, create replacement
           → if pool full + no idle: block up to borrow_timeout

release() → push back to channel
           → if channel full: discard connection
```

### Pool Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `max_size` | 20 | Maximum connections per pool per worker |
| `borrow_timeout` | 3.0s | Max wait when pool is exhausted |
| `idle_timeout` | 60.0s | Reserved for future idle eviction |

### Per-Worker Pools

Pools are initialized in `onWorkerStart` — **never shared across workers**. Each Swoole worker gets its own pool instances:

```
Worker 0: [Platform Pool (max 20)] [App Pool (max 20)] [Redis Pool (max 20)]
Worker 1: [Platform Pool (max 20)] [App Pool (max 20)] [Redis Pool (max 20)]
```

Total max connections = `workers × max_size × pool_count`.

## Database Architecture

### Dual Database Model

| Database | Purpose | Tables |
|----------|---------|--------|
| `sf_platform` | Shared platform metadata | `tenants`, `api_keys`, `roles`, `idempotency_keys` |
| `sf_app` | Tenant application data | `users`, `rooms`, `room_members`, `messages` |

### Tenancy Strategy (Hybrid)

**Default (v1):** Shared app database with `tenant_id` enforcement.

Every row in the app DB includes `tenant_id`:
- Repository layer **requires** `TenantContext` to be set
- If `tenant_id` is null → fail-fast `RuntimeException`
- All queries include `WHERE tenant_id = ?` via base `TenantAwareRepository`

**Future (v2):** Optional per-tenant DB or shard-per-group:
- `DbManager::app($dbKey)` accepts an optional key for routing
- LRU-managed pool map for dedicated tenant connections
- No pool-per-tenant by default (prevents connection explosion)

## Transaction Patterns

```php
// Borrows ONE connection, holds it for the transaction
$result = $dbManager->transaction(function ($conn) {
    $conn->query('INSERT INTO messages ...');
    $conn->query('UPDATE rooms SET last_message_at = ...');
    return $messageId;
});
// Connection automatically released after callable returns
```

**Rules:**
1. Never store connections in class properties
2. Always borrow → use → release in the same coroutine
3. `transaction()` handles begin/commit/rollback + release

## Health Check & Reconnect

On every `borrow()`:
1. Pop connection from channel
2. Execute `SELECT 1` (MySQL) or `PING` (Redis)
3. If healthy → return to caller
4. If unhealthy → discard, decrement pool size, create fresh connection

This ensures callers never receive broken connections.

## Monitoring

`DbManager::stats()` returns per-pool metrics:

```json
{
  "platform": { "current_size": 5, "max_size": 20, "idle": 3, "in_use": 2 },
  "app":      { "current_size": 8, "max_size": 20, "idle": 5, "in_use": 3 },
  "redis":    { "current_size": 4, "max_size": 20, "idle": 2, "in_use": 2 }
}
```

Exposed via `/metrics` endpoint (Phase 7) as `db_pool_in_use` and `db_pool_waits` gauges.
