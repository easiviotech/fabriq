# My Fabriq App

> Built on [Fabriq](https://github.com/easiviotech/fabriq) — a unified Swoole-powered platform with multi-tenancy, realtime, queues, and per-tenant frontend serving.

## Quick Start

### Docker (Recommended)

```bash
# 1. Clone this project
git clone <your-repo-url> myapp && cd myapp

# 2. (Optional) Install dependencies locally for IDE autocompletion
composer install --ignore-platform-reqs

# 3. Start the full stack
docker compose -f infra/docker-compose.yml up -d --build

# 4. Verify
curl http://localhost:8000/health
curl http://localhost:8000/api/welcome
```

### Customise Project Name & Ports

Edit `infra/.env` to avoid conflicts with other projects:

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
│   ├── Http/Controllers/    # HTTP request controllers
│   ├── Providers/           # Service providers (register + boot)
│   ├── Models/              # ORM models
│   ├── Repositories/        # Data access layer
│   ├── Events/              # Domain event classes
│   ├── Listeners/           # Event listener handlers
│   ├── Jobs/                # Queued job classes
│   └── Realtime/            # WebSocket handlers
├── bin/fabriq               # CLI entry point
├── bootstrap/app.php        # Application bootstrap
├── config/                  # Configuration files
├── routes/
│   ├── api.php              # HTTP API routes
│   └── channels.php         # WebSocket channels
├── database/migrations/     # SQL migration files
├── infra/
│   ├── .env                 # Project name + port variables
│   ├── Dockerfile
│   ├── docker-compose.yml
│   └── mysql/init.sql
├── public/                  # Frontend builds (per-tenant)
│   └── _default/            # Default frontend
├── storage/builds/          # Build workspace (git clone + npm)
├── tests/
├── composer.json
└── phpunit.xml
```

## CLI Commands

```bash
php bin/fabriq serve                         # Start HTTP + WS server
php bin/fabriq processor                     # Start queue/event processor
php bin/fabriq scheduler                     # Start cron-like scheduler
php bin/fabriq frontend:build <tenant-slug>  # Build tenant frontend from Git
php bin/fabriq frontend:status <tenant-slug> # Check frontend deploy status
php bin/fabriq help                          # Show all commands
```

## Enabling More Features

The skeleton ships with `AppServiceProvider` and `RouteServiceProvider` active. Uncomment providers in `config/app.php` to enable:

| Provider | Feature |
|----------|---------|
| `AuthServiceProvider` | JWT authentication |
| `EventServiceProvider` | Domain events & listeners |
| `RealtimeServiceProvider` | WebSocket channels |
| `OrmServiceProvider` | Active Record ORM, migrations |

### Add-on Packages

```bash
# Live streaming (WebRTC, HLS, chat moderation)
composer require fabriq/streaming

# Game server (tick loop, matchmaking, UDP protocol)
composer require fabriq/gaming
```

## Documentation

Full framework documentation: [https://fabriq.dev/docs](https://fabriq.dev/docs)

## License

MIT
