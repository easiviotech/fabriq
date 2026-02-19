<?php

declare(strict_types=1);

namespace Fabriq\Gaming;

use MessagePack\MessagePack;

/**
 * Binary message protocol for game state.
 *
 * Supports two serialization formats:
 *   - JSON: human-readable, easier to debug
 *   - MessagePack: compact binary, ~30-40% smaller than JSON
 *
 * Used for both UDP packets and WebSocket binary frames.
 *
 * Message envelope:
 *   [type: string, room_id: string, data: mixed, seq: int, timestamp: float]
 *
 * Common message types:
 *   - state_update:  Server → clients (authoritative state)
 *   - player_input:  Client → server (player actions)
 *   - room_event:    Server → clients (join, leave, game start/end)
 *   - ping/pong:     Latency measurement
 */
final class UdpProtocol
{
    private string $format;

    public function __construct(string $format = 'msgpack')
    {
        $this->format = $format;
    }

    /**
     * Encode a game message to binary or JSON.
     *
     * @param string $type Message type
     * @param string $roomId Target room
     * @param mixed $data Message payload
     * @param int $seq Sequence number for ordering
     * @return string Encoded message
     */
    public function encode(string $type, string $roomId, mixed $data, int $seq = 0): string
    {
        $message = [
            'type' => $type,
            'room_id' => $roomId,
            'data' => $data,
            'seq' => $seq,
            'ts' => microtime(true),
        ];

        return $this->format === 'msgpack'
            ? MessagePack::pack($message)
            : json_encode($message, JSON_THROW_ON_ERROR);
    }

    /**
     * Decode a received message.
     *
     * @param string $raw Raw bytes (msgpack) or JSON string
     * @return array{type: string, room_id: string, data: mixed, seq: int, ts: float}|null
     */
    public function decode(string $raw): ?array
    {
        try {
            if ($this->format === 'msgpack') {
                $decoded = MessagePack::unpack($raw);
            } else {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            }

            if (!is_array($decoded) || !isset($decoded['type'])) {
                return null;
            }

            return [
                'type' => (string)($decoded['type'] ?? ''),
                'room_id' => (string)($decoded['room_id'] ?? ''),
                'data' => $decoded['data'] ?? null,
                'seq' => (int)($decoded['seq'] ?? 0),
                'ts' => (float)($decoded['ts'] ?? 0.0),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Create a state update message.
     */
    public function stateUpdate(string $roomId, array $state, int $seq): string
    {
        return $this->encode('state_update', $roomId, $state, $seq);
    }

    /**
     * Create a player input message.
     */
    public function playerInput(string $roomId, array $input, int $seq): string
    {
        return $this->encode('player_input', $roomId, $input, $seq);
    }

    /**
     * Create a room event message.
     */
    public function roomEvent(string $roomId, string $event, array $data = []): string
    {
        return $this->encode('room_event', $roomId, array_merge(['event' => $event], $data));
    }

    /**
     * Create a ping message.
     */
    public function ping(string $roomId): string
    {
        return $this->encode('ping', $roomId, ['sent_at' => microtime(true)]);
    }

    /**
     * Create a pong response.
     */
    public function pong(string $roomId, float $sentAt): string
    {
        return $this->encode('pong', $roomId, [
            'sent_at' => $sentAt,
            'received_at' => microtime(true),
        ]);
    }

    /**
     * Get the current serialization format.
     */
    public function getFormat(): string
    {
        return $this->format;
    }
}

