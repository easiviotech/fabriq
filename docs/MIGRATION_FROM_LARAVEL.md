# Migrating from Laravel to Fabriq

This guide provides a side-by-side comparison for Laravel developers moving to Fabriq. The DX is intentionally similar — most patterns transfer directly.

## Key Differences

| Aspect | Laravel | Fabriq |
|--------|---------|--------|
| Runtime | PHP-FPM (request-per-process) | Swoole (persistent, async) |
| State | Stateless per request | Shared memory across requests |
| Multi-tenancy | Third-party packages | Built-in kernel-level |
| WebSockets | Broadcasting + Pusher/Soketi | Built-in Gateway + Redis Pub/Sub |
| Queue | Laravel Queue (Redis/DB/SQS) | Redis Streams with DLQ |
| Frontend | Mix/Vite (build-time) | Per-tenant atomic deploys |

## Routing

### Laravel

```php
// routes/web.php
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::get('/users/{id}', [UserController::class, 'show']);
```

### Fabriq

```php
// routes/api.php
use Fabriq\Http\Router;

return function (Router $router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->post('/users', [UserController::class, 'store']);
    $router->get('/users/{id}', [UserController::class, 'show']);
};
```

## Controllers

### Laravel

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::all();
        return response()->json($users);
    }
}
```

### Fabriq

```php
namespace App\Http\Controllers;

use Swoole\Http\Request;
use Swoole\Http\Response;

class UserController
{
    public function index(Request $request, Response $response): void
    {
        $users = User::all();
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($users->toArray()));
    }
}
```

**Key difference**: Fabriq controllers write directly to the Swoole Response object. There's no return value — you call `$response->end()`.

## Middleware

### Laravel

```php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->user()?->isAdmin()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
```

### Fabriq

```php
namespace App\Http\Middleware;

use Fabriq\Http\Request;
use Fabriq\Http\Response;

class EnsureAdmin
{
    public function __invoke(Request $request, Response $response, callable $next): void
    {
        if (!$request->attribute('user')?->isAdmin()) {
            $response->status(403);
            $response->end(json_encode(['error' => 'Forbidden']));
            return; // short-circuit
        }

        $next($request, $response);
    }
}
```

**Key difference**: Fabriq middleware uses `__invoke` with an explicit `$next` callable. Short-circuit by returning without calling `$next`.

## ORM / Models

### Laravel (Eloquent)

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = ['name', 'email'];
    protected $casts = ['is_admin' => 'boolean'];

    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}

// Usage
$user = User::find($id);
$users = User::where('status', 'active')->orderBy('name')->get();
$user->posts()->create(['title' => 'Hello']);
```

### Fabriq

```php
namespace App\Models;

use Fabriq\Orm\Model;

class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];
    protected array $casts = ['is_admin' => 'bool'];
    protected bool $tenantScoped = true;

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

// Usage
$user = User::find($id);
$users = User::query()->where('status', '=', 'active')->orderBy('name')->get();
$post = Post::create(['title' => 'Hello', 'user_id' => $user->id]);
```

**Key differences**:
- `$table` is an explicit string property
- `$tenantScoped = true` automatically adds `WHERE tenant_id = ?` to all queries
- Relationship definitions are identical in pattern
- `query()` returns a QueryBuilder for complex queries

## Service Providers

Nearly identical pattern:

### Laravel

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, fn() => new StripeGateway(
            config('services.stripe.key')
        ));
    }

    public function boot(): void
    {
        //
    }
}
```

### Fabriq

```php
namespace App\Providers;

use Fabriq\Kernel\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->singleton(PaymentGateway::class, fn() => new StripeGateway(
            $this->app->config()->get('services.stripe.key')
        ));
    }

    public function boot(): void
    {
        //
    }
}
```

## Queues / Jobs

### Laravel

```php
// Dispatching
ProcessOrder::dispatch($order)->onQueue('orders');

// Job class
class ProcessOrder implements ShouldQueue
{
    public function __construct(private Order $order) {}

    public function handle(): void
    {
        // process...
    }
}
```

### Fabriq

```php
// Dispatching
$dispatcher->dispatch('orders', 'ProcessOrder', ['order_id' => $order->id]);

// Consuming (in bootstrap/processor.php)
$consumer->registerHandler('ProcessOrder', function (array $payload) {
    $order = Order::find($payload['order_id']);
    // process...
});
```

**Key differences**:
- Jobs are dispatched as typed messages to Redis Streams
- Handlers are registered as callables, not separate classes
- Built-in retry with exponential backoff and dead letter queue
- Idempotency keys prevent duplicate processing

## WebSockets / Real-time

### Laravel

```php
// Broadcasting (server-side)
broadcast(new MessageSent($message))->toOthers();

// Echo (client-side)
Echo.channel('chat.1').listen('MessageSent', (e) => {
    console.log(e.message);
});
```

### Fabriq

```php
// Server-side push
$pushService->toRoom($tenantId, "chat:{$roomId}", json_encode([
    'type' => 'message',
    'data' => $message->toArray(),
]));

// Client-side (native WebSocket)
const ws = new WebSocket('wss://app.example.com/ws');
ws.onmessage = (e) => {
    const data = JSON.parse(e.data);
    console.log(data);
};
```

**Key differences**:
- Native WebSocket connections (no Pusher/Soketi dependency)
- Built-in room/channel management
- Cross-worker delivery via Redis Pub/Sub
- Per-tenant connection isolation

## Multi-tenancy

### Laravel (with third-party package)

```php
// Using stancl/tenancy or similar
tenancy()->initialize($tenant);
// All subsequent queries are scoped
```

### Fabriq (built-in)

```php
// Automatic — middleware resolves tenant from subdomain/header/domain
// All ORM queries with $tenantScoped = true are automatically filtered
// Database routing handles shared, same-server, or dedicated DB per tenant

// Access current tenant anywhere:
$tenant = Context::tenantId();
```

Multi-tenancy is a first-class kernel feature in Fabriq, not an afterthought. Tenant isolation is enforced at the database router level.

## CLI Commands

### Laravel

```bash
php artisan make:controller UserController
php artisan make:model User -m
php artisan migrate
```

### Fabriq

```bash
php bin/fabriq make:controller User
php bin/fabriq make:model User
php bin/fabriq make:migration create_users_table
php bin/fabriq migrate
```

## Configuration

Both use PHP array config files in `config/`. Fabriq uses the same dot-notation access:

```php
// Laravel
config('database.connections.mysql.host');

// Fabriq
$config->get('database.connections.mysql.host');
```

## What You Gain

- **10-50x throughput** from Swoole's persistent processes and coroutines
- **Built-in multi-tenancy** without third-party packages
- **Native WebSockets** without Pusher, Soketi, or Laravel Echo Server
- **Redis Streams** for reliable job processing with built-in DLQ
- **Per-tenant frontend deploys** with SPA fallback and custom domains
- **Single binary deployment** — one process serves HTTP, WebSocket, and background jobs
