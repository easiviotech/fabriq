<?php

declare(strict_types=1);

namespace App\Gaming\Handlers;

use Fabriq\Gaming\GameRoom;

/**
 * Example game tick handler for a trivia/quiz game.
 *
 * This is a sample implementation showing how to build game logic
 * using the Fabriq gaming engine. Runs at the "casual" tick rate (10 Hz).
 *
 * Game flow:
 *   1. Host sends a question
 *   2. Players submit answers within a time limit
 *   3. Answers are revealed and scores updated
 *   4. Repeat for N rounds
 */
final class TriviaGameHandler
{
    /**
     * Create a tick handler for trivia games.
     *
     * @return callable(GameRoom, float): void
     */
    public static function create(): callable
    {
        return function (GameRoom $room, float $deltaTime): void {
            $state = $room->getState();

            $phase = $state['phase'] ?? 'waiting_question';
            $timer = ($state['timer'] ?? 0.0) - $deltaTime;
            $round = $state['round'] ?? 0;
            $maxRounds = $state['max_rounds'] ?? 10;

            match ($phase) {
                'waiting_question' => self::handleWaitingQuestion($room, $timer),
                'answering' => self::handleAnswering($room, $timer),
                'revealing' => self::handleRevealing($room, $timer, $round, $maxRounds),
                'game_over' => null, // Do nothing, game is over
                default => null,
            };
        };
    }

    private static function handleWaitingQuestion(GameRoom $room, float $timer): void
    {
        if ($timer <= 0) {
            // Auto-advance or wait for host to send question
            $room->setState('phase', 'answering');
            $room->setState('timer', 30.0); // 30 seconds to answer
            $room->setState('answers', []);
        } else {
            $room->setState('timer', $timer);
        }
    }

    private static function handleAnswering(GameRoom $room, float $timer): void
    {
        if ($timer <= 0) {
            // Time's up — reveal answers
            $room->setState('phase', 'revealing');
            $room->setState('timer', 5.0); // 5 seconds to show results
        } else {
            $room->setState('timer', $timer);

            // Check if all players have answered
            $answers = $room->getState()['answers'] ?? [];
            if (count($answers) >= $room->getPlayerCount()) {
                $room->setState('phase', 'revealing');
                $room->setState('timer', 5.0);
            }
        }
    }

    private static function handleRevealing(GameRoom $room, float $timer, int $round, int $maxRounds): void
    {
        if ($timer <= 0) {
            $newRound = $round + 1;
            $room->setState('round', $newRound);

            if ($newRound >= $maxRounds) {
                $room->setState('phase', 'game_over');
                $room->end();
            } else {
                $room->setState('phase', 'waiting_question');
                $room->setState('timer', 3.0); // 3 seconds before next question
            }
        } else {
            $room->setState('timer', $timer);
        }
    }
}

