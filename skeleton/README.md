# Fabriq Skeleton

> The official starter project for [Fabriq](https://github.com/easiviotech/fabriq) — a unified Swoole-powered platform for building multi-tenant SaaS applications with realtime WebSockets, background jobs, and per-tenant frontend serving.

## Create a New Project

**Linux / macOS:**
```bash
composer create-project easiviotech/fabriq-skeleton myapp
cd myapp
```

**Windows:**
```powershell
composer create-project easiviotech/fabriq-skeleton myapp --ignore-platform-req=ext-swoole
cd myapp
```

> Swoole does not run natively on Windows or macOS. The `--ignore-platform-req=ext-swoole` flag skips the local Swoole check — Swoole runs inside Docker, so this is safe and expected.

## Start the Stack

```bash
docker compose -f infra/docker-compose.yml up -d --build
```

This starts six containers:

| Container | Role |
|---|---|
| `app` | Fabriq HTTP + WebSocket server |
| `processor` | Background job + event bus worker |
| `scheduler` | Cron-like task scheduler |
| `mysql` | MySQL 8 database |
| `redis` | Redis (jobs, events, pub/sub, rate limiting) |
| `adminer` | Database UI at [http://localhost:8080](http://localhost:8080) |

Verify it's running:

```bash
curl http://localhost:8000/health
curl http://localhost:8000/api/welcome
```

## Customise Project Name & Ports

Edit `infra/.env` to avoid port conflicts when running multiple projects:

```env
COMPOSE_PROJECT_NAME=myapp
APP_PORT=8000
MYSQL_PORT=3306
REDIS_PORT=6379
ADMINER_PORT=8080
```

## Project Structure

```
myapp/
├── app/
│   ├── Http/Controllers/    # HTTP request handlers
│   ├── Providers/           # Service providers (register + boot lifecycle)
│   ├── Models/              # ORM models (Active Record, tenant-scoped)
│   ├── Repositories/        # Data access layer
│   ├── Events/              # Domain event classes
│   ├── Listeners/           # Event listener handlers
│   ├── Jobs/                # Background job classes
│   └── Realtime/            # WebSocket handlers
├── bin/fabriq               # CLI entry point
├── bootstrap/app.php        # Application bootstrap
├── config/                  # Configuration files
├── routes/
│   ├── api.php              # HTTP API routes
│   └── channels.php         # WebSocket channel definitions
├── database/
│   └── migrations/          # SQL migration files
├── infra/
│   ├── .env                 # Project name + port variables
│   ├── Dockerfile
│   ├── docker-compose.yml
│   └── mysql/init.sql
├── public/_default/         # Default tenant frontend (static files)
├── storage/builds/          # Frontend build workspace
├── tests/
│   ├── Feature/
│   └── Unit/
├── composer.json
└── phpunit.xml
```

## CLI Commands

```bash
# Server processes
php bin/fabriq serve                          # Start HTTP + WebSocket server
php bin/fabriq processor                      # Start queue + event processor
php bin/fabriq scheduler                      # Start task scheduler

# Code generators
php bin/fabriq make:controller Name           # Generate a controller
php bin/fabriq make:model Name                # Generate an ORM model
php bin/fabriq make:migration create_table    # Generate a migration
php bin/fabriq make:provider Name             # Generate a service provider
php bin/fabriq make:middleware Name           # Generate middleware
php bin/fabriq make:seeder Name               # Generate a database seeder
php bin/fabriq make:factory Name              # Generate a model factory

# Database
php bin/fabriq migrate                        # Run pending migrations
php bin/fabriq migrate:rollback               # Roll back the last migration batch
php bin/fabriq db:seed                        # Run database seeders

# Frontend (per-tenant)
php bin/fabriq frontend:build <tenant-slug>   # Build tenant frontend from Git
php bin/fabriq frontend:status <tenant-slug>  # Check frontend deploy status

# Help
php bin/fabriq help                           # List all available commands
```

## Enable Optional Features

The skeleton ships with the core providers active. Uncomment providers in `config/app.php` to enable additional features:

| Provider | Feature |
|---|---|
| `AuthServiceProvider` | JWT + API key authentication, RBAC/ABAC |
| `EventServiceProvider` | Domain events and listeners |
| `RealtimeServiceProvider` | WebSocket channels and presence |
| `OrmServiceProvider` | Active Record ORM and migrations |

## Add Premium Packages

```bash
# Live streaming (WebRTC signaling, HLS transcoding, chat moderation)
composer require fabriq/streaming

# Game server engine (tick loop, matchmaking, lobbies, UDP protocol)
composer require fabriq/gaming
```

## Documentation

- **Full framework docs**: [https://fabriq.dev/docs](https://fabriq.dev/docs)
- **Framework source**: [https://github.com/easiviotech/fabriq](https://github.com/easiviotech/fabriq)
- **Changelog**: [https://github.com/easiviotech/fabriq/blob/main/CHANGELOG.md](https://github.com/easiviotech/fabriq/blob/main/CHANGELOG.md)

## License

MIT — see [LICENSE](LICENSE)
