# SwooleFabric — Idempotency & Deduplication

## Concept

Idempotency ensures that **processing the same request/job/event multiple times produces the same result** as processing it once. This is critical for:
- HTTP retries (network failures, client retries)
- Queue redeliveries (consumer crashes)
- Event replay (at-least-once delivery)

## Idempotency-Key Flow

### HTTP Requests
Client sends `Idempotency-Key` header:
```
POST /api/messages
Idempotency-Key: msg-abc-123
```
Server checks if key exists → returns cached response or processes and stores result.

### Queue Jobs
Jobs carry `idempotency_key` in their stream fields:
```
XADD sf:queue:default * type SendEmail idempotency_key "email-user-456"
```
Consumer checks before processing → skips if already processed.

### Events
Events carry `event_id` (stable across retries):
```
event_id: "msg-sent-abc-123"
event_type: "MessageSent"
```
Consumer checks dedupe store → skips duplicate event IDs.

## Storage Layers

| Layer | Backend | TTL | Purpose |
|-------|---------|-----|---------|
| Primary | Redis SETNX | 24h | Fast uniqueness check |
| Fallback | Platform DB `idempotency_keys` | 24h | Durable, survives Redis restarts |

## Key Format

```
sf:idempotency:{key}
```

Where `{key}` is the raw Idempotency-Key value (HTTP) or job/event stable ID.

## Behavior Matrix

| Scenario | First call | Second call |
|----------|-----------|-------------|
| HTTP with Idempotency-Key | Process + store response | Return cached response |
| Job with idempotency_key | Process + ack | Ack without processing |
| Event with event_id | Process + store | Skip (dedupe) |
| No key provided | Always process | Always process |

## Safety Guarantees

- SETNX ensures exactly-once semantics in Redis (single-threaded)
- TTL-based expiry prevents unbounded storage growth
- DB fallback provides durability if Redis restarts between check and store
