<?php

declare(strict_types=1);

namespace App\Streaming;

use Fabriq\Kernel\Context;
use Fabriq\Streaming\HlsManager;
use Fabriq\Streaming\StreamManager;
use Fabriq\Streaming\ViewerTracker;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * REST API controller for stream management.
 *
 * Endpoints:
 *   POST   /api/streams           — Create a new stream
 *   GET    /api/streams           — List live streams
 *   GET    /api/streams/{id}      — Get stream info
 *   POST   /api/streams/{id}/end  — End a stream
 */
final class StreamController
{
    public function __construct(
        private readonly StreamManager $streamManager,
        private readonly ViewerTracker $viewerTracker,
        private readonly HlsManager $hlsManager,
    ) {}

    /**
     * Create a new stream and return the stream key.
     */
    public function create(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';
        $userId = Context::actorId() ?? 'anonymous';

        $body = json_decode($request->getContent() ?: '{}', true) ?: [];

        $result = $this->streamManager->createStream(
            tenantId: $tenantId,
            userId: $userId,
            title: $body['title'] ?? '',
            metadata: $body['metadata'] ?? [],
        );

        $response->header('Content-Type', 'application/json');
        $response->status(201);
        $response->end(json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * List live streams for the current tenant.
     */
    public function index(Request $request, Response $response): void
    {
        $tenantId = Context::tenantId() ?? 'default';

        $streams = $this->streamManager->getLiveStreams($tenantId);

        // Add viewer counts
        foreach ($streams as &$stream) {
            $stream['viewers'] = $this->viewerTracker->count($tenantId, $stream['stream_id']);
            $stream['hls_url'] = $this->hlsManager->getPlaybackUrl($stream['stream_id']);
        }

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['streams' => $streams], JSON_THROW_ON_ERROR));
    }

    /**
     * Get stream info by ID.
     */
    public function show(Request $request, Response $response, string $streamId): void
    {
        $stream = $this->streamManager->getStream($streamId);

        if ($stream === null) {
            $response->status(404);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode(['error' => 'Stream not found']));
            return;
        }

        $tenantId = $stream['tenant_id'];
        $stream['viewers'] = $this->viewerTracker->count($tenantId, $streamId);
        $stream['hls_available'] = $this->hlsManager->hasManifest($streamId);
        $stream['hls_url'] = $this->hlsManager->getPlaybackUrl($streamId);

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($stream, JSON_THROW_ON_ERROR));
    }

    /**
     * End a stream.
     */
    public function end(Request $request, Response $response, string $streamId): void
    {
        $result = $this->streamManager->endStream($streamId);

        if (!$result) {
            $response->status(404);
            $response->header('Content-Type', 'application/json');
            $response->end(json_encode(['error' => 'Stream not found']));
            return;
        }

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode(['status' => 'ended', 'stream_id' => $streamId]));
    }
}

