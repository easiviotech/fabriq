# Fabriq — Tenancy Architecture

## Tenant Resolution Chain

Every request must resolve to a tenant, enforced by TenancyMiddleware. The resolution priority:

```
1. Host subdomain  →  acme.Fabriq.dev → "acme"
2. X-Tenant header →  X-Tenant: acme        → "acme"
3. JWT claim       →  { tenant_id: "acme" } → "acme"
```

If none resolves → `403 Forbidden`.

## TenantContext

```php
TenantContext {
    id: string        // UUID or generated ID
    slug: string      // URL-safe identifier
    name: string      // Display name
    plan: string      // "free", "pro", "enterprise"
    status: string    // "active", "suspended"
    config: array     // Tenant-specific overrides (including database strategy)
    dbKey: ?string    // Database routing key — drives TenantDbRouter
}
```

Suspended tenants are rejected at resolution time.

### Database Fields in config_json

The `config_json` column (stored in the `tenants` table) can include a `database` block that controls how the ORM routes queries for this tenant:

```json
{
  "database": {
    "strategy": "shared"
  }
}
```

| Strategy | `dbKey` | Description |
|----------|---------|-------------|
| `shared` (default) | `null` | All tenants share the `app` pool. Row-level isolation via `WHERE tenant_id = ?` |
| `same_server` | `null` | Same MySQL server, separate database name. `USE <tenant_db>` before each query |
| `dedicated` | Auto-generated pool key | Tenant has its own MySQL server; a dedicated connection pool is created dynamically |

For `same_server`:

```json
{
  "database": {
    "strategy": "same_server",
    "name": "tenant_acme_db"
  }
}
```

For `dedicated`:

```json
{
  "database": {
    "strategy": "dedicated",
    "host": "10.0.1.50",
    "port": 3306,
    "name": "acme_production",
    "username": "acme_user",
    "password": "secret"
  }
}
```

## Per-Tenant Database Routing

The `TenantDbRouter` is the ORM component responsible for acquiring the correct database connection for the current tenant. It reads the tenant's strategy from `Context::tenant()` and routes accordingly.

### Routing Flow

```
Request arrives
  → TenancyMiddleware resolves tenant
    → Context::setTenant(TenantContext)
      → Application code calls DB::table('orders')->get()
        → QueryBuilder calls TenantDbRouter::acquire('app')
          → Router inspects TenantContext::config['database']['strategy']
            → shared:    borrow from default 'app' pool
            → same_server: borrow from 'app' pool, then USE <tenant_db>
            → dedicated: borrow from tenant-specific pool (created on first use)
          → Query executes on the correct connection
        → TenantDbRouter::release() returns connection to the right pool
```

### Dedicated Pool LRU Cache

To prevent unbounded memory growth, dedicated tenant pools are managed with an LRU cache:

- Maximum pools: configurable via `config/orm.php` → `tenant_routing.max_dedicated_pools` (default: 50)
- When the cache is full and a new tenant's pool is needed, the least-recently-used pool is closed and evicted
- Pool configuration (size, timeouts) is set in `tenant_routing.dedicated_pool`

### Stored Procedures & Tenant Routing

Stored procedure calls (`DB::call('sp_name')`) are also routed through `TenantDbRouter`. If a tenant uses `same_server` or `dedicated` strategy, the `CALL` statement executes on the tenant's database automatically:

```php
// Automatically routed to the tenant's database
$result = DB::call('sp_calculate_invoice')
    ->in('order_id', $orderId)
    ->out('invoice_total')
    ->exec();
```

## Enforcement

| Layer | How tenant_id is enforced |
|-------|--------------------------|
| HTTP | TenancyMiddleware resolves and sets Context |
| WS | WsAuthHandler resolves from JWT, registers in Gateway |
| Jobs | Context restored from job fields (tenant_id stored at dispatch) |
| Events | Context restored from event fields |
| ORM Models | `HasTenantScope` trait auto-adds `WHERE tenant_id = ?` and injects `tenant_id` on insert |
| ORM QueryBuilder | `tenantScoped()` method adds tenant filter; models opt-in via `$tenantScoped = true` |
| DB Routing | `TenantDbRouter` selects pool/database per tenant strategy |
| Repository | Base `TenantAwareRepository` adds `WHERE tenant_id = ?` |
| Live Streaming | `StreamManager` carries `tenant_id`; signaling, chat, and viewer tracking are tenant-scoped |
| Game Server | `GameRoom`, `Matchmaker`, and `LobbyManager` carry `tenant_id`; matchmaking queues are per-tenant |

## Config Cache

Tenant configuration is cached in Redis with 5-minute TTL:
```
Key: sf:tenant:config:{slug}
TTL: 300 seconds
```

This avoids hitting the database on every request for tenant lookup.

## Coroutine Safety

All tenant context is stored using `Swoole\Coroutine::getContext()`, meaning each coroutine (each HTTP request, WebSocket frame, or job execution) has its own isolated tenant context. There is **no risk of tenant data leaking** between concurrent requests.

```php
// Each coroutine sees its own tenant
Context::setTenant($tenant);      // Sets for THIS coroutine only
Context::tenantId();               // Returns THIS coroutine's tenant ID
```
