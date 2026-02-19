<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

/**
 * Server-authoritative state synchronization.
 *
 * Provides delta compression: only sends changed state to clients.
 * Maintains per-player "last seen" snapshots to compute individual
 * deltas (each player may be at a different sync point).
 *
 * Used by GameRoom to efficiently broadcast state updates.
 *
 * Sync modes:
 *   - Full sync: complete state (for new/reconnecting players)
 *   - Delta sync: only changed fields since last ack'd seq
 */
final class StateSync
{
    /** @var array<string, array<string, mixed>> playerId → last confirmed state */
    private array $playerSnapshots = [];

    /** @var array<string, int> playerId → last confirmed seq number */
    private array $playerSeqs = [];

    /**
     * Register a player for state tracking.
     */
    public function addPlayer(string $playerId): void
    {
        $this->playerSnapshots[$playerId] = [];
        $this->playerSeqs[$playerId] = 0;
    }

    /**
     * Remove a player from state tracking.
     */
    public function removePlayer(string $playerId): void
    {
        unset($this->playerSnapshots[$playerId], $this->playerSeqs[$playerId]);
    }

    /**
     * Acknowledge that a player has received state up to a given sequence number.
     *
     * @param string $playerId
     * @param int $seq Sequence number the player confirmed receiving
     * @param array<string, mixed> $currentState Current full state at that seq
     */
    public function acknowledge(string $playerId, int $seq, array $currentState): void
    {
        if (!isset($this->playerSeqs[$playerId])) {
            return;
        }

        $this->playerSeqs[$playerId] = $seq;
        $this->playerSnapshots[$playerId] = $currentState;
    }

    /**
     * Compute the delta between a player's last snapshot and the current state.
     *
     * Returns only the keys/values that have changed.
     *
     * @param string $playerId
     * @param array<string, mixed> $currentState Full current game state
     * @return array<string, mixed> Delta (changed keys only)
     */
    public function computeDelta(string $playerId, array $currentState): array
    {
        $lastSnapshot = $this->playerSnapshots[$playerId] ?? [];

        if (empty($lastSnapshot)) {
            // No previous snapshot — send full state
            return $currentState;
        }

        $delta = [];

        // Find changed or new keys
        foreach ($currentState as $key => $value) {
            if (!array_key_exists($key, $lastSnapshot) || $lastSnapshot[$key] !== $value) {
                $delta[$key] = $value;
            }
        }

        // Find removed keys
        foreach ($lastSnapshot as $key => $value) {
            if (!array_key_exists($key, $currentState)) {
                $delta[$key] = null; // null indicates removal
            }
        }

        return $delta;
    }

    /**
     * Get the full state for a player (for initial sync or reconnection).
     *
     * @param array<string, mixed> $currentState
     * @return array{type: string, state: array<string, mixed>}
     */
    public function fullSync(array $currentState): array
    {
        return [
            'type' => 'full_sync',
            'state' => $currentState,
        ];
    }

    /**
     * Get the last confirmed sequence for a player.
     */
    public function getPlayerSeq(string $playerId): int
    {
        return $this->playerSeqs[$playerId] ?? 0;
    }

    /**
     * Check if a player needs a full sync (never received any state).
     */
    public function needsFullSync(string $playerId): bool
    {
        return !isset($this->playerSnapshots[$playerId]) || empty($this->playerSnapshots[$playerId]);
    }
}

