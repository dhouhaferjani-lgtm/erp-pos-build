# Staging Environment Setup — Dokploy

> Create these 10 services in the **staging** environment of the **New ERP** project.
> Each service mirrors production exactly. Branch: `dev`. Domain: `erp.otospex.dev`.

## Shared Environment Variables

Set these in the Dokploy project environment (inherited by all services):

```env
APP_KEY=base64:... (generate with: php artisan key:generate --show)
DB_PASSWORD=<strong-password>
REDIS_PASSWORD=<strong-password>
MEILISEARCH_KEY=<strong-key>
AWS_ACCESS_KEY_ID=<minio-user>
AWS_SECRET_ACCESS_KEY=<minio-password>
REVERB_APP_KEY=<generate-random>
REVERB_APP_SECRET=<generate-random>
```

---

## Infrastructure Services (Docker Image)

### 1. ERP Staging PostgreSQL

| Setting | Value |
|---------|-------|
| **Type** | Docker |
| **Image** | `timescale/timescaledb:2.13.0-pg16` |
| **Environment** | `POSTGRES_DB=autoerp`, `POSTGRES_USER=autoerp`, `POSTGRES_PASSWORD=${DB_PASSWORD}` |
| **Volume** | Mount `/var/lib/postgresql/data` |
| **Healthcheck** | `pg_isready -U autoerp` |

### 2. ERP Staging Redis

| Setting | Value |
|---------|-------|
| **Type** | Docker |
| **Image** | `redis:7-alpine` |
| **Command** | `redis-server --requirepass ${REDIS_PASSWORD}` |
| **Volume** | Mount `/data` |
| **Healthcheck** | `redis-cli -a ${REDIS_PASSWORD} ping` |

### 3. ERP Staging PgBouncer

| Setting | Value |
|---------|-------|
| **Type** | Docker |
| **Image** | `edoburu/pgbouncer:latest` |
| **Environment** | See below |
| **Depends on** | PostgreSQL (healthy) |
| **Healthcheck** | `pg_isready -h localhost -p 5432` |

```env
DATABASE_URL=postgres://autoerp:${DB_PASSWORD}@<postgres-hostname>:5432/autoerp
AUTH_TYPE=scram-sha-256
POOL_MODE=transaction
DEFAULT_POOL_SIZE=20
MAX_CLIENT_CONN=200
IGNORE_STARTUP_PARAMETERS=extra_float_digits
```

> Replace `<postgres-hostname>` with the Docker hostname Dokploy assigns to the PostgreSQL service.

### 4. ERP Staging Meilisearch

| Setting | Value |
|---------|-------|
| **Type** | Docker |
| **Image** | `getmeili/meilisearch:v1.6` |
| **Environment** | `MEILI_MASTER_KEY=${MEILISEARCH_KEY}`, `MEILI_ENV=production` |
| **Volume** | Mount `/meili_data` |
| **Healthcheck** | `curl -f http://localhost:7700/health` |

### 5. ERP Staging MinIO

| Setting | Value |
|---------|-------|
| **Type** | Docker |
| **Image** | `minio/minio:latest` |
| **Command** | `server /data --console-address ":9001"` |
| **Environment** | `MINIO_ROOT_USER=${AWS_ACCESS_KEY_ID}`, `MINIO_ROOT_PASSWORD=${AWS_SECRET_ACCESS_KEY}` |
| **Volume** | Mount `/data` |
| **Healthcheck** | `curl -f http://localhost:9000/minio/health/live` |

---

## Application Services (GitHub — branch: `dev`)

All application services use the same repo and environment block. Set these env vars on each:

```env
APP_ENV=staging
APP_DEBUG=true
APP_KEY=${APP_KEY}
APP_URL=https://api.erp.otospex.dev
FRONTEND_URL=https://erp.otospex.dev
DB_CONNECTION=pgsql
DB_HOST=<postgres-hostname>
DB_PORT=5432
DB_DATABASE=autoerp
DB_USERNAME=autoerp
DB_PASSWORD=${DB_PASSWORD}
REDIS_HOST=<redis-hostname>
REDIS_PASSWORD=${REDIS_PASSWORD}
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb
MEILISEARCH_HOST=http://<meilisearch-hostname>:7700
MEILISEARCH_KEY=${MEILISEARCH_KEY}
AWS_ENDPOINT=http://<minio-hostname>:9000
AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID}
AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY}
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=autoerp
AWS_USE_PATH_STYLE_ENDPOINT=true
REVERB_APP_ID=autoerp-staging
REVERB_APP_KEY=${REVERB_APP_KEY}
REVERB_APP_SECRET=${REVERB_APP_SECRET}
REVERB_SERVER_HOST=<websocket-hostname>
REVERB_SERVER_PORT=8080
LOG_CHANNEL=stack
LOG_LEVEL=debug
AUTO_SEED=true
```

