<?php

declare(strict_types=1);

namespace App\Streaming;

use Fabriq\Streaming\StreamManager;
use Fabriq\Streaming\TranscodingPipeline;
use Fabriq\Streaming\ViewerTracker;

/**
 * Event listener for stream lifecycle events.
 *
 * Reacts to stream state changes and triggers side effects:
 *   - Stream started → start transcoding (if needed), update metrics
 *   - Stream ended → stop transcoding, clean up viewer data
 *   - Viewer threshold → auto-switch from P2P to HLS
 */
final class StreamEventListener
{
    /** @var int Viewer count at which to switch from P2P to HLS transcoding */
    private const HLS_THRESHOLD = 50;

    public function __construct(
        private readonly StreamManager $streamManager,
        private readonly TranscodingPipeline $transcodingPipeline,
        private readonly ViewerTracker $viewerTracker,
    ) {}

    /**
     * Handle stream started event.
     */
    public function onStreamStarted(string $streamId, string $tenantId): void
    {
        $this->streamManager->startStream($streamId);
    }

    /**
     * Handle stream ended event.
     */
    public function onStreamEnded(string $streamId, string $tenantId): void
    {
        // Stop transcoding if active
        if ($this->transcodingPipeline->isActive($streamId)) {
            $this->transcodingPipeline->stop($streamId);
        }

        // Clean up viewer data
        $this->viewerTracker->clearStream($tenantId, $streamId);

        // End the stream
        $this->streamManager->endStream($streamId);
    }

    /**
     * Check if a stream should switch to HLS transcoding based on viewer count.
     *
     * Call this periodically or on viewer join.
     */
    public function checkTranscodingThreshold(string $streamId, string $tenantId): void
    {
        $viewers = $this->viewerTracker->count($tenantId, $streamId);

        if ($viewers >= self::HLS_THRESHOLD && !$this->transcodingPipeline->isActive($streamId)) {
            // Switch to HLS — viewers will be redirected to the HLS manifest
            // The input URL would come from the stream's WebRTC/WHIP ingest
            // For now, this is a placeholder for the actual ingest URL
            $stream = $this->streamManager->getStream($streamId);
            if ($stream !== null) {
                $inputUrl = $stream['metadata']['ingest_url'] ?? '';
                if ($inputUrl !== '') {
                    $this->transcodingPipeline->start($streamId, $inputUrl);
                }
            }
        }
    }
}

