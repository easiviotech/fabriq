# Fabriq — Database Architecture

## Overview

Fabriq uses coroutine-safe connection pooling for both MySQL and Redis, designed for Swoole's long-running, high-concurrency model. The built-in ORM provides Active Record models, a fluent query builder, stored procedure support, schema migrations, and per-tenant database routing — all coroutine-safe.

## Default Credentials (Docker)

The `docker-compose.yml` creates a MySQL instance with the following credentials:

| Parameter | Value |
|-----------|-------|
| Host (from containers / Adminer) | `mysql` |
| Host (from host machine) | `localhost` |
| Port | `3306` |
| Root password | `rootpass` |
| Application username | `fabriq` |
| Application password | `sfpass` |
| Platform database | `sf_platform` |
| Application database | `sf_app` |

These values are configured in `config/database.php` and must match the `docker-compose.yml` environment variables.

### Accessing via Adminer

Adminer is included in the Docker stack at [http://localhost:8080](http://localhost:8080):

| Field | Value |
|-------|-------|
| System | MySQL / MariaDB |
| Server | `mysql` |
| Username | `fabriq` |
| Password | `sfpass` |
| Database | `sf_platform` or `sf_app` |

> **Important:** Use `mysql` as the server name (the Docker service name), not `localhost` or `db`.

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
| `sf_app` | Tenant application data | `users`, `rooms`, `room_members`, `messages`, `streams` |

### Tenancy Strategy (Per-Tenant Routing)

The `TenantDbRouter` dynamically selects the correct database connection based on the tenant's configuration:

| Strategy | How It Works |
|----------|-------------|
| **shared** (default) | All tenants share the `app` pool. Isolation is via `WHERE tenant_id = ?` |
| **same_server** | Borrow from `app` pool, then `USE <tenant_db>`. Restore original DB on release |
| **dedicated** | Dynamically create/cache a `ConnectionPool` for the tenant's MySQL server. LRU eviction keeps memory bounded |

Configuration in `config/orm.php`:

```php
'tenant_routing' => [
    'default_strategy'    => 'shared',
    'max_dedicated_pools' => 50,
    'dedicated_pool'      => [
        'max_size'       => 10,
        'borrow_timeout' => 3.0,
        'idle_timeout'   => 120.0,
    ],
],
```

Tenant strategy is configured in the tenant's `config_json`:

```json
{
  "database": {
    "strategy": "same_server",
    "name": "tenant_acme_db"
  }
}
```

Or for a dedicated server:

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

## ORM — Query Builder

The `DB` facade provides a fluent query builder:

```php
use Fabriq\Orm\DB;

// Select
$users = DB::table('users')
    ->where('status', 'active')
    ->orderBy('name')
    ->get();

// Insert
$id = DB::table('orders')->insert([
    'id'       => $orderId,
    'total'    => 99.99,
    'status'   => 'pending',
]);

// Update
DB::table('orders')
    ->where('id', $orderId)
    ->update(['status' => 'completed']);

// Delete
DB::table('orders')
    ->where('id', $orderId)
    ->delete();

// Pagination
$paginated = DB::table('users')
    ->where('status', 'active')
    ->paginate(perPage: 15, page: 2);

// Raw query
$rows = DB::raw('SELECT * FROM users WHERE id = ?', [$id]);
```

The `QueryBuilder` is **immutable** — each method returns a new instance, making it safe to share across coroutines. Connections are borrowed and released entirely within terminal methods (`get`, `first`, `insert`, `update`, `delete`).

## ORM — Active Record Models

Define models by extending the `Model` base class:

```php
<?php
namespace App\Models;

use Fabriq\Orm\Model;

class User extends Model
{
    protected string $table = 'users';
    protected string $primaryKey = 'id';
    protected bool $tenantScoped = true;
    protected bool $timestamps = true;

    protected array $fillable = ['name', 'email', 'status'];

    protected array $casts = [
        'is_admin' => 'bool',
        'settings' => 'json',
    ];

    public function orders(): \Fabriq\Orm\Relations\HasMany
    {
        return $this->hasMany(Order::class);
    }
}
```

Usage:

```php
use App\Models\User;

// Find
$user = User::find('uuid-123');
$user = User::findOrFail('uuid-123');

// Query
$activeUsers = User::where('status', 'active')->get();

// Create
$user = User::create(['name' => 'Jane', 'email' => 'jane@example.com']);

// Update
$user->name = 'Jane Doe';
$user->save();

// Delete
$user->delete();

// All records
$users = User::all();
```

### Tenant Scoping

When `$tenantScoped = true`, the model automatically:
- Adds `WHERE tenant_id = ?` to all SELECT, UPDATE, DELETE queries
- Injects `tenant_id` into INSERT data
- The `tenant_id` is read from `Context::tenantId()`

### Relationships

```php
// Has One
$user->profile();  // returns HasOne

// Has Many
$user->orders();   // returns HasMany

// Belongs To
$order->user();    // returns BelongsTo

// Belongs To Many (pivot table)
$user->roles();    // returns BelongsToMany
```

### Collections

Query results are returned as `Collection` objects with functional helpers:

```php
$users = User::where('status', 'active')->get();

$names = $users->pluck('name');
$grouped = $users->groupBy('department');
$filtered = $users->filter(fn($u) => $u->age > 18);
$mapped = $users->map(fn($u) => $u->toArray());
```

## ORM — Stored Procedures

For applications that use stored procedures, the `DB::call()` fluent builder handles IN/OUT parameters and tenant-aware routing:

```php
use Fabriq\Orm\DB;

$result = DB::call('sp_get_user_stats')
    ->in('user_id', $userId)
    ->out('total_orders')
    ->out('total_spent')
    ->exec();

$totalOrders = $result->out('total_orders');
$rows        = $result->rows();
```

When the tenant has a `same_server` or `dedicated` database strategy, the `CALL` statement is automatically executed on the correct tenant database — no manual routing required.

### Batch Parameters

```php
$result = DB::call('sp_process_batch')
    ->withParams([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
        'category'   => 'electronics',
    ])
    ->out('processed_count')
    ->exec();
```

## ORM — Schema & Migrations

### Schema Builder

```php
use Fabriq\Orm\Schema\Schema;
use Fabriq\Orm\Schema\Blueprint;

Schema::create('orders', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->tenantId();
    $table->string('status', 50)->default('pending');
    $table->decimal('total', 10, 2);
    $table->timestamps();

    $table->index(['tenant_id', 'status']);
});

Schema::alter('orders', function (Blueprint $table) {
    $table->string('tracking_number', 100)->nullable();
});

Schema::drop('temp_data');
```

### Migrations

Migration files follow the convention `NNN_description.php`:

```php
<?php
use Fabriq\Orm\Schema\Migration;
use Fabriq\Orm\Schema\Schema;
use Fabriq\Orm\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('status', 50)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('orders');
    }
};
```

Run migrations via `MigrationRunner`:

```php
$runner = new MigrationRunner($router, 'database/migrations');
$ran = $runner->migrate();      // Run pending
$rolled = $runner->rollback(1); // Roll back last
$pending = $runner->pending();  // List pending
```

## Transaction Patterns

### Using DB Facade

```php
DB::transaction('app', function ($conn) {
    $conn->query('INSERT INTO messages ...');
    $conn->query('UPDATE rooms SET last_message_at = ...');
    return $messageId;
});
// Commits on success, rolls back on exception, always releases the connection.
```

### Using DbManager Directly

```php
$result = $dbManager->transaction('app', function ($conn) use ($tenantId) {
    $conn->prepare('INSERT INTO orders (id, tenant_id, total) VALUES (?, ?, ?)')
         ->execute(['order-1', $tenantId, 99.99]);
    return ['order_id' => 'order-1'];
});
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

Exposed via `/metrics` endpoint as `db_pool_in_use` and `db_pool_waits` gauges.
