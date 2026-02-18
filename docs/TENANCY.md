# SwooleFabric — Tenancy Architecture

## Tenant Resolution Chain

Every request must resolve to a tenant, enforced by TenancyMiddleware. The resolution priority:

```
1. Host subdomain  →  acme.swoolefabric.dev → "acme"
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
    config: array     // Tenant-specific overrides
    dbKey: ?string    // For future shard routing
}
```

Suspended tenants are rejected at resolution time.

## Enforcement

| Layer | How tenant_id is enforced |
|-------|--------------------------|
| HTTP | TenancyMiddleware resolves and sets Context |
| WS | WsAuthHandler resolves from JWT, registers in Gateway |
| Jobs | Context restored from job fields (tenant_id stored at dispatch) |
| Events | Context restored from event fields |
| Repository | Base `TenantAwareRepository` adds `WHERE tenant_id = ?` |

## Config Cache

Tenant configuration is cached in Redis with 5-minute TTL:
```
Key: sf:tenant:config:{slug}
TTL: 300 seconds
```

This avoids hitting the database on every request for tenant lookup.
