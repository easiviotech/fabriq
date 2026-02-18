<?php

declare(strict_types=1);

namespace SwooleFabric\Kernel;

use Swoole\Http\Request;
use Swoole\Http\Response;
use SwooleFabric\Observability\MetricsCollector;
use SwooleFabric\Observability\Logger;
use SwooleFabric\Storage\DbManager;

/**
 * Application bootstrap.
 *
 * Loads config, builds the DI container, wires the HTTP server,
 * and registers the default /health and /metrics endpoints.
 */
final class Application
{
    private Config $config;
    private Container $container;
    private Server $server;

    /** @var list<callable(Request, Response): bool> Route handlers; return true if handled */
    private array $routes = [];

    public function __construct(string $configPath)
    {
        $this->config = Config::fromFile($configPath);
        $this->container = new Container();
        $this->server = new Server($this->config);

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

    /**
     * Register a route handler.
     *
     * @param callable(Request, Response): bool $handler Return true if request was handled
     */
    public function addRoute(callable $handler): void
    {
        $this->routes[] = $handler;
    }

    /**
     * Register a callback to run in onWorkerStart.
     *
     * @param callable(Container): void $callback
     */
    public function onWorkerStart(callable $callback): void
    {
        $this->server->addWorkerStartCallback($callback);
    }

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
                    'service' => 'swoolefabric',
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
