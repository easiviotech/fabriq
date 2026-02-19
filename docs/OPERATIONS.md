# Fabriq — Operations Guide

## Running Locally

### Prerequisites
- Docker + Docker Compose
- No host PHP/Swoole required (everything runs in containers)

### Start Services
```bash
cd infra
docker compose up -d
```

### Start Server
```bash
docker exec -it fabriq-app php bin/fabriq serve
```

### Start Worker
```bash
docker exec -it fabriq-app php bin/fabriq worker
```

### Start Scheduler
```bash
docker exec -it fabriq-app php bin/fabriq scheduler
```

## Health Check

```bash
curl http://localhost:8000/health
```

Response:
```json
{"status":"ok","timestamp":1708127400,"worker_id":0}
```

## Metrics

```bash
curl http://localhost:8000/metrics
```

Returns Prometheus text exposition format:
```
# HELP http_requests_total Total HTTP requests
# TYPE http_requests_total counter
http_requests_total 1523
# HELP ws_connections Active WebSocket connections
# TYPE ws_connections gauge
ws_connections 42
# HELP streams_active Currently live streams
# TYPE streams_active gauge
streams_active 3
# HELP game_rooms_active Active game rooms
# TYPE game_rooms_active gauge
game_rooms_active 12
# HELP udp_packets_total UDP packets processed
# TYPE udp_packets_total counter
udp_packets_total 98432
```

### Key Metrics

| Metric | Type | Description |
|--------|------|-------------|
| `http_requests_total` | counter | Total HTTP requests processed |
| `http_latency_ms` | summary | HTTP response latency in ms |
| `ws_connections` | gauge | Active WS connections on this worker |
| `ws_online_users` | gauge | Unique online users on this worker |
| `queue_processed_total` | counter | Jobs processed |
| `queue_lag` | gauge | Pending messages in queue |
| `db_pool_in_use` | gauge | Active DB connections |
| `db_pool_waits` | counter | Times a borrow had to wait |
| `streams_active` | gauge | Currently live streams |
| `stream_viewers_current` | gauge | Total concurrent stream viewers |
| `stream_transcodes_active` | gauge | Active FFmpeg transcoding processes |
| `game_rooms_active` | gauge | Active game rooms |
| `game_players_connected` | gauge | Connected game players |
| `game_tick_latency_ms` | histogram | Game loop tick timing |
| `udp_packets_total` | counter | UDP packets processed |
| `matchmaking_queue_size` | gauge | Players waiting for match |

## Logging

All logs are structured JSON to STDOUT (Docker captures):
```json
{
  "timestamp": "2026-02-16T23:50:00+08:00",
  "level": "INFO",
  "channel": "http",
  "message": "Request handled",
  "tenant_id": "acme",
  "correlation_id": "abc-123",
  "actor_id": "user-456",
  "request_id": "req-789"
}
```

### Log Levels
- `DEBUG` — verbose diagnostic
- `INFO` — normal operations
- `WARN` — recoverable issues
- `ERROR` — failures requiring attention

## Tracing

Trace context follows W3C `traceparent` format:
```
traceparent: 00-{trace_id}-{span_id}-01
```

Propagated across: HTTP → Job → Consumer → WS Push.
