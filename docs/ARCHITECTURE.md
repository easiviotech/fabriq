# Fabriq — System Architecture

## Overview

Fabriq is a unified Swoole runtime that consolidates:
- **HTTP API server** (REST endpoints)
- **WebSocket gateway** (realtime push/presence)
- **Queue workers** (Redis Streams)
- **Event consumers** (publish/subscribe with dedupe)
- **Live streaming** (WebRTC signaling, FFmpeg RTMP→HLS transcoding, viewer tracking, chat moderation)
- **Game server** (fixed tick-rate game loop, UDP protocol with MessagePack, Redis ZSET matchmaking, lobbies, delta state sync)

into a single deployable process.

## Component Diagram

```mermaid
graph TD
    subgraph "Swoole Server"
        HTTP["HTTP Handler"] --> MW["Middleware Chain"]
        WS["WS Handler"] --> GW["Gateway"]
        UDP["UDP Handler"] --> UdpProto["UdpProtocol"]
        MW --> Router["Router"]
        Router --> Handlers["Route Handlers"]
        Handlers --> DB["DbManager"]
        Handlers --> EventBus["EventBus"]
        Handlers --> Push["PushService"]
        Signaling["SignalingHandler"] --> Push
        Transcode["TranscodingPipeline"] --> FFmpeg["FFmpeg Process"]
        HLS["HlsManager"] --> Router
        GameLoop["GameLoop"] --> GameRooms["GameRoom(s)"]
        UdpProto --> GameRooms
        Matchmaker["Matchmaker"]
    end

    subgraph "Workers"
        Consumer["Queue Consumer"]
        EventConsumer["Event Consumer"]
        Scheduler["Scheduler"]
    end

    subgraph "Infrastructure"
        MySQL["MySQL 8"]
        Redis["Redis 7"]
    end

    DB --> MySQL
    GW --> Redis
    Push --> Redis
    EventBus --> Redis
    Consumer --> Redis
    EventConsumer --> Redis
    Scheduler --> Redis
    Matchmaker --> Redis
    Signaling --> Redis
```

## Data Flow: Send Message

```
1. HTTP POST /api/rooms/{id}/messages
   → AuthMiddleware → TenancyMiddleware → Handler
2. Handler persists message (MySQL, tenant_id enforced)
3. Handler emits MessageSent event (Redis Stream)
4. Handler calls PushService.pushRoom() (Redis PUBLISH)
5. Gateway workers receive pub/sub → push to local WS fds
6. Event consumer reads MessageSent → updates projections
```

## Tenancy Enforcement

Every execution path requires TenantContext:

| Path | Where tenant is set |
|------|-------------------|
| HTTP | TenancyMiddleware (host/header/JWT) |
| WebSocket | WsAuthHandler (JWT token) |
| Queue Job | Context restored from job fields |
| Event Consumer | Context restored from event fields |
| Live Streaming | StreamManager carries `tenant_id`; signaling/chat messages are tenant-scoped |
| Game Server | GameRoom and Matchmaker carry `tenant_id`; UDP packets include room context |

## Long-Running Safety

| Rule | Implementation |
|------|---------------|
| No global mutable state | `Context::reset()` per request |
| No stored connections | Borrow → use → release in same coroutine |
| Per-worker pools | Initialized in `onWorkerStart` |
| Health checks | `SELECT 1` on every pool borrow |
| Bounded pools | `ConnectionPool` with `max_size` via Channel |