> Replace `<*-hostname>` with the Docker hostnames Dokploy assigns to each infrastructure service.

### 6. ERP Staging API

| Setting | Value |
|---------|-------|
| **Type** | GitHub app |
| **Repo** | `otospexsolutions/erp` |
| **Branch** | `dev` |
| **Dockerfile** | `apps/api/Dockerfile` |
| **Build context** | `apps/api` |
| **Target** | `api` |
| **Extra env** | `CONTAINER_ROLE=api` |
| **Domain** | `api.erp.otospex.dev` (HTTPS) |
| **Healthcheck** | `curl -f http://localhost/health` (start period: 60s) |

> This service runs migrations on startup. All other app services depend on it.

### 7. ERP Staging Worker

| Setting | Value |
|---------|-------|
| **Type** | GitHub app |
| **Repo** | `otospexsolutions/erp` |
| **Branch** | `dev` |
| **Dockerfile** | `apps/api/Dockerfile` |
| **Build context** | `apps/api` |
| **Target** | `worker` |
| **Memory limit** | 512M |
| **Depends on** | API (healthy), Redis (healthy) |

### 8. ERP Staging Scheduler

| Setting | Value |
|---------|-------|
| **Type** | GitHub app |
| **Repo** | `otospexsolutions/erp` |
| **Branch** | `dev` |
| **Dockerfile** | `apps/api/Dockerfile` |
| **Build context** | `apps/api` |
| **Target** | `scheduler` |
| **Depends on** | API (healthy) |

### 9. ERP Staging WebSocket

| Setting | Value |
|---------|-------|
| **Type** | GitHub app |
| **Repo** | `otospexsolutions/erp` |
| **Branch** | `dev` |
| **Dockerfile** | `apps/api/Dockerfile` |
| **Build context** | `apps/api` |
| **Target** | `websocket` |
| **Depends on** | Redis (healthy) |
| **Healthcheck** | `curl -f http://localhost:8080/` (start period: 15s) |

### 10. ERP Staging Web

| Setting | Value |
|---------|-------|
| **Type** | GitHub app |
| **Repo** | `otospexsolutions/erp` |
| **Branch** | `dev` |
| **Dockerfile** | `apps/web/Dockerfile` |
| **Build context** | `.` (repo root) |
| **Build arg** | `VITE_API_URL=https://api.erp.otospex.dev` |
| **Extra env** | `API_URL=https://api.erp.otospex.dev` |
| **Domain** | `erp.otospex.dev` (HTTPS) |
| **Depends on** | API |
| **Healthcheck** | `wget -q --spider http://localhost/health` |

---

## Setup Order

1. Create infrastructure services first: PostgreSQL → Redis → PgBouncer → Meilisearch → MinIO
2. Wait for all to be healthy (green)
3. Note down the Docker hostnames Dokploy assigns to each
4. Create app services: API → Worker, Scheduler, WebSocket → Web
5. Set the `<*-hostname>` placeholders in env vars to actual Docker hostnames
6. Assign domains: `api.erp.otospex.dev` to API, `erp.otospex.dev` to Web
7. Enable HTTPS/SSL on both domains

## Differences from Production

| Setting | Production | Staging |
|---------|-----------|---------|
| `APP_ENV` | `production` | `staging` |
| `APP_DEBUG` | `false` | `true` |
| `APP_URL` | `https://api.riserpos.app` | `https://api.erp.otospex.dev` |
| `FRONTEND_URL` | `https://riserpos.app` | `https://erp.otospex.dev` |
| `REVERB_APP_ID` | `autoerp` | `autoerp-staging` |
| Branch | `main` | `dev` |
