# Fabriq — Operations Guide

## Running with Docker (Recommended)

### Prerequisites

- **Docker** (v20+) and **Docker Compose** (v2+)
- No host PHP or Swoole installation required — everything runs in containers
- On **Windows**: Docker Desktop must be running (Swoole does not run natively on Windows)

### Step 1: Install Composer Dependencies (Optional)

If you want IDE autocompletion and local tooling, install dependencies with platform checks skipped:

```bash
composer install --ignore-platform-reqs
```

This is optional — Docker will install dependencies during the image build.

### Step 2: Start the Full Stack

From the **project root** directory:

```bash
docker compose -f infra/docker-compose.yml up -d --build
```

This starts six containers:

| Container | Service | URL / Port | Notes |
|-----------|---------|------------|-------|
| `fabriq-app` | Fabriq HTTP + WS server | `http://localhost:8000` | Auto-starts `php bin/fabriq serve` |
| `fabriq-processor` | Queue/event processor | *(background)* | Auto-starts `php bin/fabriq processor`, restarts on crash |
| `fabriq-scheduler` | Cron-like job scheduler | *(background)* | Auto-starts `php bin/fabriq scheduler`, restarts on crash |
| `fabriq-mysql` | MySQL 8.0 | `localhost:3306` | Health-checked, waits ~15–30s |
| `fabriq-redis` | Redis 7 | `localhost:6379` | Health-checked |
| `fabriq-adminer` | Adminer (DB GUI) | `http://localhost:8080` | Optional, for database management |

All application containers wait for MySQL and Redis to pass their health checks before starting. The processor and scheduler have `restart: unless-stopped` for automatic crash recovery.

### Step 3: Verify

```bash
curl http://localhost:8000/health
# {"status":"ok","service":"Fabriq","timestamp":...,"worker_id":0}
```

### Step 4: Access the Database (Adminer)

Open [http://localhost:8080](http://localhost:8080) and log in:

| Field | Value |
|-------|-------|
| System | MySQL / MariaDB |
| Server | `mysql` |
| Username | `fabriq` |
| Password | `sfpass` |
| Database | `sf_platform` or `sf_app` |

> **Important:** The server name in Adminer must be `mysql` (the Docker service name), not `localhost` or `db`.

### Default Database Credentials

| Parameter | Value |
|-----------|-------|
| Host (from containers) | `mysql` |
| Host (from host machine) | `localhost` |
| Port | `3306` |
| Root password | `rootpass` |
| App username | `fabriq` |
| App password | `sfpass` |
| Platform DB | `sf_platform` |
| Application DB | `sf_app` |

### Processor & Scheduler

The processor and scheduler start automatically as separate containers. No manual commands needed.

To check their status:

```bash
docker compose -f infra/docker-compose.yml ps
```

### View Logs

```bash
# All containers
docker compose -f infra/docker-compose.yml logs -f

# Individual services
docker compose -f infra/docker-compose.yml logs -f app
docker compose -f infra/docker-compose.yml logs -f processor
docker compose -f infra/docker-compose.yml logs -f scheduler

# Last 50 lines
docker compose -f infra/docker-compose.yml logs --tail 50 app
```

### Stopping the Stack

```bash
# Stop containers (preserves data)
docker compose -f infra/docker-compose.yml down

# Stop and remove all data volumes (full reset)
docker compose -f infra/docker-compose.yml down -v
```

### Rebuilding After Code Changes

The `docker-compose.yml` mounts the project root as a volume (`..:/app`), so code changes are reflected immediately without rebuilding. If you change `composer.json` or the `Dockerfile`:

```bash
docker compose -f infra/docker-compose.yml up -d --build
```

---

## Running Locally (Linux / macOS only)

### Prerequisites

- PHP 8.2+ with the Swoole extension (≥ 5.1)
- MySQL 8.0+
- Redis 7.x
- Composer 2.x

### Start Infrastructure Only

You can use Docker for just MySQL and Redis while running the PHP server locally:

```bash
docker compose -f infra/docker-compose.yml up -d mysql redis
```

### Start Server

```bash
php bin/fabriq serve
```

### Start Processor

```bash
php bin/fabriq processor
```

### Start Scheduler

```bash
php bin/fabriq scheduler
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
