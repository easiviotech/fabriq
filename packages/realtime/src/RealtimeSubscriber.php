<?php

declare(strict_types=1);

namespace Fabriq\Realtime;

use Swoole\Coroutine;
use Swoole\Coroutine\Redis;
use Swoole\WebSocket\Server as WsServer;
use Fabriq\Kernel\Config;
use Fabriq\Storage\DbManager;

/**
 * Per-worker Redis Pub/Sub subscriber.
 *
 * Runs in a long-lived coroutine spawned during onWorkerStart.
 * Subscribes to push channels and dispatches received messages
 * to local Gateway connections.
 *
 * Uses a DEDICATED Redis connection (not from pool) since
 * Redis SUBSCRIBE blocks the connection.
 */
final class RealtimeSubscriber
{
    private const CHANNEL_PATTERN = 'sf:push:*';

    private ?Redis $subscriber = null;
    private bool $running = false;

    /** @var array{host: string, port: int, password: string} */
    private array $redisConfig;

    public function __construct(
        private readonly Gateway $gateway,
        private readonly WsServer $server,
        private readonly DbManager $db,
        ?Config $config = null,
    ) {
        // Extract Redis config for the dedicated subscriber connection
        $this->redisConfig = [
            'host' => $config?->get('redis.host', '127.0.0.1') ?? '127.0.0.1',
            'port' => (int) ($config?->get('redis.port', 6379) ?? 6379),
            'password' => (string) ($config?->get('redis.password', '') ?? ''),
        ];
    }

    /**
     * Start subscribing in a background coroutine.
     *
     * Call this from onWorkerStart.
     */
    public function start(): void
    {
        $this->running = true;

        Coroutine::create(function () {
            $this->subscribeLoop();
        });
    }

    /**
     * Stop the subscriber.
     */
    public function stop(): void
    {
        $this->running = false;

        if ($this->subscriber !== null) {
            try {
                $this->subscriber->close();
            } catch (\Throwable) {
                // Ignore
            }
            $this->subscriber = null;
        }
    }

    /**
     * Main subscribe loop — reconnects on failure.
     */
    private function subscribeLoop(): void
    {
        while ($this->running) {
            try {
                $this->subscriber = $this->createSubscriberConnection();

                // Pattern subscribe to all push channels
                $this->subscriber->psubscribe([self::CHANNEL_PATTERN]);

                while ($this->running) {
                    $message = $this->subscriber->recv();

                    if ($message === false || $message === null) {
                        break; // Connection lost
                    }

                    // psubscribe messages: [type, pattern, channel, data]
                    if (is_array($message) && ($message[0] ?? '') === 'pmessage') {
                        $channel = $message[2] ?? '';
                        $data = $message[3] ?? '';
                        $this->dispatch($channel, $data);
                    }
                }
            } catch (\Throwable) {
                // Reconnect after a delay
            }

            if ($this->running) {
                Coroutine::sleep(1.0); // Wait before reconnecting
            }
        }
    }

    /**
     * Dispatch a received pub/sub message to local connections.
     */
    private function dispatch(string $channel, string $rawData): void
    {
        $data = json_decode($rawData, true);
        if (!is_array($data)) {
            return;
        }

        $type = $data['type'] ?? '';
        $tenantId = $data['tenant_id'] ?? '';
        $payload = json_encode($data['payload'] ?? [], JSON_THROW_ON_ERROR);

        match ($type) {
            'user' => $this->gateway->pushToUser(
                $this->server,
                $tenantId,
                $data['user_id'] ?? '',
                $payload,
            ),
            'room' => $this->gateway->pushToRoom(
                $this->server,
                $tenantId,
                $data['room_id'] ?? '',
                $payload,
            ),
            'topic' => $this->broadcastToAllConnections($payload),
            default => null,
        };
    }

    /**
     * Broadcast to all connections on this worker (for topic messages).
     */
    private function broadcastToAllConnections(string $payload): void
    {
        foreach ($this->server->connections as $fd) {
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, $payload);
            }
        }
    }

    /**
     * Create a dedicated Redis connection for subscribing.
     *
     * Uses a fresh connection (not from pool) since SUBSCRIBE blocks.
     */
    private function createSubscriberConnection(): Redis
    {
        $redis = new Redis();
        $connected = $redis->connect(
            $this->redisConfig['host'],
            $this->redisConfig['port'],
            5.0,
        );

        if (!$connected) {
            throw new \RuntimeException(
                "RealtimeSubscriber: Failed to connect to Redis at "
                . "{$this->redisConfig['host']}:{$this->redisConfig['port']}"
            );
        }

        if ($this->redisConfig['password'] !== '') {
            $redis->auth($this->redisConfig['password']);
        }

        return $redis;
    }
}

