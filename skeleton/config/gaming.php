<?php

declare(strict_types=1);

/**
 * Game server configuration.
 *
 * Controls the game loop tick rates, room limits, matchmaking,
 * reconnection behavior, and binary protocol settings.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Enable Gaming
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) (getenv('GAMING_ENABLED') ?: false),

    /*
    |--------------------------------------------------------------------------
    | UDP Port for Game State
    |--------------------------------------------------------------------------
    | The UDP port used for low-latency game state updates. This should match
    | server.udp_port when server.udp_enabled is true.
    */
    'udp_port' => 8001,

    /*
    |--------------------------------------------------------------------------
    | Tick Rates (Hz)
    |--------------------------------------------------------------------------
    | Game loop frequency for different game types.
    | - casual: card games, trivia, turn-based (10 Hz)
    | - realtime: .io games, action RPGs (30 Hz)
    | - competitive: FPS, fighting games (60 Hz)
    */
    'tick_rates' => [
        'casual' => 10,
        'realtime' => 30,
        'competitive' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Room Limits
    |--------------------------------------------------------------------------
    */
    'max_rooms_per_worker' => 100,
    'max_players_per_room' => 64,

    /*
    |--------------------------------------------------------------------------
    | Matchmaking
    |--------------------------------------------------------------------------
    | - rating_range: initial skill rating difference for matching
    | - expand_after_seconds: widen the range after this many seconds
    | - max_wait_seconds: maximum time before giving up on finding a match
    */
    'matchmaking' => [
        'rating_range' => 100,
        'expand_after_seconds' => 10,
        'max_wait_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconnection Window
    |--------------------------------------------------------------------------
    | Seconds to keep a player's session alive after disconnect.
    | During this window, the player can reconnect and resume.
    */
    'reconnection_window_seconds' => 30,

    /*
    |--------------------------------------------------------------------------
    | Protocol
    |--------------------------------------------------------------------------
    | Binary serialization format for game state.
    | - 'json': human-readable, larger payload
    | - 'msgpack': compact binary, ~30-40% smaller than JSON
    */
    'protocol' => 'msgpack',
];
