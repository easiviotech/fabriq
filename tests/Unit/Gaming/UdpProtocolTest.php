<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Gaming;

use Fabriq\Gaming\UdpProtocol;
use PHPUnit\Framework\TestCase;

final class UdpProtocolTest extends TestCase
{
    private UdpProtocol $protocol;

    protected function setUp(): void
    {
        $this->protocol = new UdpProtocol('json');
    }

    public function testJsonEncodeDecode(): void
    {
        $encoded = $this->protocol->encode('state_update', 'room-1', ['hp' => 100], 1);
        $decoded = $this->protocol->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame('state_update', $decoded['type']);
        $this->assertSame('room-1', $decoded['room_id']);
        $this->assertSame(['hp' => 100], $decoded['data']);
        $this->assertSame(1, $decoded['seq']);
        $this->assertIsFloat($decoded['ts']);
    }

    public function testDecodeInvalidJson(): void
    {
        $this->assertNull($this->protocol->decode('{not valid json'));
    }

    public function testDecodeWithMissingType(): void
    {
        $this->assertNull($this->protocol->decode(json_encode(['room_id' => 'r1'])));
    }

    public function testStateUpdate(): void
    {
        $encoded = $this->protocol->stateUpdate('room-1', ['x' => 10, 'y' => 20], 5);
        $decoded = $this->protocol->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame('state_update', $decoded['type']);
        $this->assertSame('room-1', $decoded['room_id']);
        $this->assertSame(['x' => 10, 'y' => 20], $decoded['data']);
        $this->assertSame(5, $decoded['seq']);
    }

    public function testPlayerInput(): void
    {
        $encoded = $this->protocol->playerInput('room-2', ['action' => 'jump'], 3);
        $decoded = $this->protocol->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame('player_input', $decoded['type']);
        $this->assertSame('room-2', $decoded['room_id']);
        $this->assertSame(['action' => 'jump'], $decoded['data']);
        $this->assertSame(3, $decoded['seq']);
    }

    public function testRoomEvent(): void
    {
        $encoded = $this->protocol->roomEvent('room-3', 'player_joined', ['player_id' => 'p1']);
        $decoded = $this->protocol->decode($encoded);

        $this->assertNotNull($decoded);
        $this->assertSame('room_event', $decoded['type']);
        $this->assertSame('room-3', $decoded['room_id']);
        $this->assertSame('player_joined', $decoded['data']['event']);
        $this->assertSame('p1', $decoded['data']['player_id']);
    }

    public function testPingPong(): void
    {
        $pingEncoded = $this->protocol->ping('room-1');
        $pingDecoded = $this->protocol->decode($pingEncoded);

        $this->assertNotNull($pingDecoded);
        $this->assertSame('ping', $pingDecoded['type']);
        $this->assertArrayHasKey('sent_at', $pingDecoded['data']);

        $sentAt = $pingDecoded['data']['sent_at'];
        $pongEncoded = $this->protocol->pong('room-1', $sentAt);
        $pongDecoded = $this->protocol->decode($pongEncoded);

        $this->assertNotNull($pongDecoded);
        $this->assertSame('pong', $pongDecoded['type']);
        $this->assertSame($sentAt, $pongDecoded['data']['sent_at']);
        $this->assertArrayHasKey('received_at', $pongDecoded['data']);
    }

    public function testGetFormat(): void
    {
        $this->assertSame('json', $this->protocol->getFormat());

        $defaultProtocol = new UdpProtocol();
        $this->assertSame('msgpack', $defaultProtocol->getFormat());
    }
}
