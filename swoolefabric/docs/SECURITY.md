# SwooleFabric — Security Architecture

## Authentication

### JWT (Bearer Tokens)
- Algorithm: HS256 (HMAC-SHA256)
- Claims: `tenant_id`, `actor_id`, `roles`, `iat`, `exp`
- Used for: user sessions, WS connections
- Tokens validated on every HTTP request / WS auth message

### API Keys
- Format: 64-char hex (32 random bytes)
- Storage: prefix (first 8 chars, plaintext for lookup) + SHA-256 hash
- Used for: server-to-server, tenant bootstrap
- Validated via `X-API-Key` header

## Authorization (PolicyEngine)

### RBAC (Role-Based)
Roles define permissions: `{resource}:{action}`

| Role | Permissions |
|------|------------|
| `admin` | `*:*` |
| `editor` | `rooms:create`, `rooms:update`, `messages:*` |
| `viewer` | `messages:read`, `rooms:read` |

Wildcards: `rooms:*` (all room actions), `*:*` (superuser).

### ABAC (Attribute-Based)
Additional conditions checked after RBAC allows:

```php
$engine->addCondition('rooms', 'create', function (array $ctx): bool {
    return $ctx['tenant_plan'] !== 'free';  // Only paid plans
});
```

### Decision Flow
```
RBAC check → if denied → DENY
           → if allowed → ABAC conditions
                        → if any fails → DENY
                        → all pass → ALLOW
```

### Audit Logging
Every policy decision is logged:
```json
{
  "actor_id": "user-123",
  "resource": "rooms",
  "action": "create",
  "allowed": true,
  "rbac": true,
  "abac": true,
  "timestamp": 1708127400.123
}
```

## Rate Limiting
- Per-tenant sliding window (Redis INCR + EXPIRE)
- Default: 100 requests / 60 seconds
- Response headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`

## Middleware Chain Order
```
1. CorrelationMiddleware  → sets request/correlation IDs
2. AuthMiddleware         → validates JWT/API key
3. TenancyMiddleware      → resolves tenant
4. RateLimitMiddleware    → enforces rate limits
5. PolicyMiddleware       → RBAC/ABAC check
6. [route handler]
```
