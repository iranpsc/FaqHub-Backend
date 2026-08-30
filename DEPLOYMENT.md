# Deployment Guide

This document covers deploying FaqHub Backend with **Dokploy (production)** and running it for **local development**.

## Architecture overview

| Compose file | Purpose |
|---|---|
| `docker-compose.yml` | Production (Dokploy) |
| `docker-compose.dev.yml` | Local development |

| Service | Role |
|---|---|
| `app` | Laravel HTTP API |
| `queue` | Redis queue worker |
| `scheduler` | `schedule:run` loop |
| `reverb` | WebSocket server (Laravel Reverb) |
| `mysql` | Primary database |
| `redis` | Cache, sessions, queues |
| `nginx` | Dev only — reverse proxy to PHP-FPM |
| `vite` | Dev only — frontend HMR |
| `mailpit` | Dev only — local SMTP/UI |

Static uploads are bind-mounted from the host. Production mounts `storage` and `sitemaps` subdirectories; the dev stack mounts the host path at `/opt/faqhub`.

Nginx configs for the dev stack live in `docker/nginx/` (`nginx.conf`, `default.conf`).

---

## Prerequisites

- Docker Engine + Docker Compose v2
- Git access to this repository
- For Dokploy: a server with Dokploy installed and a domain pointed at it
- For local Windows: Docker Desktop with WSL2 backend recommended

---

## 1. Production deployment (Dokploy)

### 1.1 Prepare the host static directory

On the Dokploy server, create the uploads directories (once):

```bash
sudo mkdir -p /opt/faqhub/storage /opt/faqhub/sitemaps
sudo chown -R 1000:1000 /opt/faqhub
sudo chmod -R 775 /opt/faqhub
```

Production compose bind-mounts:

| Host path | Container path |
|---|---|
| `${FAQHUB_STATIC_PATH}/storage` | `/var/www/html/storage/app/public` |
| `${FAQHUB_STATIC_PATH}/sitemaps` | `/var/www/html/public/sitemaps` |

### 1.2 Create the Dokploy application

1. Open Dokploy → **Projects** → create or select a project.
2. Create a new application of type **Docker Compose**.
3. Connect the Git repository (or deploy from a local/private registry source).
4. Set the compose file to:

```text
docker-compose.yml
```

5. Set the build context to the repository root (where `Dockerfile` lives).

### 1.3 Configure environment variables

In Dokploy → application → **Environment**, paste production values. Start from `.env.docker.example`, then replace secrets.

Required / important variables:

```env
APP_NAME=FaqHub
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.faqhub.ir
APP_KEY=base64:GENERATE_A_REAL_KEY

DB_CONNECTION=app
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=faqhub
DB_USERNAME=faqhub
DB_PASSWORD=use-a-strong-password
DB_ROOT_PASSWORD=use-a-strong-root-password

REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=faqhub
REVERB_APP_KEY=change-me
REVERB_APP_SECRET=change-me
REVERB_HOST=api.faqhub.ir
REVERB_PORT=443
REVERB_SCHEME=https

FAQHUB_STATIC_PATH=/opt/faqhub

APP_PORT=8000
MYSQL_PORT=3306
REDIS_PORT_PUBLISH=6379
REVERB_PUBLISH_PORT=8080

RUN_MIGRATIONS=true
CACHE_CONFIG=true

OAUTH_CLIENT_ID=...
OAUTH_CLIENT_SECRET=...
OAUTH_SERVER_URL=https://accounts.example.com
FRONTEND_APP_URL=https://faqhub.ir

SENTRY_LARAVEL_DSN=
```

Generate a real app key (on any machine with PHP, or temporarily in a container):

