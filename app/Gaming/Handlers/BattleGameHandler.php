<?php

declare(strict_types=1);

namespace App\Gaming\Handlers;

use Fabriq\Gaming\GameRoom;

/**
 * Example game tick handler for a real-time battle/.io game.
 *
 * This is a sample implementation showing how to build a real-time
 * competitive game using the Fabriq gaming engine.
 * Runs at the "realtime" tick rate (30 Hz).
 *
 * Features:
 *   - Player position tracking with server authority
 *   - Input processing (movement, actions)
 *   - Collision detection (simplified)
 *   - Score tracking
 *   - Game time limit
 */
final class BattleGameHandler
{
    /** @var float Game duration in seconds */
    private const GAME_DURATION = 180.0; // 3 minutes

    /** @var float Player movement speed (units per second) */
    private const MOVE_SPEED = 200.0;

    /** @var float Arena bounds */
    private const ARENA_SIZE = 1000.0;

    /**
     * Create a tick handler for battle/io games.
     *
     * @return callable(GameRoom, float): void
     */
    public static function create(): callable
    {
        return function (GameRoom $room, float $deltaTime): void {
            $state = $room->getState();

            // Initialize state on first tick
            if (!isset($state['time_remaining'])) {
                self::initializeState($room);
                return;
            }

            // Update game timer
            $timeRemaining = ($state['time_remaining'] ?? 0.0) - $deltaTime;
            $room->setState('time_remaining', $timeRemaining);

            if ($timeRemaining <= 0) {
                self::endGame($room);
                return;
            }

            // Process player inputs
            self::processInputs($room, $deltaTime);
        };
    }

    /**
     * Initialize the game state.
     */
    private static function initializeState(GameRoom $room): void
    {
        $room->setState('time_remaining', self::GAME_DURATION);
        $room->setState('tick', 0);

        // Spawn players at random positions
        $positions = [];
        $scores = [];
        foreach ($room->getPlayerIds() as $playerId) {
            $positions[$playerId] = [
                'x' => mt_rand(50, (int)self::ARENA_SIZE - 50),
                'y' => mt_rand(50, (int)self::ARENA_SIZE - 50),
            ];
            $scores[$playerId] = 0;
        }

        $room->setState('positions', $positions);
        $room->setState('scores', $scores);
    }

    /**
     * Process player inputs and update positions.
     */
    private static function processInputs(GameRoom $room, float $deltaTime): void
    {
        $state = $room->getState();
        $positions = $state['positions'] ?? [];
        $scores = $state['scores'] ?? [];
        $tick = ($state['tick'] ?? 0) + 1;

        foreach ($room->getPlayerIds() as $playerId) {
            $input = $state["input:{$playerId}"] ?? null;
            if ($input === null || !is_array($input)) {
                continue;
            }

            $pos = $positions[$playerId] ?? ['x' => 500, 'y' => 500];

            // Apply movement
            $dx = (float)($input['dx'] ?? 0);
            $dy = (float)($input['dy'] ?? 0);

            // Normalize and apply speed
            $magnitude = sqrt($dx * $dx + $dy * $dy);
            if ($magnitude > 0) {
                $dx = ($dx / $magnitude) * self::MOVE_SPEED * $deltaTime;
                $dy = ($dy / $magnitude) * self::MOVE_SPEED * $deltaTime;
            }

            // Clamp to arena bounds
            $pos['x'] = max(0, min(self::ARENA_SIZE, $pos['x'] + $dx));
            $pos['y'] = max(0, min(self::ARENA_SIZE, $pos['y'] + $dy));

            $positions[$playerId] = $pos;

            // Clear processed input
            $room->setState("input:{$playerId}", null);
        }

        $room->setState('positions', $positions);
        $room->setState('scores', $scores);
        $room->setState('tick', $tick);
    }

    /**
     * End the game and determine winner.
     */
    private static function endGame(GameRoom $room): void
    {
        $scores = $room->getState()['scores'] ?? [];

        // Find winner
        $winner = null;
        $highScore = -1;
        foreach ($scores as $playerId => $score) {
            if ($score > $highScore) {
                $highScore = $score;
                $winner = $playerId;
            }
        }

        $room->setState('winner', $winner);
        $room->setState('final_scores', $scores);
        $room->end();
    }
}

