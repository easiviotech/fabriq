# Fabriq — Production Deployment Guide

> Deploy Fabriq as a set of Docker containers with external MySQL and Redis.

---

## Table of Contents

1. [Overview](#overview)
2. [Process Types](#process-types)
3. [Production Dockerfile](#production-dockerfile)
4. [Environment Configuration](#environment-configuration)
5. [Production Docker Compose](#production-docker-compose)
6. [Reverse Proxy & TLS](#reverse-proxy--tls)
7. [Cloud Deployment Options](#cloud-deployment-options)
8. [Connection Pool Sizing](#connection-pool-sizing)
9. [Production Checklist](#production-checklist)

---

## Overview

Fabriq is a **long-running Swoole process** — not traditional PHP-FPM. You do **not** deploy it behind Apache or Nginx+FPM. Instead, you run it as Docker containers (or any process supervisor), with a reverse proxy in front for TLS termination.

The same Docker image is used for all process types — only the startup command changes.

---

## Process Types

Fabriq requires **three process types** running in production:

| Process | Command | Scaling | Purpose |
|---------|---------|---------|---------|
| **Web** | `php bin/fabriq serve` | Horizontal (multiple replicas behind load balancer) | HTTP API + WebSocket + UDP |
| **Processor** | `php bin/fabriq processor` | Scale by queue depth (1–N replicas) | Queue consumers + event consumers |
| **Scheduler** | `php bin/fabriq scheduler` | **Exactly 1 instance** | Recurring job dispatch + delayed job promotion |

> **Warning:** Running more than one scheduler instance will cause duplicate job dispatches.

---

## Production Dockerfile

The included `infra/Dockerfile` works for development. For production, apply these optimizations:

```dockerfile
FROM php:8.3-cli

# System dependencies (no git in production)
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev libssl-dev unzip \
    && rm -rf /var/lib/apt/lists/*

# Swoole + PDO MySQL + Redis extensions
RUN pecl install swoole-5.1.5 \
    && docker-php-ext-enable swoole
RUN docker-php-ext-install pdo pdo_mysql
RUN pecl install redis \
    && docker-php-ext-enable redis

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Layer-cached dependency install (no dev dependencies)
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader

# Copy application source
COPY . .

# Re-dump optimized autoloader with full source
RUN composer dump-autoload --optimize --no-dev

EXPOSE 8000

CMD ["php", "bin/fabriq", "serve"]
```

### Key Production Differences

| Aspect | Development | Production |
|--------|------------|------------|
| Composer flags | `--no-interaction --prefer-dist` | `--no-interaction --no-dev --prefer-dist --optimize-autoloader` |
| Volume mounts | `..:/app` (live code reload) | None (code baked into image) |
| Dev dependencies | Included (PHPUnit, etc.) | Excluded |
| Autoloader | Standard PSR-4 | Optimized classmap |

---

## Environment Configuration

All sensitive values and environment-specific settings should be provided via environment variables. **Never hardcode credentials in config files for production.**

### Config Files with Environment Variables

Update the config files to read from environment variables with local defaults:

#### `config/server.php`

```php
return [
    'host'         => '0.0.0.0',
    'port'         => (int) (getenv('SWOOLE_PORT') ?: 8000),
    'workers'      => (int) (getenv('SWOOLE_WORKERS') ?: swoole_cpu_num()),
    'task_workers' => (int) (getenv('SWOOLE_TASK_WORKERS') ?: 2),
    'log_level'    => (int) (getenv('SWOOLE_LOG_LEVEL') ?: 4),
];
```

#### `config/database.php`

```php
return [
    'platform' => [
        'host'     => getenv('DB_HOST') ?: 'mysql',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_PLATFORM_NAME') ?: 'sf_platform',
        'username' => getenv('DB_USERNAME') ?: 'fabriq',
        'password' => getenv('DB_PASSWORD') ?: 'sfpass',
        'charset'  => 'utf8mb4',
        'pool'     => [
            'max_size'       => (int) (getenv('DB_POOL_MAX') ?: 20),
            'borrow_timeout' => 3.0,
            'idle_timeout'   => 60.0,
        ],
    ],
    'app' => [
        'host'     => getenv('DB_HOST') ?: 'mysql',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_APP_NAME') ?: 'sf_app',
        'username' => getenv('DB_USERNAME') ?: 'fabriq',
        'password' => getenv('DB_PASSWORD') ?: 'sfpass',
        'charset'  => 'utf8mb4',
        'pool'     => [
            'max_size'       => (int) (getenv('DB_POOL_MAX') ?: 20),
            'borrow_timeout' => 3.0,
            'idle_timeout'   => 60.0,
        ],
    ],
];
```

#### `config/redis.php`

```php
return [
    'host'     => getenv('REDIS_HOST') ?: 'redis',
    'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
    'password' => getenv('REDIS_PASSWORD') ?: '',
    'database' => (int) (getenv('REDIS_DB') ?: 0),
    'pool'     => [
        'max_size'       => (int) (getenv('REDIS_POOL_MAX') ?: 20),
        'borrow_timeout' => 3.0,
        'idle_timeout'   => 60.0,
    ],
];
```

#### `config/auth.php`

```php
return [
    'jwt' => [
        'secret'    => getenv('JWT_SECRET') ?: '',
        'algorithm' => 'HS256',
        'ttl'       => (int) (getenv('JWT_TTL') ?: 3600),
    ],
    // ... roles config stays the same
];
```

### Environment Variables Reference

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DB_HOST` | Yes | `mysql` | MySQL hostname |
| `DB_PORT` | No | `3306` | MySQL port |
| `DB_USERNAME` | Yes | `fabriq` | MySQL username |
| `DB_PASSWORD` | Yes | `sfpass` | MySQL password |
| `DB_PLATFORM_NAME` | No | `sf_platform` | Platform database name |
| `DB_APP_NAME` | No | `sf_app` | Application database name |
| `DB_POOL_MAX` | No | `20` | Max connections per pool per worker |
| `REDIS_HOST` | Yes | `redis` | Redis hostname |
| `REDIS_PORT` | No | `6379` | Redis port |
| `REDIS_PASSWORD` | No | *(empty)* | Redis password (strongly recommended) |
| `REDIS_POOL_MAX` | No | `20` | Max Redis connections per pool per worker |
| `JWT_SECRET` | Yes | *(empty)* | JWT signing secret (min 32 chars) |
| `JWT_TTL` | No | `3600` | JWT token TTL in seconds |
| `SWOOLE_WORKERS` | No | CPU count | Number of Swoole worker processes |
| `SWOOLE_TASK_WORKERS` | No | `2` | Number of task worker processes |
| `SWOOLE_LOG_LEVEL` | No | `4` | Swoole log level (4 = WARNING) |

---

## Production Docker Compose

Create `infra/docker-compose.prod.yml` for production deployment:

```yaml
services:
  web:
    image: ${REGISTRY:-fabriq}:${TAG:-latest}
    command: ["php", "bin/fabriq", "serve"]
    ports:
      - "8000:8000"
    environment:
      - DB_HOST=${DB_HOST}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_POOL_MAX=${DB_POOL_MAX:-30}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - JWT_SECRET=${JWT_SECRET}
      - SWOOLE_WORKERS=${SWOOLE_WORKERS:-8}
    deploy:
      replicas: 3
      restart_policy:
        condition: on-failure
        delay: 5s
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8000/health"]
      interval: 15s
      timeout: 5s
      retries: 3
    networks:
      - sf-net

  processor:
    image: ${REGISTRY:-fabriq}:${TAG:-latest}
    command: ["php", "bin/fabriq", "processor"]
    environment:
      - DB_HOST=${DB_HOST}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - DB_POOL_MAX=${DB_POOL_MAX:-30}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
    deploy:
      replicas: 2
      restart_policy:
        condition: on-failure
        delay: 5s
    networks:
      - sf-net

  scheduler:
    image: ${REGISTRY:-fabriq}:${TAG:-latest}
    command: ["php", "bin/fabriq", "scheduler"]
    environment:
      - DB_HOST=${DB_HOST}
      - DB_USERNAME=${DB_USERNAME}
      - DB_PASSWORD=${DB_PASSWORD}
      - REDIS_HOST=${REDIS_HOST}
      - REDIS_PASSWORD=${REDIS_PASSWORD}
    deploy:
      replicas: 1  # MUST be exactly 1
      restart_policy:
        condition: on-failure
    networks:
      - sf-net

networks:
  sf-net:
    driver: bridge
```

> **Note:** This compose file assumes MySQL and Redis are managed externally (e.g., AWS RDS, ElastiCache). If you need to run them as containers, add `mysql` and `redis` services similar to the development `docker-compose.yml`.

### Build & Deploy

```bash
# Build the production image
docker build -f infra/Dockerfile -t your-registry.com/fabriq:1.0.0 .

# Push to your container registry
docker push your-registry.com/fabriq:1.0.0

# Deploy on the production server
REGISTRY=your-registry.com/fabriq TAG=1.0.0 \
  docker compose -f infra/docker-compose.prod.yml --env-file .env.production up -d
```

### Example `.env.production`

```bash
# Docker image
REGISTRY=your-registry.com/fabriq
TAG=1.0.0

# Database (e.g., AWS RDS)
DB_HOST=your-rds-instance.region.rds.amazonaws.com
DB_USERNAME=fabriq_prod
DB_PASSWORD=strong-random-password-here
DB_POOL_MAX=30

# Redis (e.g., AWS ElastiCache)
REDIS_HOST=your-elasticache-cluster.region.cache.amazonaws.com
REDIS_PASSWORD=redis-auth-token-here
REDIS_POOL_MAX=30

# Auth
JWT_SECRET=your-256-bit-random-secret-minimum-32-characters
JWT_TTL=3600

# Swoole
SWOOLE_WORKERS=8
SWOOLE_TASK_WORKERS=4
```

> **Never commit `.env.production` to Git.** Use your CI/CD platform's secret management (GitHub Secrets, AWS Parameter Store, HashiCorp Vault, etc.).

---

## Reverse Proxy & TLS

Fabriq serves HTTP and WebSocket on port 8000. In production, place a reverse proxy in front for TLS termination, load balancing, and HTTP/2.

### Nginx

```nginx
upstream fabriq_web {
    server 127.0.0.1:8000;
    # Add more backends if running multiple host-mapped ports:
    # server 127.0.0.1:8001;
    # server 127.0.0.1:8002;
}

server {
    listen 443 ssl http2;
    server_name api.yourdomain.com;

    ssl_certificate     /etc/ssl/certs/yourdomain.crt;
    ssl_certificate_key /etc/ssl/private/yourdomain.key;

    # HTTP API requests
    location / {
        proxy_pass http://fabriq_web;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_buffering off;
    }

    # WebSocket upgrade
    location /ws {
        proxy_pass http://fabriq_web;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_read_timeout 86400;
    }
}

# Redirect HTTP to HTTPS
server {
    listen 80;
    server_name api.yourdomain.com;
    return 301 https://$server_name$request_uri;
}
```

### Caddy (Automatic TLS)

```
api.yourdomain.com {
    reverse_proxy localhost:8000
}
```

Caddy automatically provisions and renews Let's Encrypt TLS certificates.

### AWS Application Load Balancer

1. Create a **Target Group** pointing to port 8000 on your ECS tasks / EC2 instances
2. Configure the **health check** path as `/health`
3. Create an **ALB** listener on port 443 with your ACM certificate
4. Enable **sticky sessions** if your WebSocket clients don't include auth tokens on every message
5. Set the **idle timeout** to at least 3600 seconds for WebSocket connections

---

## Cloud Deployment Options

The same Docker image runs on any cloud platform:

| Platform | Web | Processor | Scheduler | Database | Redis |
|----------|-----|--------|-----------|----------|-------|
| **AWS** | ECS/Fargate + ALB | ECS Service (auto-scale) | ECS (1 task) | RDS MySQL 8.0 | ElastiCache Redis 7 |
| **GCP** | Cloud Run / GKE | GKE Deployment | GKE (1 replica) | Cloud SQL MySQL | Memorystore Redis |
| **Azure** | Container Apps / AKS | AKS Deployment | AKS (1 replica) | Azure Database for MySQL | Azure Cache for Redis |
| **DigitalOcean** | App Platform / Droplet | Droplet | Droplet | Managed MySQL | Managed Redis |
| **Self-hosted** | Docker Compose / K8s | Docker Compose / K8s | Docker Compose / K8s | MySQL 8.0 | Redis 7 |

### Kubernetes Example

```yaml
# web-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fabriq-web
spec:
  replicas: 3
  selector:
    matchLabels:
      app: fabriq
      role: web
  template:
    metadata:
      labels:
        app: fabriq
        role: web
    spec:
      containers:
        - name: fabriq
          image: your-registry.com/fabriq:1.0.0
          command: ["php", "bin/fabriq", "serve"]
          ports:
            - containerPort: 8000
          envFrom:
            - secretRef:
                name: fabriq-secrets
          livenessProbe:
            httpGet:
              path: /health
              port: 8000
            initialDelaySeconds: 10
            periodSeconds: 15
          readinessProbe:
            httpGet:
              path: /health
              port: 8000
            initialDelaySeconds: 5
            periodSeconds: 10
          resources:
            requests:
              cpu: "500m"
              memory: "256Mi"
            limits:
              cpu: "2000m"
              memory: "512Mi"
---
# processor-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fabriq-processor
spec:
  replicas: 2
  selector:
    matchLabels:
      app: fabriq
      role: processor
  template:
    metadata:
      labels:
        app: fabriq
        role: processor
    spec:
      containers:
        - name: fabriq
          image: your-registry.com/fabriq:1.0.0
          command: ["php", "bin/fabriq", "processor"]
          envFrom:
            - secretRef:
                name: fabriq-secrets
          resources:
            requests:
              cpu: "250m"
              memory: "128Mi"
            limits:
              cpu: "1000m"
              memory: "256Mi"
---
# scheduler-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: fabriq-scheduler
spec:
  replicas: 1  # MUST be exactly 1
  strategy:
    type: Recreate  # Ensure only 1 instance during updates
  selector:
    matchLabels:
      app: fabriq
      role: scheduler
  template:
    metadata:
      labels:
        app: fabriq
        role: scheduler
    spec:
      containers:
        - name: fabriq
          image: your-registry.com/fabriq:1.0.0
          command: ["php", "bin/fabriq", "scheduler"]
          envFrom:
            - secretRef:
                name: fabriq-secrets
          resources:
            requests:
              cpu: "100m"
              memory: "64Mi"
            limits:
              cpu: "500m"
              memory: "128Mi"
```

---

## Connection Pool Sizing

Each Swoole worker maintains its own connection pools. The total max connections depend on the number of workers and replicas:

```
Total MySQL connections = replicas × workers_per_replica × pool_max_size

Example (production):
  Web:       3 replicas × 8 workers × 30 pool_max =  720 connections
  Processor: 2 replicas × 1 process × 30 pool_max =   60 connections
  Scheduler: 1 replica  × 1 worker  × 30 pool_max =   30 connections
                                           Total: ~810 max connections
```

### MySQL Tuning

Ensure your MySQL `max_connections` is higher than your total max pool size:

```sql
-- Check current setting
SHOW VARIABLES LIKE 'max_connections';

-- Set to accommodate all pools + some headroom
SET GLOBAL max_connections = 1000;
```

For RDS, configure `max_connections` via a parameter group (default is based on instance class).

### Redis Tuning

Redis default `maxclients` is 10,000 — usually sufficient. For ElastiCache, this is managed automatically.

### Recommended Pool Sizes by Scale

| Scale | Workers | DB Pool Max | Redis Pool Max | Total DB Conns (3 web + 2 processor + 1 sched) |
|-------|---------|-------------|----------------|----------------------------------------------|
| Small | 2 | 10 | 10 | ~90 |
| Medium | 4 | 20 | 20 | ~300 |
| Large | 8 | 30 | 30 | ~810 |
| XL | 16 | 40 | 40 | ~2,040 |

---

## Production Checklist

### Security

- [ ] JWT secret is set via environment variable (minimum 32 characters)
- [ ] Database passwords are strong and set via environment variables
- [ ] Redis password is enabled
- [ ] MySQL and Redis ports are **not exposed** publicly (internal network only)
- [ ] Adminer is **removed** from the production compose file
- [ ] TLS is terminated at the reverse proxy / load balancer
- [ ] `.env.production` is **not committed** to version control

### Performance

- [ ] Swoole workers tuned to CPU count (`swoole_cpu_num()` or `SWOOLE_WORKERS`)
- [ ] Connection pool sizes tuned for your scale (see table above)
- [ ] MySQL `max_connections` accommodates total pool size
- [ ] Composer installed with `--no-dev --optimize-autoloader`

### Reliability

- [ ] Health check endpoint `/health` wired to load balancer
- [ ] Scheduler runs **exactly 1 replica** (never more)
- [ ] Processor and scheduler have restart policies (`on-failure` or `unless-stopped`)
- [ ] Container images are tagged with specific versions (not `latest`)

### Observability

- [ ] Logs are routed to a log aggregator (CloudWatch, Loki, ELK, Datadog)
- [ ] Prometheus scrapes `GET /metrics` endpoint
- [ ] Alerts configured for error rate, latency, pool exhaustion
- [ ] Distributed tracing via `X-Correlation-ID` / `traceparent` headers

### CI/CD

- [ ] Automated image builds on merge to main
- [ ] PHPUnit tests pass before deployment (`vendor/bin/phpunit`)
- [ ] Rolling deploys for zero-downtime updates
- [ ] Database migrations run before deploying new code

---

## Zero-Downtime Deployment

Since Fabriq is a long-running server, deployments need to be graceful:

### Docker Compose Rolling Update

```bash
# Build new image
docker build -f infra/Dockerfile -t your-registry.com/fabriq:1.1.0 .
docker push your-registry.com/fabriq:1.1.0

# Update the TAG and redeploy
TAG=1.1.0 docker compose -f infra/docker-compose.prod.yml --env-file .env.production up -d
```

### Kubernetes Rolling Update

```bash
# Update the image tag
kubectl set image deployment/fabriq-web fabriq=your-registry.com/fabriq:1.1.0
kubectl set image deployment/fabriq-processor fabriq=your-registry.com/fabriq:1.1.0
kubectl set image deployment/fabriq-scheduler fabriq=your-registry.com/fabriq:1.1.0
```

Kubernetes handles rolling updates automatically — it starts new pods, waits for readiness probes, then terminates old pods.

### Releasing New Versions (Packagist)

Fabriq uses a monorepo split workflow to publish individual packages to [Packagist](https://packagist.org). When you tag a release on the monorepo, the GitHub Actions workflow automatically propagates the tag to all split repositories, and Packagist picks them up as new versions.

```bash
# Tag a new version on the monorepo
git tag v1.1.0
git push origin v1.1.0
```

This triggers the `.github/workflows/split.yml` workflow, which:

1. Splits each `packages/<name>` directory using `splitsh/lite`
2. Pushes the split code to each read-only repository (e.g., `easiviotech/fabriq-kernel`)
3. Creates the `v1.1.0` tag on each split repository

Packagist then detects the new tag and makes it available as `fabriq/kernel:1.1.0`, `fabriq/streaming:1.1.0`, etc.

**Published packages:**

| Package | Packagist URL |
|---------|---------------|
| `fabriq/kernel` | [packagist.org/packages/fabriq/kernel](https://packagist.org/packages/fabriq/kernel) |
| `fabriq/storage` | [packagist.org/packages/fabriq/storage](https://packagist.org/packages/fabriq/storage) |
| `fabriq/observability` | [packagist.org/packages/fabriq/observability](https://packagist.org/packages/fabriq/observability) |
| `fabriq/tenancy` | [packagist.org/packages/fabriq/tenancy](https://packagist.org/packages/fabriq/tenancy) |
| `fabriq/streaming` | [packagist.org/packages/fabriq/streaming](https://packagist.org/packages/fabriq/streaming) |
| `fabriq/gaming` | [packagist.org/packages/fabriq/gaming](https://packagist.org/packages/fabriq/gaming) |

---

### Database Migrations

Run migrations **before** deploying new application code:

```bash
# Via Docker (run once, then exit)
docker run --rm \
  --env-file .env.production \
  --network sf-net \
  your-registry.com/fabriq:1.1.0 \
  php bin/fabriq migrate

# Via Kubernetes
kubectl run fabriq-migrate --rm -it --restart=Never \
  --image=your-registry.com/fabriq:1.1.0 \
  --env-from=secret/fabriq-secrets \
  -- php bin/fabriq migrate
```

