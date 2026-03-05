# Fabriq HTTP Benchmarks

Measures raw HTTP throughput and latency of the Fabriq platform under varying concurrency levels.

## Prerequisites

- PHP 8.2+ with Swoole extension
- A running Fabriq server

## Running

```bash
# Start the server in one terminal
php bin/fabriq serve

# Run benchmarks in another terminal
php benchmarks/run.php
```

## Options

| Flag | Default | Description |
|------|---------|-------------|
| `--host` | `127.0.0.1` | Server host |
| `--port` | `8080` | Server port |
| `--path` | `/` | Request path |
| `--requests` | `1000` | Total requests per concurrency level |
| `--concurrency` | `10,50,100` | Comma-separated concurrency levels |

## Example

```bash
php benchmarks/run.php --requests=5000 --concurrency=10,50,100,500
```

## What's Measured

- **Requests/sec** — total throughput
- **Latency percentiles** — p50, p95, p99 in milliseconds
- **Min/Avg/Max latency**
- **Error rate** — failed connections
- **Peak memory** — PHP process memory usage

## Results

Results are saved to `benchmarks/results.json` after each run. Compare across commits to track performance regressions.

```json
{
  "timestamp": "2026-03-06T...",
  "php_version": "8.2.x",
  "swoole_version": "5.x.x",
  "results": [
    {
      "concurrency": 10,
      "requests_per_second": 12500.0,
      "latency_ms": { "p50": 0.8, "p95": 1.5, "p99": 3.2 }
    }
  ]
}
```
