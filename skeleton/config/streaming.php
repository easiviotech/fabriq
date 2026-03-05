<?php

declare(strict_types=1);

/**
 * Live streaming configuration.
 *
 * Controls WebRTC signaling, FFmpeg transcoding, HLS output, and chat moderation.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable Streaming
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) (getenv('STREAMING_ENABLED') ?: false),

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Path
    |--------------------------------------------------------------------------
    | Absolute path to the FFmpeg binary for transcoding. If not installed,
    | transcoding features will be unavailable (P2P-only mode).
    */
    'ffmpeg_path' => '/usr/bin/ffmpeg',

    /*
    |--------------------------------------------------------------------------
    | HLS Configuration
    |--------------------------------------------------------------------------
    | Controls the HLS output format for live streaming.
    | - segment_duration: seconds per .ts segment
    | - playlist_size: number of segments in the .m3u8 manifest
    | - storage_path: directory for HLS segments and manifests
    */
    'hls' => [
        'segment_duration' => 4,
        'playlist_size' => 5,
        'storage_path' => '/tmp/fabriq-hls',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream Key TTL
    |--------------------------------------------------------------------------
    | How long a stream key remains valid (in seconds). Default: 24 hours.
    */
    'stream_key_ttl' => 86400,

    /*
    |--------------------------------------------------------------------------
    | Max Concurrent Transcodes
    |--------------------------------------------------------------------------
    | Limit the number of simultaneous FFmpeg transcoding processes.
    */
    'max_concurrent_transcodes' => 4,

    /*
    |--------------------------------------------------------------------------
    | Chat Configuration
    |--------------------------------------------------------------------------
    | Controls chat behavior in live streams.
    | - slow_mode_seconds: minimum delay between messages (0 = disabled)
    | - max_message_length: maximum characters per chat message
    */
    'chat' => [
        'slow_mode_seconds' => 0,
        'max_message_length' => 500,
    ],
];