```bash
php artisan key:generate --show
# or
docker run --rm faqhub-app:latest php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

> **Important:** Use the **same** `APP_KEY` for `app`, `queue`, `scheduler`, and `reverb`. Do not leave the sample key from `.env.docker.example` in production.

### 1.4 Domains / Traefik (Dokploy)

Point your public domain at the `app` service HTTP port:

| Public service | Internal target | Notes |
|---|---|---|
| API / web | `app:8000` | Main Laravel HTTP entry |
| WebSockets | `reverb:8080` | Configure WS/WSS domain or path |

Typical Dokploy setup:

1. Add domain `api.faqhub.ir` → service `app`, port `8000`.
2. Enable HTTPS (Let's Encrypt) in Dokploy.
3. Optionally add a Reverb domain (or path) → service `reverb`, port `8080`, with WebSocket support enabled.
4. Keep MySQL (`3306`) and Redis (`6379`) **unpublished publicly** in production if Dokploy allows restricting publish, or firewall them. The compose file exposes them for ops/debug; lock them down on the host firewall.

### 1.5 Deploy

In Dokploy, trigger **Deploy** (build + start).

Equivalent CLI on the server:

```bash
cp .env.docker.example .env   # then edit secrets
# ensure FAQHUB_STATIC_PATH=/opt/faqhub
export APP_ENV=production APP_DEBUG=false CACHE_CONFIG=true
docker compose pull           # if using prebuilt images
docker compose up -d --build
```

On Windows PowerShell for a local production smoke test:

```powershell
$env:APP_ENV='production'; $env:APP_DEBUG='false'; $env:CACHE_CONFIG='true'
docker compose up -d --build
```

On first boot, the `app` entrypoint:

1. Waits for MySQL and Redis
2. Runs migrations when `RUN_MIGRATIONS=true`
3. Caches config/routes/views when `CACHE_CONFIG=true`
4. Starts `php artisan serve` on port `8000`

### 1.6 Verify production

```bash
docker compose ps
docker compose logs -f app queue scheduler reverb

# Health endpoint (Laravel)
curl -fsS https://api.faqhub.ir/up

# From the host, if ports are published locally
curl -fsS http://127.0.0.1:8000/up

# Queue and scheduler
docker compose exec app php artisan queue:monitor redis:default
docker compose exec app php artisan schedule:list
docker compose logs queue --tail 20
docker compose logs scheduler --tail 20
```

Expected healthy state:

| Service | Check |
|---|---|
| `app` | `docker compose ps` shows `(healthy)`; `/up` returns HTTP 200 |
| `mysql` / `redis` | `(healthy)` |
| `reverb` | `(healthy)`; logs show `Starting server on 0.0.0.0:8080` |
| `queue` | Logs show `starting queue worker...` |
| `scheduler` | Logs show `starting scheduler loop...` |

Check uploads mount:

```bash
docker compose exec app ls -la storage/app/public
docker compose exec app ls -la public/sitemaps
docker compose exec app php artisan tinker --execute="echo storage_path('app/public');"
```

### 1.7 Production ports reference

| Service | Host env | Container port |
|---|---|---|
| `app` | `APP_PORT` (default `8000`) | `8000` |
| `reverb` | `REVERB_PUBLISH_PORT` (default `8080`) | `8080` |
| `mysql` | `MYSQL_PORT` (default `3306`) | `3306` |
| `redis` | `REDIS_PORT_PUBLISH` (default `6379`) | `6379` |

`queue` and `scheduler` do not expose ports.

### 1.8 Useful production commands

```bash
# Logs
docker compose logs -f app
docker compose logs -f queue

# Artisan inside app
docker compose exec app php artisan migrate --force
docker compose exec app php artisan queue:restart
docker compose exec app php artisan config:cache

# Rebuild after code changes
docker compose up -d --build

# Stop
docker compose down

# Stop and wipe DB/Redis volumes (destructive)
docker compose down -v
```

### 1.9 Production checklist

- [ ] `/opt/faqhub` exists and is writable by UID/GID `1000`
- [ ] Strong `APP_KEY`, DB passwords, Reverb secrets
- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_URL` / `REVERB_*` match public HTTPS domains
- [ ] OAuth redirect URLs allow your `APP_URL`
- [ ] Firewall: do not expose MySQL/Redis to the public internet
- [ ] `/up` returns HTTP 200
- [ ] Queue worker processes jobs
- [ ] File uploads land under `${FAQHUB_STATIC_PATH}/storage`
- [ ] `queue` and `scheduler` containers are running (check logs)
- [ ] `reverb` container is healthy

