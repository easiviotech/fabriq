<?php

declare(strict_types=1);

namespace Fabriq\Kernel;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server as WsServer;

/**
 * Unified Swoole HTTP + WebSocket + UDP server.
 *
 * Registers all lifecycle callbacks. Delegates request handling
 * to user-supplied callables set via onRequest(), onWsMessage(), etc.
 *
 * UDP support: Add a UDP listener on a separate port via addListener().
 * Binary WS: The onWsMessage handler receives Frame objects that may
 *   contain binary data (opcode WEBSOCKET_OPCODE_BINARY).
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

    /** @var callable(WsServer, string, array{address: string, port: int, server_socket: int}): void|null */
    private $udpPacketHandler = null;

    /** @var list<callable(Container): void> */
    private array $workerStartCallbacks = [];

    private ?Container $container = null;

    /** @var bool Whether UDP listener has been added */
    private bool $udpEnabled = false;

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
            'open_websocket_close_frame' => true,
        ]);

        // Default handlers (overridden by Application)
        $this->requestHandler = fn() => null;
        $this->wsOpenHandler = fn() => null;
        $this->wsMessageHandler = fn() => null;
        $this->wsCloseHandler = fn() => null;

        $this->registerCallbacks();
        $this->maybeAddUdpListener();
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
     * Register a handler for incoming UDP packets.
     *
     * @param callable(WsServer, string, array{address: string, port: int, server_socket: int}): void $handler
     */
    public function onUdpPacket(callable $handler): void
    {
        $this->udpPacketHandler = $handler;
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

    /**
     * Check if the UDP listener is enabled.
     */
    public function isUdpEnabled(): bool
    {
        return $this->udpEnabled;
    }

    /**
     * Send a UDP packet to a client.
     *
     * @param string $address Client IP address
     * @param int $port Client port
     * @param string $data Data to send
     */
    public function sendUdp(string $address, int $port, string $data): bool
    {
        if (!$this->udpEnabled) {
            return false;
        }

        return $this->swoole->sendto($address, $port, $data, -1);
    }

    /**
     * Push a binary WebSocket frame to a client.
     *
     * @param int $fd File descriptor
     * @param string $data Binary data
     */
    public function pushBinary(int $fd, string $data): bool
    {
        if (!$this->swoole->isEstablished($fd)) {
            return false;
        }

        return $this->swoole->push($fd, $data, WEBSOCKET_OPCODE_BINARY);
    }

    // ── Lifecycle ────────────────────────────────────────────────────

    public function start(): void
    {
        $msg = "[Fabriq] Starting server on "
            . $this->config->get('server.host', '0.0.0.0')
            . ":" . $this->config->get('server.port', 8000)
            . " (workers=" . $this->config->get('server.workers', 2)
            . ", task_workers=" . $this->config->get('server.task_workers', 2)
            . ")";

        if ($this->udpEnabled) {
            $msg .= " | UDP on :" . $this->config->get('server.udp_port', 8001);
        }

        echo $msg . "\n";

        $this->swoole->start();
    }

    // ── Internal Callback Wiring ─────────────────────────────────────

    /**
     * Add a UDP listener if configured.
     */
    private function maybeAddUdpListener(): void
    {
        $udpEnabled = (bool)$this->config->get('server.udp_enabled', false);
        if (!$udpEnabled) {
            return;
        }

        $host = $this->config->get('server.host', '0.0.0.0');
        $udpPort = (int)$this->config->get('server.udp_port', 8001);

        $udpListener = $this->swoole->addListener($host, $udpPort, SWOOLE_SOCK_UDP);
        if ($udpListener === false) {
            echo "[Fabriq] WARNING: Failed to add UDP listener on {$host}:{$udpPort}\n";
            return;
        }

        $udpListener->on('Packet', function ($server, string $data, array $clientInfo) {
            Context::reset();

            if ($this->udpPacketHandler !== null) {
                try {
                    ($this->udpPacketHandler)($server, $data, $clientInfo);
                } catch (\Throwable $e) {
                    // UDP is fire-and-forget; log but don't crash
                    error_log("[Fabriq] UDP handler error: " . $e->getMessage());
                }
            }
        });

        $this->udpEnabled = true;
    }

    private function registerCallbacks(): void
    {
        $this->swoole->on('WorkerStart', function (WsServer $server, int $workerId) {
            $type = $workerId < (int)$this->config->get('server.workers', 2)
                ? 'worker' : 'task_worker';
            echo "[Fabriq] {$type} #{$workerId} started\n";

            // Re-build per-worker container
            if ($this->container) {
                foreach ($this->workerStartCallbacks as $cb) {
                    $cb($this->container);
                }
            }
        });

        $this->swoole->on('Request', function (Request $request, Response $response) {
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
                // For binary frames, send error as JSON text frame
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
