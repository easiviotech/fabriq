<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Fabriq\Observability\MetricsCollector;
use Fabriq\Observability\Logger;
use Fabriq\Storage\DbManager;

/**
 * Application bootstrap — the heart of Fabriq.
 *
 * Application lifecycle:
 *   1. Instantiate with a base path
 *   2. Config is loaded from the config/ directory
 *   3. Service providers are registered (bindings)
 *   4. Service providers are booted (cross-service wiring)
 *   5. Server starts
 *
 * The two-phase provider lifecycle (register → boot) ensures that
 * providers can safely resolve each other's bindings during boot().
 */
final class Application
{
    private string $basePath;
    private Config $config;
    private Container $container;
    private Server $server;

    /** @var list<ServiceProvider> */
    private array $providers = [];

    /** @var bool Whether providers have been booted */
    private bool $booted = false;

    /** @var list<callable(Request, Response): bool> Route handlers; return true if handled */
    private array $routes = [];

    /**
     * Create a new application instance.
     *
     * @param string $basePath Root directory of the project (contains config/, routes/, app/, etc.)
     */
    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');

        // Load configuration from the config/ directory
        $configDir = $this->configPath();
        if (is_dir($configDir)) {
            $this->config = Config::fromDirectory($configDir);
        } else {
            $this->config = new Config();
        }

        $this->container = new Container();
        $this->server = new Server($this->config);