---

## 2. Local development

Local stack uses `docker-compose.dev.yml`:

- Source code bind-mounted for live PHP edits
- Nginx + PHP-FPM
- Vite HMR
- Mailpit for emails
- Xdebug available (off by default)

### 2.1 First-time setup

```bash
git clone <repo-url> FaqHub-Backend
cd FaqHub-Backend

cp .env.docker.example .env
```

Edit `.env` for local use. Suggested values:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080
APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=

FAQHUB_STATIC_PATH=./data/faqhub

NGINX_PORT=8080
PHP_FPM_PORT=9000
MYSQL_PORT=3306
REDIS_PORT_PUBLISH=6379
REVERB_PUBLISH_PORT=8081
VITE_PORT=5173
MAILPIT_SMTP_PORT=1025
MAILPIT_UI_PORT=8025

RUN_MIGRATIONS=true
CACHE_CONFIG=false
```

Create the local static directory:

```bash
# Linux / macOS
mkdir -p data/faqhub/avatars data/faqhub/storage data/faqhub/sitemaps

# Windows (PowerShell)
New-Item -ItemType Directory -Force -Path data\faqhub\avatars, data\faqhub\storage, data\faqhub\sitemaps
```

> On Windows, prefer `FAQHUB_STATIC_PATH=./data/faqhub`. On Linux, you may use `/opt/faqhub` if you create it on the host.
>
> Set `APP_URL=http://localhost:8080` in `.env` for local Docker dev (nginx listens on port 8080).

### 2.2 Build and run

```bash
docker compose -f docker-compose.dev.yml up -d --build
```

First start may take longer while the image builds and migrations run.

