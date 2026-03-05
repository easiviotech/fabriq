<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Gaming;

use PHPUnit\Framework\TestCase;
use Fabriq\Gaming\StateSync;

final class StateSyncTest extends TestCase
{
    public function testAddAndRemovePlayer(): void
    {
        $sync = new StateSync();

        $sync->addPlayer('p1');
        $this->assertTrue($sync->needsFullSync('p1'));

        $sync->removePlayer('p1');
        $this->assertSame(0, $sync->getPlayerSeq('p1'));
        $this->assertTrue($sync->needsFullSync('p1'));
    }

    public function testComputeDeltaFullStateOnFirstSync(): void
    {
        $sync = new StateSync();
        $sync->addPlayer('p1');

        $state = ['hp' => 100, 'x' => 10, 'y' => 20];
        $delta = $sync->computeDelta('p1', $state);

        $this->assertSame($state, $delta);
    }

    public function testComputeDeltaOnlyChangedKeys(): void
    {
        $sync = new StateSync();
        $sync->addPlayer('p1');

        $initial = ['hp' => 100, 'x' => 10, 'y' => 20];
        $sync->acknowledge('p1', 1, $initial);

        $updated = ['hp' => 80, 'x' => 10, 'y' => 20];
        $delta = $sync->computeDelta('p1', $updated);

        $this->assertSame(['hp' => 80], $delta);
    }

    public function testComputeDeltaDetectsRemovedKeys(): void
    {
        $sync = new StateSync();
        $sync->addPlayer('p1');

        $sync->acknowledge('p1', 1, ['hp' => 100, 'shield' => 50]);

        $delta = $sync->computeDelta('p1', ['hp' => 100]);

        $this->assertArrayHasKey('shield', $delta);
        $this->assertNull($delta['shield']);
    }

    public function testComputeDeltaEmptyWhenNoChange(): void
    {
        $sync = new StateSync();
        $sync->addPlayer('p1');

        $state = ['hp' => 100, 'x' => 5];
        $sync->acknowledge('p1', 1, $state);

        $delta = $sync->computeDelta('p1', $state);

        $this->assertEmpty($delta);
    }

    public function testAcknowledge(): void
    {
        $sync = new StateSync();
        $sync->addPlayer('p1');

        $sync->acknowledge('p1', 5, ['hp' => 90]);

        $this->assertSame(5, $sync->getPlayerSeq('p1'));
        $this->assertFalse($sync->needsFullSync('p1'));
    }

    public function testGetPlayerSeq(): void
    {
        $sync = new StateSync();

        $this->assertSame(0, $sync->getPlayerSeq('unknown'));

        $sync->addPlayer('p1');
        $this->assertSame(0, $sync->getPlayerSeq('p1'));

        $sync->acknowledge('p1', 3, []);
        $this->assertSame(3, $sync->getPlayerSeq('p1'));
    }

    public function testNeedsFullSync(): void
    {
        $sync = new StateSync();

        $this->assertTrue($sync->needsFullSync('new_player'));

        $sync->addPlayer('p1');
        $this->assertTrue($sync->needsFullSync('p1'));

        $sync->acknowledge('p1', 1, ['hp' => 100]);
        $this->assertFalse($sync->needsFullSync('p1'));
    }

    public function testFullSync(): void
    {
        $sync = new StateSync();
        $state = ['hp' => 100, 'x' => 0, 'y' => 0];

        $result = $sync->fullSync($state);

        $this->assertSame('full_sync', $result['type']);
        $this->assertSame($state, $result['state']);
    }
}
