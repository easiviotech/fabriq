# SwooleFabric — Operations Guide

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
docker exec -it sf-app php bin/swoolefabric serve
```

### Start Worker
```bash
docker exec -it sf-app php bin/swoolefabric worker
```

### Start Scheduler
```bash
docker exec -it sf-app php bin/swoolefabric scheduler
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
