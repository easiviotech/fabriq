<?php

declare(strict_types=1);

namespace SwooleFabric\Kernel;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

/**
 * Unified Swoole HTTP + WebSocket server.
 *
 * Registers all lifecycle callbacks. Delegates request handling
 * to a user-supplied callable (the "request handler") which will
 * typically be the HTTP Router once Phase 3 is built.
 */
final class Server
{
    private WsServer $swoole;
    private Config $config;

    /** @var callable(Request, Response): void */
    private $requestHandler;

    /** @var callable(WsServer, Request): void */
    private $wsOpenHandler;

    /** @var callable(WsServer, Frame): void */
    private $wsMessageHandler;

    /** @var callable(WsServer, int): void */
    private $wsCloseHandler;

    /** @var list<callable(Container): void> */
    private array $workerStartCallbacks = [];

    private ?Container $container = null;

    public function __construct(Config $config)
    {
        $this->config = $config;

        $host = $config->get('server.host', '0.0.0.0');
        $port = (int)$config->get('server.port', 8000);

        $this->swoole = new WsServer($host, $port);

        $this->swoole->set([
            'worker_num' => (int)$config->get('server.workers', 2),
            'task_worker_num' => (int)$config->get('server.task_workers', 2),
            'daemonize' => false,
            'log_level' => (int)$config->get('server.log_level', 4), // SWOOLE_LOG_WARNING
            'open_http2_protocol' => false,
            'enable_coroutine' => true,
        ]);

        // Default handlers (overridden by Application)
        $this->requestHandler = fn() => null;
        $this->wsOpenHandler = fn() => null;
        $this->wsMessageHandler = fn() => null;
        $this->wsCloseHandler = fn() => null;

        $this->registerCallbacks();
    }

    // ── Handler Registration ─────────────────────────────────────────

    /**
     * @param callable(Request, Response): void $handler
     */
    public function onRequest(callable $handler): void
    {
        $this->requestHandler = $handler;
    }

    /**
     * @param callable(WsServer, Request): void $handler
     */
    public function onWsOpen(callable $handler): void
    {
        $this->wsOpenHandler = $handler;
    }

    /**
     * @param callable(WsServer, Frame): void $handler
     */
    public function onWsMessage(callable $handler): void
    {
        $this->wsMessageHandler = $handler;
    }

    /**
     * @param callable(WsServer, int): void $handler
     */
    public function onWsClose(callable $handler): void
    {
        $this->wsCloseHandler = $handler;
    }

    /**
     * @param callable(Container): void $callback
     */
    public function addWorkerStartCallback(callable $callback): void
    {
        $this->workerStartCallbacks[] = $callback;
    }

    public function setContainer(Container $container): void
    {
        $this->container = $container;
    }

    public function getSwoole(): WsServer
    {
        return $this->swoole;
    }

    // ── Lifecycle ────────────────────────────────────────────────────

    public function start(): void
    {
        echo "[SwooleFabric] Starting server on "
            . $this->config->get('server.host', '0.0.0.0')
            . ":" . $this->config->get('server.port', 8000)
            . " (workers=" . $this->config->get('server.workers', 2)
            . ", task_workers=" . $this->config->get('server.task_workers', 2)
            . ")\n";

        $this->swoole->start();
    }

    // ── Internal Callback Wiring ─────────────────────────────────────

    private function registerCallbacks(): void
    {
        $this->swoole->on('WorkerStart', function (WsServer $server, int $workerId) {
            $type = $workerId < (int)$this->config->get('server.workers', 2)
                ? 'worker' : 'task_worker';
            echo "[SwooleFabric] {$type} #{$workerId} started\n";

            // Re-build per-worker container
            if ($this->container) {
                foreach ($this->workerStartCallbacks as $cb) {
                    $cb($this->container);
                }
            }
        });

        $this->swoole->on('Request', function (Request $request, Response $response) {
            // Skip WebSocket upgrade requests — Swoole handles those
            if ($request->server['request_uri'] === '/favicon.ico') {
                $response->status(404);
                $response->end();
                return;
            }

            // Reset context for every HTTP request
            Context::reset();

            try {
                ($this->requestHandler)($request, $response);
            }
            catch (\Throwable $e) {
                $response->status(500);
                $response->header('Content-Type', 'application/json');
                $response->end(json_encode([
                    'error' => 'Internal Server Error',
                    'message' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->swoole->on('Open', function (WsServer $server, Request $request) {
            Context::reset();
            try {
                ($this->wsOpenHandler)($server, $request);
            }
            catch (\Throwable $e) {
                $server->disconnect($request->fd, 1011, $e->getMessage());
            }
        });

        $this->swoole->on('Message', function (WsServer $server, Frame $frame) {
            Context::reset();
            try {
                ($this->wsMessageHandler)($server, $frame);
            }
            catch (\Throwable $e) {
                $server->push($frame->fd, json_encode([
                    'error' => $e->getMessage(),
                ], JSON_THROW_ON_ERROR));
            }
        });

        $this->swoole->on('Close', function (WsServer $server, int $fd) {
            try {
                ($this->wsCloseHandler)($server, $fd);
            }
            catch (\Throwable) {
            // Swallow — client is already gone
            }
        });

        $this->swoole->on('Task', function (WsServer $server, int $taskId, int $srcWorkerId, mixed $data) {
            Context::reset();
            // Task handling will be wired in Phase 5 (queue)
            return $data;
        });

        $this->swoole->on('Finish', function (WsServer $server, int $taskId, mixed $data) {
        // Task result callback — Phase 5
        });
    }
}
