<?php

declare(strict_types=1);

namespace App\Providers;

use Fabriq\Kernel\Config;
use Fabriq\Kernel\Container;
use Fabriq\Kernel\ServiceProvider;
use Fabriq\Observability\Logger;
use Fabriq\Storage\DbManager;
use Fabriq\Streaming\ChatModerator;
use Fabriq\Streaming\HlsManager;
use Fabriq\Streaming\SignalingHandler;
use Fabriq\Streaming\StreamManager;
use Fabriq\Streaming\TranscodingPipeline;
use Fabriq\Streaming\ViewerTracker;

/**
 * Streaming Service Provider.
 *
 * Registers all live streaming services into the container:
 *   - StreamManager (stream lifecycle)
 *   - SignalingHandler (WebRTC signaling)
 *   - TranscodingPipeline (FFmpeg transcoding)
 *   - HlsManager (HLS segment serving)
 *   - ViewerTracker (concurrent viewer counting)
 *   - ChatModerator (chat moderation)
 *
 * Only registers if config('streaming.enabled') is true.
 */
class StreamingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->config();
        if (!$config->get('streaming.enabled', false)) {
            return;
        }

        $container = $this->app->container();

        // Stream lifecycle manager
        $container->singleton(StreamManager::class, function (Container $c) {
            return new StreamManager(
                db: $c->make(DbManager::class),
                config: $c->make(Config::class),
                logger: $c->make(Logger::class),
            );
        });

        // WebRTC signaling
        $container->singleton(SignalingHandler::class, function (Container $c) {
            return new SignalingHandler(
                streamManager: $c->make(StreamManager::class),
                db: $c->make(DbManager::class),
                logger: $c->make(Logger::class),
            );
        });

        // FFmpeg transcoding
        $container->singleton(TranscodingPipeline::class, function (Container $c) {
            return new TranscodingPipeline(
                config: $c->make(Config::class),
                logger: $c->make(Logger::class),
            );
        });

        // HLS serving
        $container->singleton(HlsManager::class, function (Container $c) {
            return new HlsManager(
                config: $c->make(Config::class),
            );
        });

        // Viewer tracking
        $container->singleton(ViewerTracker::class, function (Container $c) {
            return new ViewerTracker(
                db: $c->make(DbManager::class),
            );
        });

        // Chat moderation
        $container->singleton(ChatModerator::class, function (Container $c) {
            return new ChatModerator(
                db: $c->make(DbManager::class),
                config: $c->make(Config::class),
            );
        });
    }

    public function boot(): void
    {
        $config = $this->app->config();
        if (!$config->get('streaming.enabled', false)) {
            return;
        }

        // Register HLS routes
        $this->registerHlsRoutes();
    }

    /**
     * Register HTTP routes for HLS segment serving.
     */
    private function registerHlsRoutes(): void
    {
        $this->app->addRoute(function ($request, $response): bool {
            $method = strtoupper($request->server['request_method'] ?? 'GET');
            $uri = $request->server['request_uri'] ?? '/';

            // Match GET /hls/{streamId}/{filename}
            if ($method === 'GET' && preg_match('#^/hls/([^/]+)/([^/]+)$#', $uri, $matches)) {
                $streamId = $matches[1];
                $filename = $matches[2];

                /** @var HlsManager $hls */
                $hls = $this->app->container()->make(HlsManager::class);
                return $hls->serve($request, $response, $streamId, $filename);
            }

            return false;
        });
    }
}

