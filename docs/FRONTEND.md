# Fabriq — Frontend Serving & Build Automation

> Serve per-tenant frontend builds directly through Swoole with built-in CI/CD. Use any frontend framework — React, Vue, Svelte, Angular, Next.js, or anything that produces static build output.

---

## Table of Contents

1. [Overview](#overview)
2. [How It Works](#how-it-works)
3. [Configuration](#configuration)
4. [Directory Structure](#directory-structure)
5. [Static File Serving](#static-file-serving)
6. [Multi-Tenancy](#multi-tenancy)
7. [Custom Domains](#custom-domains)
8. [SPA Fallback](#spa-fallback)
9. [Build Automation (CI/CD)](#build-automation-cicd)
10. [CLI Commands](#cli-commands)
11. [API Endpoints](#api-endpoints)
12. [Webhook Integration](#webhook-integration)
13. [Caching Strategy](#caching-strategy)
14. [Security](#security)
15. [Deployment](#deployment)

---

## Overview

Traditional PHP frameworks delegate static file serving to Nginx or Apache. Fabriq takes a different approach: the Swoole HTTP server serves both your **API** and your **frontend** from a single port, with per-tenant build directories and a built-in CI/CD pipeline.

This means:

- **One port, one process** — API and frontend served together, no separate web server needed
- **Per-tenant frontends** — Each tenant can run a completely different frontend (or version)
- **Any framework** — React, Vue, Svelte, Angular, Next.js, Vite, or anything that produces `index.html` + assets
- **Built-in CI/CD** — Clone a tenant's Git repo, run the build command, deploy the output — triggered via CLI, API, or webhook
- **Zero-downtime deploys** — Atomic directory swap ensures users never see a half-built state

---

## How It Works

```
Incoming HTTP Request
  │
  ├─ /api/*, /health, /metrics  →  API route handlers (unchanged)
  │
  └─ Everything else  →  StaticFileMiddleware
                            │
                            ├─ Resolve tenant (subdomain / X-Tenant header / JWT)
                            │
                            ├─ Try: public/{tenant-slug}/{path}
                            │   └─ File exists? → Serve with sendfile() + MIME + cache headers
                            │
                            ├─ Fallback: public/_default/{path}
                            │   └─ File exists? → Serve with sendfile() + MIME + cache headers
                            │
                            └─ SPA fallback: Serve index.html from resolved directory
```

The `StaticFileMiddleware` is registered as the **last route handler** on the Application, so API routes always take priority. Non-API requests are resolved to per-tenant directories, with automatic fallback to the `_default/` directory.

---

## Configuration

All settings live in `config/static.php`:

```php
return [
    // Master switch — set to true to enable frontend serving
    'enabled' => false,

    // Directory containing frontend builds, relative to project root
    'document_root' => 'public',

    // Per-tenant subdirectories (public/{slug}/)
    'multi_tenancy' => true,

    // Fallback directory when tenant has no custom build
    'default_tenant_dir' => '_default',

    // Serve index.html for unmatched paths (required for client-side routing)
    'spa_fallback' => true,

    // SPA entry point filename
    'index' => 'index.html',

    // Paths that are never served as static files (left to API handlers)
    'api_prefixes' => ['/api', '/health', '/metrics'],

    // Cache-Control max-age for non-fingerprinted assets (seconds)
    'cache_max_age' => 86400,

    // Add Access-Control-Allow-Origin: * to static responses
    'cors' => false,

    // Static domain → slug mapping (checked first, O(1), no DB)
    'domain_map' => [
        // 'dashboard.acme.com' => 'acme',
        // 'app.globex.io'      => 'globex',
    ],

    // Build automation settings
    'build' => [
        'workspace'       => 'storage/builds',
        'default_command' => 'npm install && npm run build',
        'default_output'  => 'dist',
        'webhook_secret'  => '',
        'timeout'         => 300,
    ],
];
```

### Enabling

Set `'enabled' => true` in `config/static.php`. That's all you need — the `StaticFileMiddleware` is automatically registered as the last route handler.

---

## Directory Structure

```
public/
├── _default/                  # Shared fallback frontend
│   ├── index.html
│   ├── favicon.ico
│   └── assets/
│       ├── app.a1b2c3d4.js
│       └── style.e5f6g7h8.css
├── acme/                      # Tenant "acme" custom frontend
│   ├── index.html
│   └── assets/
│       └── ...
└── globex/                    # Tenant "globex" custom frontend
    ├── index.html
    └── assets/
        └── ...
```

- **`_default/`** — Used when tenant resolution fails (e.g., login page) or when a tenant has no dedicated build
- **`{tenant-slug}/`** — Tenant-specific builds, named by the tenant's `slug` field from the database

---

## Static File Serving

Fabriq uses Swoole's zero-copy `sendfile()` system call for high-performance file delivery. Files are served with appropriate MIME types automatically detected from the file extension.

### Supported MIME Types

| Extension | Content-Type |
|-----------|-------------|
| `.html`, `.htm` | `text/html` |
| `.css` | `text/css` |
| `.js`, `.mjs` | `application/javascript` |
| `.json`, `.map` | `application/json` |
| `.svg` | `image/svg+xml` |
| `.png` | `image/png` |
| `.jpg`, `.jpeg` | `image/jpeg` |
| `.gif` | `image/gif` |
| `.webp` | `image/webp` |
| `.avif` | `image/avif` |
| `.ico` | `image/x-icon` |
| `.woff` | `font/woff` |
| `.woff2` | `font/woff2` |
| `.ttf` | `font/ttf` |
| `.eot` | `application/vnd.ms-fontobject` |
| `.webmanifest` | `application/manifest+json` |
| `.wasm` | `application/wasm` |
| `.pdf` | `application/pdf` |
| `.txt` | `text/plain` |
| `.xml` | `application/xml` |

Unknown extensions are served as `application/octet-stream`.

---

## Multi-Tenancy

When `multi_tenancy` is enabled (default), the middleware resolves the tenant from the incoming request using the existing `TenantResolver` — the same one used by your API middleware.

### Resolution Chain

The tenant is identified using the same strategies configured in `config/tenancy.php`:

1. **Host** — Subdomain extraction (e.g., `acme.myapp.com` → slug `acme`)
2. **Header** — `X-Tenant: acme` header
3. **Token** — `tenant_id` claim from a decoded JWT

### Graceful Fallback

Unlike the API's `TenancyMiddleware` (which rejects with 400 on failure), the static file handler **degrades gracefully**:

- If tenant resolution fails → serve from `_default/`
- If the tenant's directory doesn't exist → serve from `_default/`
- If `_default/` doesn't exist either → return `false` (let the 404 handler respond)

This ensures login pages, marketing sites, and public-facing pages always work — even before a user is authenticated or a tenant is identified.

### Disabling Multi-Tenancy

Set `'multi_tenancy' => false` in `config/static.php` to serve all requests from the `_default/` directory, ignoring tenant resolution entirely.

---

## Custom Domains

Tenants can have their own custom domains (e.g., `dashboard.acme.com`, `app.globex.io`). When a request arrives on a custom domain, Fabriq resolves the tenant and serves their build files — no subdomains or headers required.

### 3-Tier Domain Resolution

The `StaticFileMiddleware` resolves the tenant slug using a three-tier strategy. Each tier is tried in order until one succeeds:

```
Incoming request (Host: dashboard.acme.com)
  │
  ├─ Tier 1: domain_map config        ← O(1) array lookup, no DB
  │   dashboard.acme.com → "acme"
  │
  ├─ Tier 2: TenantResolver           ← subdomain, X-Tenant header, JWT
  │   (existing multi-strategy resolver)
  │
  └─ Tier 3: DB domain lookup         ← SELECT * FROM tenants WHERE domain = ?
      returns tenant.slug
```

| Tier | Source | Speed | When to Use |
|------|--------|-------|-------------|
| 1 | `static.domain_map` config | O(1), no DB | Known domains, high traffic |
| 2 | `TenantResolver` | Depends on strategy | Subdomains, headers, JWT |
| 3 | DB query (`tenants.domain`) | DB round-trip | Dynamic domains, catchall |

### Tier 1: Static Domain Map (Recommended for Production)

Add explicit domain-to-slug mappings in `config/static.php`:

```php
'domain_map' => [
    'dashboard.acme.com' => 'acme',
    'app.globex.io'      => 'globex',
    'myapp.example.com'  => 'example-co',
],
```

This is the fastest path — a simple array lookup with no database query. Use this for tenants with stable custom domains.

### Tier 2: TenantResolver

The existing `TenantResolver` handles:

- **Subdomain extraction** — `acme.yourapp.com` resolves slug `acme`
- **Custom domain matching** — if the resolver's lookup callback handles `('domain', 'dashboard.acme.com')` queries
- **`X-Tenant` header** — explicit tenant identification
- **JWT `tenant_id` claim** — token-based resolution

This tier fires automatically when a `TenantResolver` is registered in the container.

### Tier 3: Database Domain Lookup

As a last resort, Fabriq queries the `tenants` table directly:

```sql
SELECT * FROM tenants WHERE domain = 'dashboard.acme.com' LIMIT 1
```

This requires:
1. The `domain` column on your `tenants` table (already present in the default schema)
2. The tenant's domain stored in the `domain` column:

```php
// When creating or updating a tenant
Tenant::create([
    'id'     => $uuid,
    'slug'   => 'acme',
    'name'   => 'Acme Corp',
    'domain' => 'dashboard.acme.com',
    'plan'   => 'pro',
]);
```

### Setting Up Custom Domains

1. **Register the domain on the tenant record:**

```bash
curl -X POST https://api.yourapp.com/api/tenants \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Acme Corp",
    "slug": "acme",
    "domain": "dashboard.acme.com"
  }'
```

2. **Point the domain to your Fabriq server** (DNS):

```
dashboard.acme.com.  CNAME  yourapp.com.
# or
dashboard.acme.com.  A      <your-server-ip>
```

3. **(Optional) Add to `domain_map` for faster resolution:**

```php
// config/static.php
'domain_map' => [
    'dashboard.acme.com' => 'acme',
],
```

4. **Deploy the tenant's frontend:**

```bash
php bin/fabriq frontend:build acme
```

Now requests to `https://dashboard.acme.com` serve the frontend from `public/acme/`.

### Multiple Domains Per Tenant

A tenant's slug maps to a single directory (`public/{slug}/`), but multiple domains can resolve to the same slug:

```php
'domain_map' => [
    'dashboard.acme.com' => 'acme',
    'app.acme.com'       => 'acme',
    'acme-portal.io'     => 'acme',
],
```

All three domains serve files from `public/acme/`.

---

## SPA Fallback

Single-page applications (React, Vue, Angular, etc.) use client-side routing — the browser URL changes without a full page reload. When a user refreshes `https://app.com/dashboard/settings`, the server needs to serve `index.html` (not look for a file at `/dashboard/settings`).

When `spa_fallback` is enabled (default), any request that:

1. Does **not** start with an API prefix
2. Does **not** match a static file

...is served the `index.html` from the resolved tenant directory. The frontend's JavaScript router then handles the path.

---

## Build Automation (CI/CD)

Fabriq includes a built-in build pipeline that clones a tenant's frontend Git repository, runs the build command, and deploys the output to the correct directory.

### Tenant Frontend Config

Each tenant stores their frontend settings in the `config` JSON field (the existing `TenantContext.config`):

```json
{
  "frontend": {
    "repository": "https://github.com/acme-corp/dashboard.git",
    "branch": "main",
    "build_command": "npm install && npm run build",
    "output_dir": "dist"
  }
}
```

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `repository` | Yes | — | Git clone URL (HTTPS or SSH) |
| `branch` | No | `main` | Branch to clone/checkout |
| `build_command` | No | `npm install && npm run build` | Shell command to run in the repo |
| `output_dir` | No | `dist` | Subdirectory containing the build output |

### Build Pipeline

```
1. Clone / Pull
   └─ git clone --depth 1 --branch {branch} {repository}
   └─ (or git fetch + git reset --hard origin/{branch} if already cloned)

2. Build
   └─ cd {repo} && {build_command}
   └─ Runs via Swoole\Coroutine\System::exec() (non-blocking, timeout enforced)

3. Validate
   └─ Check that {output_dir}/ exists
   └─ Warn if index.html is missing

4. Deploy (atomic)
   └─ Rename public/{slug}/ → public/{slug}.old/
   └─ Copy build output → public/{slug}/
   └─ Delete public/{slug}.old/
```

The atomic swap in step 4 ensures **zero-downtime deployments** — users never see a broken or half-built state.

### Build Workspace

Builds happen in `storage/builds/{tenant-slug}/repo/`, isolated from the application root. The workspace persists between builds so subsequent builds only need to `git pull` instead of a full clone.

---

## CLI Commands

### `frontend:build`

Build and deploy a tenant's frontend from their configured Git repository.

```bash
php bin/fabriq frontend:build acme
```

Output:

```
╔══════════════════════════════════════╗
║              Fabriq                  ║
║        Frontend Builder              ║
╚══════════════════════════════════════╝

[info] Starting build for tenant 'acme'...
[git] Cloning https://github.com/acme-corp/dashboard.git (branch: main)...
[build] Running: npm install && npm run build
[deploy] Deployed to public/acme/

Status:      success
Commit:      a1b2c3d
Duration:    12345ms
Timestamp:   2026-02-22T18:00:00+00:00

Frontend deployed to public/acme/
```

This command runs synchronously — useful for initial setup, debugging, or manual deployments.

### `frontend:status`

Check the deployment status of a tenant's frontend.

```bash
php bin/fabriq frontend:status acme
```

Output:

```
Tenant:       acme
Deploy path:  /app/public/acme
Status:       deployed
Has index:    yes
Total files:  42
```

---

## API Endpoints

### Deploy (trigger a build)

```
POST /api/tenants/{tenantId}/frontend/deploy
Authorization: Bearer <token>
```

Dispatches an async build job via the queue. Returns immediately with a status URL.

**Response** (`202 Accepted`):

```json
{
  "message": "Build queued",
  "tenant_slug": "acme",
  "status_url": "/api/tenants/uuid-here/frontend/status"
}
```

### Status

```
GET /api/tenants/{tenantId}/frontend/status
Authorization: Bearer <token>
```

**Response** (`200 OK`):

```json
{
  "tenant_slug": "acme",
  "deployed": true,
  "has_index": true,
  "last_build": {
    "tenant_slug": "acme",
    "status": "success",
    "commit_hash": "a1b2c3d",
    "duration_ms": 12345.67,
    "log": "[git] Cloning...\n[build] Running...\n[deploy] Deployed...",
    "timestamp": "2026-02-22T18:00:00+00:00"
  }
}
```

---

## Webhook Integration

Fabriq exposes a webhook endpoint that external CI systems (GitHub, GitLab, Bitbucket) can call to trigger a frontend build automatically on push.

### Endpoint

```
POST /api/webhooks/frontend/deploy
X-Webhook-Secret: <your-secret>
Content-Type: application/json

{
  "tenant_slug": "acme"
}
```

**Response** (`202 Accepted`):

```json
{
  "message": "Build queued",
  "tenant_slug": "acme"
}
```

### Setup

1. Set a webhook secret in `config/static.php`:

```php
'build' => [
    'webhook_secret' => 'my-super-secret-token',
    // ...
],
```

2. Configure your Git hosting provider to send a POST request on push:

**GitHub:**

- Settings > Webhooks > Add webhook
- Payload URL: `https://api.yourapp.com/api/webhooks/frontend/deploy`
- Content type: `application/json`
- Secret: add `X-Webhook-Secret` as a custom header

**GitLab:**

- Settings > Webhooks
- URL: `https://api.yourapp.com/api/webhooks/frontend/deploy`
- Add custom header: `X-Webhook-Secret: my-super-secret-token`
- Trigger: Push events

### Security

The webhook endpoint validates the `X-Webhook-Secret` header using constant-time comparison (`hash_equals`). Requests without a valid secret are rejected with `401 Unauthorized`.

---

## Caching Strategy

The `StaticFileMiddleware` applies intelligent cache headers based on the file type and naming pattern:

| File Type | Cache-Control | Rationale |
|-----------|--------------|-----------|
| HTML files (`.html`, `.htm`) | `no-cache` | Always fetch the latest; the HTML references versioned assets |
| Fingerprinted assets (`app.a1b2c3d4.js`) | `public, max-age=31536000, immutable` | Content-addressed; the hash changes when the file changes |
| Other static files | `public, max-age=86400` | Configurable via `cache_max_age` setting |

### Fingerprint Detection

A file is considered "fingerprinted" if its name contains a hex hash segment (6-32 characters), e.g.:

- `app.a1b2c3d4.js` — fingerprinted
- `style.e5f6g7h8.css` — fingerprinted
- `chunk-vendors.abc12345.js` — fingerprinted
- `index.html` — not fingerprinted
- `logo.png` — not fingerprinted

Most frontend build tools (Vite, Webpack, Rollup) automatically add content hashes to asset filenames.

---

## Security

### Path Traversal Protection

The middleware validates every request path to prevent directory traversal attacks:

- Paths containing `..` are rejected
- Paths containing null bytes (`\0`) are rejected
- `realpath()` is used to verify the resolved file stays within the document root

### Build Isolation

- Builds run in `storage/builds/`, never in the application root
- The build output directory is validated before deployment
- The build timeout (default 300 seconds) prevents runaway processes

### Webhook Authentication

- `X-Webhook-Secret` header is compared using `hash_equals()` (constant-time, timing-safe)
- Requests without a valid secret are rejected with `401`
- An empty `webhook_secret` config disables the webhook endpoint entirely (`503`)

---

## Deployment

### Manual Deployment (No CI/CD)

Simply copy your frontend build output into the appropriate directory:

```bash
# Default frontend (shared by all tenants without a custom build)
cp -r my-frontend/dist/* public/_default/

# Tenant-specific frontend
cp -r acme-dashboard/dist/* public/acme/
```

### Automated Deployment via CLI

```bash
# Build from the tenant's configured Git repository
php bin/fabriq frontend:build acme
```

### Automated Deployment via API

```bash
curl -X POST https://api.yourapp.com/api/tenants/uuid-here/frontend/deploy \
  -H "Authorization: Bearer <token>"
```

### Automated Deployment via Webhook

Configure GitHub/GitLab to POST to `/api/webhooks/frontend/deploy` on push. See [Webhook Integration](#webhook-integration).

### Docker Considerations

When running in Docker, ensure the `public/` and `storage/builds/` directories are either:

- **Persisted via volumes** — so builds survive container restarts
- **Part of a shared filesystem** — if running multiple replicas

```yaml
services:
  web:
    image: fabriq:latest
    volumes:
      - frontend-data:/app/public
      - build-workspace:/app/storage/builds

volumes:
  frontend-data:
  build-workspace:
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `STATIC_ENABLED` | `false` | Enable static file serving |
| `STATIC_DOCUMENT_ROOT` | `public` | Frontend build directory |
| `STATIC_SPA_FALLBACK` | `true` | Enable SPA fallback to index.html |
| `STATIC_WEBHOOK_SECRET` | — | Shared secret for webhook authentication |
| `STATIC_BUILD_TIMEOUT` | `300` | Build timeout in seconds |

To use environment variables in `config/static.php`:

```php
'enabled' => (bool) (getenv('STATIC_ENABLED') ?: false),
'build' => [
    'webhook_secret' => getenv('STATIC_WEBHOOK_SECRET') ?: '',
    'timeout' => (int) (getenv('STATIC_BUILD_TIMEOUT') ?: 300),
],
```
