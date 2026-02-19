<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use Fabriq\Events\EventSchema;

/**
 * Tests for IdempotencyStore and EventSchema.
 *
 * Note: Full IdempotencyStore tests require Redis/MySQL connections.
 * These tests verify the EventSchema value object and idempotency concepts.
 */
final class IdempotencyStoreTest extends TestCase
{
    // ── EventSchema Tests ────────────────────────────────────────────

    public function testEventSchemaCreate(): void
    {
        $event = EventSchema::create(
            eventType: 'message.sent',
            tenantId: 'tid-001',
            payload: ['room_id' => 'r1', 'body' => 'Hello'],
            dedupeKey: 'dedupe-123',
            correlationId: 'corr-456',
            actorId: 'user-789',
        );

        $this->assertSame('message.sent', $event->eventType);
        $this->assertSame('tid-001', $event->tenantId);
        $this->assertSame('Hello', $event->payload['body']);
        $this->assertSame('dedupe-123', $event->dedupeKey);
    }

    public function testEventSchemaAutoGeneratesDedupeKey(): void
    {
        $event = EventSchema::create(
            eventType: 'test.event',
            tenantId: 'tid-001',
            payload: [],
        );

        $this->assertNotEmpty($event->dedupeKey);
        $this->assertSame(32, strlen($event->dedupeKey)); // hex of 16 bytes
    }

    public function testEventSchemaRoundTrip(): void
    {
        $original = EventSchema::create(
            eventType: 'user.created',
            tenantId: 'tid-002',
            payload: ['name' => 'Alice', 'email' => 'alice@example.com'],
            dedupeKey: 'dk-100',
            correlationId: 'corr-200',
            actorId: 'admin-1',
        );

        $fields = $original->toStreamFields();
        $restored = EventSchema::fromStreamFields($fields);

        $this->assertSame($original->eventType, $restored->eventType);
        $this->assertSame($original->tenantId, $restored->tenantId);
        $this->assertSame($original->payload, $restored->payload);
        $this->assertSame($original->dedupeKey, $restored->dedupeKey);
        $this->assertSame($original->correlationId, $restored->correlationId);
        $this->assertSame($original->actorId, $restored->actorId);
    }

    public function testEventSchemaStreamFieldsAreAllStrings(): void
    {
        $event = EventSchema::create(
            eventType: 'test',
            tenantId: 'tid',
            payload: ['num' => 42],
        );

        $fields = $event->toStreamFields();

        foreach ($fields as $key => $value) {
            $this->assertIsString($value, "Field '{$key}' should be a string for Redis streams");
        }
    }

    // ── Idempotency Concept Tests ────────────────────────────────────

    public function testIdempotencyKeyUniqueness(): void
    {
        // Verify that two events with different dedupe keys are distinguishable
        $event1 = EventSchema::create('test', 'tid', ['a' => 1]);
        $event2 = EventSchema::create('test', 'tid', ['a' => 1]);

        // Auto-generated dedupe keys should be unique
        $this->assertNotSame($event1->dedupeKey, $event2->dedupeKey);
    }

    public function testIdempotencyKeyStableWhenProvided(): void
    {
        $event1 = EventSchema::create('test', 'tid', ['a' => 1], dedupeKey: 'stable-key');
        $event2 = EventSchema::create('test', 'tid', ['a' => 1], dedupeKey: 'stable-key');

        $this->assertSame($event1->dedupeKey, $event2->dedupeKey);
    }
}

