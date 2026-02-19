<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Gaming\GameLoop;
use Fabriq\Gaming\GameRoomManager;
use Fabriq\Gaming\LobbyManager;
use Fabriq\Gaming\Matchmaker;
use Fabriq\Gaming\PlayerSession;
use Fabriq\Gaming\StateSync;
use Fabriq\Gaming\UdpProtocol;
use Fabriq\Kernel\Config;
use Fabriq\Kernel\Container;
use Fabriq\Kernel\Server;
use Fabriq\Kernel\ServiceProvider;
use Fabriq\Observability\Logger;
use Fabriq\Storage\DbManager;

/**
 * Gaming Service Provider.
 *
 * Registers all game server services into the container:
 *   - UdpProtocol (binary message encode/decode)
 *   - GameLoop (tick engine)
 *   - GameRoomManager (room lifecycle)
 *   - Matchmaker (skill-based matchmaking)
 *   - PlayerSession (connection tracking with reconnection)
 *   - LobbyManager (pre-game lobbies)
 *   - StateSync (delta compression)
 *
 * Only registers if config('gaming.enabled') is true.
 */
class GamingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->config();
        if (!$config->get('gaming.enabled', false)) {
            return;
        }

        $container = $this->app->container();

        // Binary protocol
        $container->singleton(UdpProtocol::class, function (Container $c) {
            $config = $c->make(Config::class);
            return new UdpProtocol(
                format: (string)$config->get('gaming.protocol', 'msgpack'),
            );
        });

        // Game loop (tick engine)
        $container->singleton(GameLoop::class, function (Container $c) {
            $config = $c->make(Config::class);
            $tickRates = $config->get('gaming.tick_rates', []);

            // Convert Hz to milliseconds
            $tickMs = [];
            if (is_array($tickRates)) {
                foreach ($tickRates as $type => $hz) {
                    $tickMs[$type] = (int)(1000 / max(1, (int)$hz));
                }
            }

            return new GameLoop(
                protocol: $c->make(UdpProtocol::class),
                logger: $c->make(Logger::class),
                tickRates: $tickMs,
            );
        });

        // Room manager
        $container->singleton(GameRoomManager::class, function (Container $c) {
            return new GameRoomManager(
                gameLoop: $c->make(GameLoop::class),
                db: $c->make(DbManager::class),
                server: $c->make(Server::class)->getSwoole(),
                config: $c->make(Config::class),
                logger: $c->make(Logger::class),
            );
        });

        // Matchmaker
        $container->singleton(Matchmaker::class, function (Container $c) {
            return new Matchmaker(
                db: $c->make(DbManager::class),
                config: $c->make(Config::class),
                logger: $c->make(Logger::class),
            );
        });

        // Player sessions
        $container->singleton(PlayerSession::class, function (Container $c) {
            $config = $c->make(Config::class);
            return new PlayerSession(
                db: $c->make(DbManager::class),
                windowSeconds: (int)$config->get('gaming.reconnection_window_seconds', 30),
            );
        });

        // Lobby manager
        $container->singleton(LobbyManager::class, function (Container $c) {
            return new LobbyManager(
                roomManager: $c->make(GameRoomManager::class),
                protocol: $c->make(UdpProtocol::class),
                server: $c->make(Server::class)->getSwoole(),
                logger: $c->make(Logger::class),
            );
        });

        // State sync (stateless utility)
        $container->singleton(StateSync::class, function () {
            return new StateSync();
        });
    }

    public function boot(): void
    {
        $config = $this->app->config();
        if (!$config->get('gaming.enabled', false)) {
            return;
        }

        // Start game loop per worker
        $this->app->onWorkerStart(function (Container $c) {
            $config = $c->make(Config::class);
            if (!$config->get('gaming.enabled', false)) {
                return;
            }

            /** @var GameLoop $gameLoop */
            $gameLoop = $c->make(GameLoop::class);
            $gameLoop->start();
        });

        // Wire UDP packet handler if UDP is enabled
        /** @var Server $server */
        $server = $this->app->container()->make(Server::class);

        if ($server->isUdpEnabled()) {
            $server->onUdpPacket(function ($swoole, string $data, array $clientInfo) {
                $container = $this->app->container();

                /** @var UdpProtocol $protocol */
                $protocol = $container->make(UdpProtocol::class);
                $message = $protocol->decode($data);

                if ($message === null) {
                    return;
                }

                // Route to appropriate game room
                /** @var GameRoomManager $roomManager */
                $roomManager = $container->make(GameRoomManager::class);
                $room = $roomManager->findRoom($message['room_id']);

                if ($room !== null && $message['type'] === 'player_input') {
                    // Apply player input to the room
                    $room->setState('last_input', $message['data']);
                }
            });
        }

        // Register gaming API routes
        $this->registerGameRoutes();
    }

    /**
     * Register gaming-related HTTP API routes.
     */
    private function registerGameRoutes(): void
    {
        $this->app->addRoute(function ($request, $response): bool {
            $method = strtoupper($request->server['request_method'] ?? 'GET');
            $uri = $request->server['request_uri'] ?? '/';

            // GET /api/game/rooms — list available rooms
            if ($method === 'GET' && $uri === '/api/game/rooms') {
                $tenantId = $request->header['x-tenant-id'] ?? 'default';

                /** @var GameRoomManager $roomManager */
                $roomManager = $this->app->container()->make(GameRoomManager::class);
                $rooms = $roomManager->getAvailableRooms($tenantId);

                $response->header('Content-Type', 'application/json');
                $response->end(json_encode(['rooms' => $rooms]));
                return true;
            }

            return false;
        });
    }
}

