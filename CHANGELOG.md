# Changelog

All notable changes to Fabriq are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Fabriq uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

---

## [1.2.0] — 2026-03-06

### Added

#### CLI System
- New `Command` abstract base class with `line()`, `info()`, `error()`, `warn()` output helpers
- New `CommandRegistry` for registering and resolving CLI commands by name
- New `Console\Kernel` that parses `argv`, resolves commands, and prints a formatted help screen
- New `GeneratorCommand` base class for stub-based code generators
- Code generators: `make:controller`, `make:model`, `make:provider`, `make:middleware`, `make:migration`, `make:seeder`, `make:factory`
- Migration commands: `migrate`, `migrate:rollback`
- Seeding commands: `db:seed`, `make:seeder`, `make:factory`
- Stub templates for all generators in `packages/kernel/stubs/`

#### Database
- New `Seeder` abstract base class with `run()` and `call()` for chained seeders
- New `Factory` builder with `count()`, `state()`, `as()`, `make()`, `create()` and abstract `definition()`
- `database/seeders/` and `database/factories/` directories scaffolded in skeleton

#### Error Handling
- New `ErrorHandler` with developer-friendly HTML debug page (stack trace, request details) and production JSON responses
- New `HttpException` base class and hierarchy: `NotFoundException` (404), `ValidationException` (400), `UnauthorizedException` (401), `ForbiddenException` (403)
- `Server` now delegates all uncaught exceptions to `ErrorHandler`

#### Lifecycle Hooks
- New `EventDispatcher` for internal lifecycle events (`listen`, `dispatch`, `hasListeners`, `forget`)
- Events dispatched: `server.booted`, `worker.started`, `request.received`, `request.handled`, `request.error`
- `Application` and `Server` wired to dispatch all lifecycle events

#### Static Analysis
- PHPStan configured at level 6 (`phpstan.neon`)
- `phpstan-baseline.neon` for tracking pre-existing issues
- `composer analyse` / `composer analyze` scripts added

#### CI/CD
- GitHub Actions workflow (`.github/workflows/ci.yml`) — PHP 8.2 + 8.3 matrix, Swoole, PHPUnit with coverage, PHPStan

#### Testing
- 40+ new unit tests across all packages
- Tier 1 (pure logic): `ValidatorTest`, `CollectionTest`, `PaginatorTest`, `BlueprintTest`, `BuildResultTest`, `HasAttributesTest`, `TraceContextTest`, `MetricsCollectorTest`, `LoggerTest`, `TenantContextTest`, `TenantConfigCacheTest`, `StateSyncTest`, `UdpProtocolTest`, `ErrorHandlerTest`, `HttpExceptionTest`, `EventDispatcherTest`, `MiddlewareChainTest`
- Tier 2 (mock-dependent): `JwtAuthenticatorTest`, `ApiKeyAuthenticatorTest`, `GatewayTest`, `CommandRegistryTest`, `ConsoleKernelTest`, `MakeControllerCommandTest`, `SeederTest`
- Coverage reporting via Clover (`composer test:coverage`)

#### Benchmarks
- `benchmarks/HttpBenchmark.php` — concurrent HTTP load testing via Swoole coroutine client
- `benchmarks/run.php` — CLI entry point with configurable concurrency and request count
- `benchmarks/README.md` — documented usage and result interpretation

#### Open-Source Infrastructure
- `MIT LICENSE` for the core framework
- `LICENSE-PREMIUM` for proprietary add-on packages (`fabriq/streaming`, `fabriq/gaming`)
- `CONTRIBUTING.md` — development setup, coding standards, PR process
- `CHANGELOG.md` (this file)
- `.github/ISSUE_TEMPLATE/bug_report.md` and `feature_request.md`
- `.github/PULL_REQUEST_TEMPLATE.md`
- `docs/MIGRATION_FROM_LARAVEL.md` — side-by-side comparison for Laravel developers