The dev image ships with production Composer dependencies (`--no-dev`). If you previously ran `composer install` on the host, delete stale bootstrap cache files **before** the first Docker start (see [§5 Troubleshooting](#5-troubleshooting)).

Follow logs:

```bash
docker compose -f docker-compose.dev.yml logs -f
```

### 2.3 Local URLs / ports

| URL / port | Service |
|---|---|
| http://localhost:8080 | App (Nginx → PHP-FPM) |
| http://localhost:8080/up | Health check |
| http://localhost:5173 | Vite HMR |
| http://localhost:8025 | Mailpit UI |
| `localhost:1025` | Mailpit SMTP |
| `localhost:8081` | Reverb WebSockets |
| `localhost:3306` | MySQL |
| `localhost:6379` | Redis |
| `localhost:9000` | PHP-FPM |
| `localhost:9003` | Xdebug |

### 2.4 Verify local stack

```bash
docker compose -f docker-compose.dev.yml ps

curl -fsS http://localhost:8080/up
curl -fsS http://localhost:8080/healthz   # nginx-only probe (returns "ok")

docker compose -f docker-compose.dev.yml exec app php artisan about
docker compose -f docker-compose.dev.yml exec app php artisan migrate:status
docker compose -f docker-compose.dev.yml exec app php artisan queue:monitor redis:default
docker compose -f docker-compose.dev.yml logs queue scheduler reverb --tail 20
```

On Windows PowerShell, use `curl.exe` instead of `curl` (which aliases to `Invoke-WebRequest`):

```powershell
curl.exe -fsS http://localhost:8080/up
```

### 2.5 Day-to-day development commands

```bash
# Rebuild images after Dockerfile / dependency changes
docker compose -f docker-compose.dev.yml build
docker compose -f docker-compose.dev.yml up -d

# Restart a single service
docker compose -f docker-compose.dev.yml restart queue

# Shell / Artisan
docker compose -f docker-compose.dev.yml exec app sh
docker compose -f docker-compose.dev.yml exec app php artisan migrate
docker compose -f docker-compose.dev.yml exec app php artisan test

# Enable Xdebug for a session
XDEBUG_MODE=debug docker compose -f docker-compose.dev.yml up -d app

# Stop
docker compose -f docker-compose.dev.yml down

# Stop + delete DB/Redis volumes
docker compose -f docker-compose.dev.yml down -v
```

### 2.6 Local notes

- PHP code changes are live (bind mount). Restart `queue` / `reverb` after job or broadcasting changes.
- Frontend asset changes go through the `vite` service.
- Mail is captured by Mailpit (`MAIL_HOST=mailpit`).
- Do not commit `.env`. Keep secrets out of git.
- If host ports collide (e.g. `8080` already used), change `NGINX_PORT` / `REVERB_PUBLISH_PORT` in `.env`.

---

## 3. Image build details

Multi-stage `Dockerfile` targets:

| Target | Used by | Description |
|---|---|---|
| `app` | Production compose | Optimized PHP runtime + built assets + Composer `--no-dev` |
| `nginx` | Dev compose (via `nginx:1.27-alpine` + `docker/nginx/*.conf`) | Reverse proxy to PHP-FPM; not a separate production service |
| `app-dev` | Dev compose | Adds Composer, Node, Xdebug |

Manual build examples:

```bash
# Production app image
docker build --target app -t faqhub-app:latest .

# Development image
docker build --target app-dev -t faqhub-app-dev:latest .
```

---

## 4. Static files

| Environment | Host path | Container path |
|---|---|---|
| Production / Dokploy | `${FAQHUB_STATIC_PATH}/storage` | `/var/www/html/storage/app/public` |
| Production / Dokploy | `${FAQHUB_STATIC_PATH}/sitemaps` | `/var/www/html/public/sitemaps` |
| Local dev (recommended) | `./data/faqhub` | `/opt/faqhub` (extra mount; uploads also use bind-mounted project `storage/`) |

The entrypoint runs `php artisan storage:link`, which creates `public/storage` → `storage/app/public`. Uploads (e.g. avatars) persist on the host across container rebuilds.

---

## 5. Troubleshooting

| Symptom | What to check |
|---|---|
| `app` unhealthy / won’t start | `docker compose logs app`; MySQL/Redis healthy?; valid `APP_KEY` |
| `Class "Laravel\Pail\PailServiceProvider" not found` (dev) | Host `bootstrap/cache/packages.php` lists dev packages but the Docker `vendor` volume has `--no-dev` deps. Delete stale cache, then restart: `rm -f bootstrap/cache/packages.php bootstrap/cache/services.php` (PowerShell: `Remove-Item bootstrap/cache/packages.php, bootstrap/cache/services.php -ErrorAction SilentlyContinue`), then `docker compose -f docker-compose.dev.yml restart app queue scheduler reverb`. To install full dev deps inside the container: `docker compose -f docker-compose.dev.yml run --rm --no-deps --entrypoint sh app -c "composer install"` |
| MySQL restart loop | Remove invalid MySQL 8.4 flags; recreate volume with `down -v` only if disposable |
| Port already allocated | Change `APP_PORT` / `NGINX_PORT` / `REVERB_PUBLISH_PORT` in `.env` |
| Uploads missing after deploy | Host bind paths exist? `FAQHUB_STATIC_PATH` correct? permissions `1000:1000`? |
| Queue not processing | `QUEUE_CONNECTION=redis`, Redis up, `docker compose logs queue` |
| Reverb clients fail | `REVERB_HOST` / scheme / public port match browser URL; firewall/proxy WS support |
| Dev vendor missing | Wait for first start, or `docker compose -f docker-compose.dev.yml run --rm --no-deps --entrypoint sh app -c "composer install"` |
| Nginx fails to start (dev) | Ensure `docker/nginx/nginx.conf` and `docker/nginx/default.conf` exist in the repo |

---

## 6. Quick command cheat sheet

```bash
# --- Production ---
docker compose up -d --build
docker compose ps
curl -fsS http://127.0.0.1:8000/up
docker compose logs -f app

# --- Local development ---
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml ps
curl -fsS http://localhost:8080/up
docker compose -f docker-compose.dev.yml logs -f
```
