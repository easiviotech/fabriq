<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use Fabriq\Observability\Logger;
use Swoole\Timer;

/**
 * Fixed tick-rate game loop engine.
 *
 * Runs game rooms at configurable tick rates using Swoole Timer
 * (not sleep()) for precise timing. Supports multiple tick rates
 * simultaneously for different game types:
 *
 *   - Casual (10 Hz): card games, trivia, turn-based
 *   - Realtime (30 Hz): .io games, action RPGs
 *   - Competitive (60 Hz): FPS, fighting games
 *
 * Architecture:
 *   - One GameLoop per worker
 *   - Each GameLoop manages multiple GameRooms
 *   - Rooms are grouped by tick rate for efficiency
 *   - Timer fires at the fastest active tick rate
 */
final class GameLoop
{
    /** @var array<string, GameRoom> roomId → GameRoom */
    private array $rooms = [];

    /** @var array<string, int> tickType → interval in ms */
    private array $tickRates;

    /** @var array<int, int> timerId → timer handle */
    private array $timers = [];

    /** @var bool Whether the loop is running */
    private bool $running = false;

    /** @var int Sequence counter for state updates */
    private int $seq = 0;

    /** @var float Last tick timestamp (for delta calculation) */
    private float $lastTickTime = 0.0;

    public function __construct(
        private readonly UdpProtocol $protocol,
        private readonly ?Logger $logger = null,
        array $tickRates = [],
    ) {
        $this->tickRates = $tickRates ?: [
            'casual' => 100,       // 10 Hz  = 100ms
            'realtime' => 33,      // 30 Hz  = ~33ms
            'competitive' => 16,   // 60 Hz  = ~16ms
        ];
    }

    /**
     * Start the game loop.
     *
     * Creates Swoole Timers for each active tick rate.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->lastTickTime = microtime(true);

        // Start timers for each tick rate that has rooms
        foreach ($this->tickRates as $type => $intervalMs) {
            $this->timers[$intervalMs] = Timer::tick($intervalMs, function () use ($type, $intervalMs) {
                $this->tick($type, $intervalMs);
            });
        }

        $this->logger?->info('GameLoop started', [
            'tick_rates' => $this->tickRates,
        ]);
    }

    /**
     * Stop the game loop.
     */
    public function stop(): void
    {
        $this->running = false;

        foreach ($this->timers as $timerId) {
            Timer::clear($timerId);
        }
        $this->timers = [];

        $this->logger?->info('GameLoop stopped');
    }

    /**
     * Add a room to the game loop.
     */
    public function addRoom(GameRoom $room): void
    {
        $this->rooms[$room->getId()] = $room;

        $this->logger?->info('Room added to game loop', [
            'room_id' => $room->getId(),
            'tick_type' => $room->getTickType(),
        ]);
    }

    /**
     * Remove a room from the game loop.
     */
    public function removeRoom(string $roomId): void
    {
        unset($this->rooms[$roomId]);
    }

    /**
     * Get a room by ID.
     */
    public function getRoom(string $roomId): ?GameRoom
    {
        return $this->rooms[$roomId] ?? null;
    }

    /**
     * Get all active rooms.
     *
     * @return array<string, GameRoom>
     */
    public function getRooms(): array
    {
        return $this->rooms;
    }

    /**
     * Check if the loop is running.
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Get stats for monitoring.
     *
     * @return array{running: bool, rooms: int, seq: int}
     */
    public function stats(): array
    {
        return [
            'running' => $this->running,
            'rooms' => count($this->rooms),
            'seq' => $this->seq,
        ];
    }

    // ── Internal Tick ──────────────────────────────────────────────

    /**
     * Execute one tick for all rooms matching the given tick type.
     */
    private function tick(string $tickType, int $intervalMs): void
    {
        if (!$this->running) {
            return;
        }

        $now = microtime(true);
        $deltaTime = $now - $this->lastTickTime;
        $this->lastTickTime = $now;
        $this->seq++;

        foreach ($this->rooms as $roomId => $room) {
            if ($room->getTickType() !== $tickType) {
                continue;
            }

            if ($room->getStatus() !== 'playing') {
                continue;
            }

            try {
                // Run game logic
                $room->tick($deltaTime);

                // Get state deltas (only changed state)
                $deltas = $room->getDeltas();
                if (!empty($deltas)) {
                    $this->broadcastState($room, $deltas);
                }
            } catch (\Throwable $e) {
                $this->logger?->error('Game tick error', [
                    'room_id' => $roomId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Broadcast state deltas to all players in a room.
     */
    private function broadcastState(GameRoom $room, array $deltas): void
    {
        $payload = $this->protocol->stateUpdate($room->getId(), $deltas, $this->seq);

        foreach ($room->getPlayerFds() as $fd) {
            $room->sendToPlayer($fd, $payload);
        }
    }
}