#### Skeleton Project
- Full `skeleton/` directory for bootstrapping new Fabriq applications
- Includes Docker Compose stack, routes, controllers, config, service providers, and README
- `skeleton/bin/fabriq` wired to the new `Console\Kernel`

#### Frontend Serving
- Per-tenant static file serving via `StaticFileMiddleware`
- SPA fallback for client-side routing
- Smart caching with fingerprint detection and immutable headers
- 3-tier custom domain resolution: `domain_map` → `TenantResolver` → database lookup
- `FrontendBuilder` — git clone, npm build, atomic deploy per tenant
- `frontend:build` and `frontend:status` CLI commands
- Webhook endpoint for GitHub/GitLab push-triggered builds
- `BuildFrontendJob` for async queue-based frontend builds

#### Add-on Packages (opt-in)
- `fabriq/streaming` and `fabriq/gaming` are now disabled by default
- Enable via `config/app.php` service providers — zero overhead when not in use

### Changed
- `bin/fabriq` refactored from monolithic `switch` to `Console\Kernel` with `CommandRegistry`
- `Application::boot()` now dispatches the `server.booted` lifecycle event
- `Server::onRequest()` delegates exception handling to `ErrorHandler`
- `composer test` now runs `phpunit --no-coverage` to avoid warnings in environments without Xdebug
- Organization references updated from `fabriqphp` to `easiviotech` across docs, templates, and config
- Core package licenses (`fabriq/kernel`, `fabriq/storage`, `fabriq/observability`, `fabriq/tenancy`) updated to MIT
- `phpunit.xml` — added coverage configuration, set `failOnWarning="false"`

---

## [1.1.0] — 2026-02-15

### Added
- Packagist publishing setup with monorepo split workflow
- Split workflow for `fabriq/kernel`, `fabriq/storage`, `fabriq/observability`, `fabriq/tenancy`, `fabriq/streaming`, `fabriq/gaming` via GitHub Actions
- `easiviotech` GitHub organization configured as split target

---

## [1.0.0] — 2026-01-20

### Added
- Swoole HTTP server with middleware pipeline and routing
- WebSocket gateway with JWT auth, rooms, presence, and cross-worker Redis pub/sub push
- Background job queue via Redis Streams with retry, exponential backoff, and dead-letter queue
- Event bus with consumer groups and deduplication
- Scheduler for recurring and delayed jobs
- Kernel-level multi-tenancy: `TenantResolver`, `TenantContext`, `TenantConfigCache`
- Per-tenant database routing (shared, same_server, dedicated strategies)
- Coroutine-safe `Context` isolation with propagation across async boundaries
- Connection pools for MySQL and Redis (per Swoole worker, health-checked)
- Custom ORM: Active Record, QueryBuilder, stored procedures, schema migrations, `Blueprint`
- Live streaming subsystem: WebRTC signaling, FFmpeg transcoding, HLS, viewer tracking, chat moderation (`fabriq/streaming`)
- Game server engine: tick loop, matchmaking, lobbies, state sync, UDP protocol (`fabriq/gaming`)
- JWT and API key authentication
- RBAC + ABAC policy engine
- Redis-based rate limiting
- Idempotency store for HTTP requests, queued jobs, and events
- Structured JSON logging, Prometheus metrics, distributed tracing
- DI container with `bind`, `singleton`, `instance`, `make`
- Service provider lifecycle (`register` → `boot`)
- Docker Compose stack (MySQL, Redis, Adminer)
- Production deployment guide (Docker, Kubernetes, cloud platforms)
- Nginx/Caddy/ALB reverse proxy configurations
- HTML documentation site (`docs-site/`) with 15 pages, search, and syntax highlighting
- CLI: `serve`, `processor`, `scheduler`

---

[Unreleased]: https://github.com/easiviotech/fabriq/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/easiviotech/fabriq/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/easiviotech/fabriq/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/easiviotech/fabriq/releases/tag/v1.0.0
