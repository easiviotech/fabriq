<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Realtime;

use PHPUnit\Framework\TestCase;
use Fabriq\Realtime\Gateway;

final class GatewayTest extends TestCase
{
    private Gateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new Gateway();
    }

    public function testAddAndRemoveConnection(): void
    {
        $this->gateway->addConnection(1, 'tenant-1', 'user-1');

        $meta = $this->gateway->getFdMeta(1);
        $this->assertNotNull($meta);
        $this->assertSame('tenant-1', $meta['tenant_id']);
        $this->assertSame('user-1', $meta['user_id']);

        $this->gateway->removeConnection(1);
        $this->assertNull($this->gateway->getFdMeta(1));
    }

    public function testGetFdMeta(): void
    {
        $this->assertNull($this->gateway->getFdMeta(999));

        $this->gateway->addConnection(10, 'tenant-a', 'user-x');
        $meta = $this->gateway->getFdMeta(10);

        $this->assertNotNull($meta);
        $this->assertSame('tenant-a', $meta['tenant_id']);
        $this->assertSame('user-x', $meta['user_id']);
    }

    public function testGetOnlineUsers(): void
    {
        $this->assertSame([], $this->gateway->getOnlineUsers('tenant-1'));

        $this->gateway->addConnection(1, 'tenant-1', 'user-a');
        $this->gateway->addConnection(2, 'tenant-1', 'user-b');
        $this->gateway->addConnection(3, 'tenant-2', 'user-c');

        $online = $this->gateway->getOnlineUsers('tenant-1');
        $this->assertCount(2, $online);
        $this->assertContains('user-a', $online);
        $this->assertContains('user-b', $online);

        $this->assertSame(['user-c'], $this->gateway->getOnlineUsers('tenant-2'));
    }

    public function testJoinAndLeaveRoom(): void
    {
        $this->gateway->addConnection(1, 'tenant-1', 'user-1');
        $this->gateway->addConnection(2, 'tenant-1', 'user-2');

        $this->gateway->joinRoom(1, 'tenant-1', 'room-a');
        $this->gateway->joinRoom(2, 'tenant-1', 'room-a');

        $fds = $this->gateway->getRoomFds('tenant-1', 'room-a');
        $this->assertCount(2, $fds);
        $this->assertContains(1, $fds);
        $this->assertContains(2, $fds);

        $this->gateway->leaveRoom(1, 'tenant-1', 'room-a');
        $fds = $this->gateway->getRoomFds('tenant-1', 'room-a');
        $this->assertSame([2], $fds);
    }

    public function testGetRoomFds(): void
    {
        $this->assertSame([], $this->gateway->getRoomFds('tenant-1', 'room-x'));

        $this->gateway->addConnection(5, 'tenant-1', 'user-1');
        $this->gateway->joinRoom(5, 'tenant-1', 'room-x');

        $this->assertSame([5], $this->gateway->getRoomFds('tenant-1', 'room-x'));
    }

    public function testRemoveConnectionCleansUpRooms(): void
    {
        $this->gateway->addConnection(1, 'tenant-1', 'user-1');
        $this->gateway->joinRoom(1, 'tenant-1', 'room-a');
        $this->gateway->joinRoom(1, 'tenant-1', 'room-b');

        $this->assertSame([1], $this->gateway->getRoomFds('tenant-1', 'room-a'));
        $this->assertSame([1], $this->gateway->getRoomFds('tenant-1', 'room-b'));

        $this->gateway->removeConnection(1);

        $this->assertSame([], $this->gateway->getRoomFds('tenant-1', 'room-a'));
        $this->assertSame([], $this->gateway->getRoomFds('tenant-1', 'room-b'));
        $this->assertNull($this->gateway->getFdMeta(1));
    }

    public function testStats(): void
    {
        $stats = $this->gateway->stats();
        $this->assertSame(0, $stats['total_connections']);
        $this->assertSame(0, $stats['tenants']);
        $this->assertSame(0, $stats['rooms']);

        $this->gateway->addConnection(1, 'tenant-1', 'user-1');
        $this->gateway->addConnection(2, 'tenant-1', 'user-2');
        $this->gateway->addConnection(3, 'tenant-2', 'user-3');
        $this->gateway->joinRoom(1, 'tenant-1', 'room-a');
        $this->gateway->joinRoom(3, 'tenant-2', 'room-b');

        $stats = $this->gateway->stats();
        $this->assertSame(3, $stats['total_connections']);
        $this->assertSame(2, $stats['tenants']);
        $this->assertSame(2, $stats['rooms']);
    }
}
