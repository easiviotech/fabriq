# Fabriq — Realtime Fabric

## Architecture

The Realtime Fabric separates **connection management** (Gateway) from **business logic** (app workers), using Redis Pub/Sub for cross-worker message delivery.

```
Business Worker → Redis PUBLISH → Gateway Worker → WebSocket Push
```

**No FD passing between workers.** Each worker maintains its own fd→user mappings.

## Components

| Component | Role |
|-----------|------|
| `Gateway` | Per-worker fd→user→room maps, local push |
| `Presence` | Redis sorted set heartbeats, online user tracking |
| `PushService` | Publishes to Redis Pub/Sub (user/room/topic) |
| `RealtimeSubscriber` | Subscribes to Redis channels, fans out to local fds |
| `WsAuthHandler` | Token validation on WS handshake |

## Push API

```php
$push->pushUser($tenantId, $userId, ['type' => 'notification', 'body' => '...']);
$push->pushRoom($tenantId, $roomId, ['type' => 'message', 'body' => '...']);
$push->pushTopic($tenantId, 'announcements', ['type' => 'alert', 'body' => '...']);
```

> **Note:** The `PushService` is also used by the **Live Streaming** package (`SignalingHandler`) to relay WebRTC SDP offers/answers and ICE candidates between streamer and viewers, and by the **Game Server** package (`GameRoomManager`) for cross-worker room coordination.

## Redis Channel Naming

| Pattern | Example |
|---------|---------|
| `sf:push:user:{tenant}:{user}` | `sf:push:user:acme:u-123` |
| `sf:push:room:{tenant}:{room}` | `sf:push:room:acme:r-456` |
| `sf:push:topic:{tenant}:{topic}` | `sf:push:topic:acme:announcements` |

## WebSocket Auth Flow

1. Client connects: `ws://host:8000/?token=JWT`
2. Server validates token in `onOpen`
3. On success: registers fd in Gateway + Presence heartbeat
4. Alternative: connect without token, send `{"type":"auth","token":"..."}`

## Cross-Worker Routing

With N workers, a user's WS might connect to Worker 0. When Worker 1 handles an HTTP request that triggers a push:

1. Worker 1 calls `PushService::pushRoom()` → Redis PUBLISH
2. All gateway workers receive the pub/sub message
3. Each worker checks its local Gateway for matching fds
4. Only the worker holding the user's fd actually sends the push

## Presence

Uses Redis sorted sets with heartbeat scores:

```
ZADD sf:presence:acme <timestamp> user-123
```

Users with scores older than TTL (60s) are considered offline.
Online users queried via `ZRANGEBYSCORE sf:presence:acme <now-ttl> +inf`.