        // Register core instances
        $this->container->instance(self::class, $this);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Server::class, $this->server);

        // Register observability services
        $metrics = new MetricsCollector();
        $this->registerDefaultMetrics($metrics);
        $this->container->instance(MetricsCollector::class, $metrics);

        $logLevel = $this->config->get('observability.log_level', 'info');
        $logger = new Logger(is_string($logLevel) ? $logLevel : 'info');
        $this->container->instance(Logger::class, $logger);

        // Register DbManager
        $dbManager = new DbManager();
        $this->container->instance(DbManager::class, $dbManager);

        $this->server->setContainer($this->container);

        // Boot DB pools per worker
        $this->onWorkerStart(function (Container $c) {
            /** @var DbManager $db */
            $db = $c->make(DbManager::class);
            /** @var Config $cfg */
            $cfg = $c->make(Config::class);
            $db->boot($cfg);
        });

        $this->registerDefaultRoutes($metrics);
        $this->wireRequestHandler($metrics);
    }

    // ── Path Helpers ────────────────────────────────────────────────

    /**
     * Get the base path of the application.
     */
    public function basePath(string $path = ''): string
    {
        return $this->basePath . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    /**
     * Get the path to the config/ directory.
     */
    public function configPath(string $path = ''): string
    {
        return $this->basePath('config') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    /**
     * Get the path to the routes/ directory.
     */
    public function routesPath(string $path = ''): string
    {
        return $this->basePath('routes') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    /**
     * Get the path to the app/ directory.
     */
    public function appPath(string $path = ''): string
    {
        return $this->basePath('app') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    /**
     * Get the path to the database/ directory.
     */
    public function databasePath(string $path = ''): string
    {
        return $this->basePath('database') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    /**
     * Get the path to the bootstrap/ directory.
     */
    public function bootstrapPath(string $path = ''): string
    {
        return $this->basePath('bootstrap') . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, '/\\') : '');
    }

    // ── Accessors ────────────────────────────────────────────────────

    public function config(): Config
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function server(): Server
    {
        return $this->server;
    }

    // ── Service Provider Lifecycle ───────────────────────────────────

    /**
     * Register a service provider.
     *
     * Accepts either a ServiceProvider instance or a class name string.
     *
     * @param ServiceProvider|class-string<ServiceProvider> $provider
     */
    public function register(ServiceProvider|string $provider): ServiceProvider
    {
        if (is_string($provider)) {
            $provider = new $provider($this);
        }

        $provider->register();
        $this->providers[] = $provider;

        // If already booted, boot this provider immediately
        if ($this->booted) {
            $provider->boot();
        }

        return $provider;
    }

    /**
     * Boot all registered providers.
     *
     * Called once after all providers have been registered.
     * Each provider's boot() method can safely resolve any service.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    /**
     * Register all providers listed in config('app.providers').
     *
     * This is the conventional way to register providers — list them
     * in config/app.php under the 'providers' key.
     */
    public function registerConfiguredProviders(): void
    {
        $providers = $this->config->get('app.providers', []);

        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $providerClass) {
            if (is_string($providerClass) && class_exists($providerClass)) {
                $this->register($providerClass);
            }
        }
    }

    // ── Route Registration ──────────────────────────────────────────

    /**
     * Register a route handler.
     *
     * @param callable(Request, Response): bool $handler Return true if request was handled
     */
    public function addRoute(callable $handler): void
    {
        $this->routes[] = $handler;
    }

    // ── Worker Start Hooks ──────────────────────────────────────────

    /**
     * Register a callback to run in onWorkerStart.
     *
     * @param callable(Container): void $callback
     */
    public function onWorkerStart(callable $callback): void
    {
        $this->server->addWorkerStartCallback($callback);
    }

    // ── Run ─────────────────────────────────────────────────────────

    /**
     * Start the Swoole server (blocking).
     */
    public function run(): void
    {
        $this->server->start();
    }

    // ── Internals ────────────────────────────────────────────────────

    private function registerDefaultMetrics(MetricsCollector $metrics): void
    {
        $metrics->registerCounter('http_requests_total', 'Total HTTP requests');
        $metrics->registerHistogram('http_latency_ms', 'HTTP request latency in milliseconds');
        $metrics->registerGauge('ws_connections', 'Active WebSocket connections');
        $metrics->registerGauge('ws_online_users', 'Online WebSocket users');
        $metrics->registerCounter('queue_processed_total', 'Total queue jobs processed');
        $metrics->registerGauge('queue_lag', 'Queue processing lag');
        $metrics->registerGauge('db_pool_in_use', 'DB pool connections in use');
        $metrics->registerCounter('db_pool_waits', 'DB pool borrow wait count');

        // Streaming metrics
        $metrics->registerGauge('streams_active', 'Currently live streams');
        $metrics->registerGauge('stream_viewers_current', 'Total concurrent viewers');
        $metrics->registerGauge('stream_transcodes_active', 'Active FFmpeg processes');

        // Gaming metrics
        $metrics->registerGauge('game_rooms_active', 'Active game rooms');
        $metrics->registerGauge('game_players_connected', 'Connected game players');
        $metrics->registerHistogram('game_tick_latency_ms', 'Game loop tick timing');
        $metrics->registerCounter('udp_packets_total', 'UDP packets processed');
        $metrics->registerGauge('matchmaking_queue_size', 'Players waiting for match');
    }

    private function registerDefaultRoutes(MetricsCollector $metrics): void
    {
        // GET /health — always available
        $this->addRoute(function (Request $request, Response $response): bool {
            $method = strtoupper($request->server['request_method'] ?? 'GET');
            $uri = $request->server['request_uri'] ?? '/';

            if ($method === 'GET' && $uri === '/health') {
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'status' => 'ok',
                    'service' => 'Fabriq',
                    'timestamp' => time(),
                    'request_id' => Context::requestId(),
                ], JSON_THROW_ON_ERROR));
                return true;
            }

            return false;
        });

        // GET /metrics — Prometheus text format
        $this->addRoute(function (Request $request, Response $response) use ($metrics): bool {
            $method = strtoupper($request->server['request_method'] ?? 'GET');
            $uri = $request->server['request_uri'] ?? '/';

            if ($method === 'GET' && $uri === '/metrics') {
                $response->header('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
                $response->end($metrics->render());
                return true;
            }

            return false;
        });
    }

    private function wireRequestHandler(MetricsCollector $metrics): void
    {
        $this->server->onRequest(function (Request $request, Response $response) use ($metrics) {
            $startTime = microtime(true);
            $metrics->increment('http_requests_total');

            foreach ($this->routes as $handler) {
                if ($handler($request, $response)) {
                    $latencyMs = (microtime(true) - $startTime) * 1000;
                    $metrics->observe('http_latency_ms', $latencyMs);
                    return;
                }
            }

            // No route matched → 404
            $response->status(404);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode([
                'error' => 'Not Found',
                'path' => $request->server['request_uri'] ?? '/',
            ], JSON_THROW_ON_ERROR));

            $latencyMs = (microtime(true) - $startTime) * 1000;
            $metrics->observe('http_latency_ms', $latencyMs);
        });
    }
}
