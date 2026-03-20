# Logging & Log Aggregation

> Current state and recommendations for production log management. Last updated: 2026-03-20.

---

## Current State

| Source | Destination | Persistent? |
|--------|-------------|-------------|
| Laravel application errors | **Sentry** (via `sentry-laravel`) | Yes |
| Laravel info/warning/debug | `storage/logs/laravel.log` inside container | **No** -- lost on container restart |
| Nginx access/error logs | Container stdout/stderr | **No** -- ephemeral |
| PostgreSQL logs | Container stdout | **No** -- ephemeral |
| Queue worker output | Container stdout | **No** -- ephemeral |

The main gap: non-error application logs (info, warning, debug) and infrastructure logs (nginx, postgres, queue) are ephemeral. When a container restarts or redeploys, those logs are gone.

---

## Step 1: Docker Log Rotation (Immediate)

Prevent disk exhaustion by configuring the json-file log driver with rotation. Add to every service in your Docker Compose / Dokploy stack:

```yaml
services:
  app:
    image: erp-app:latest
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "5"

  queue-worker:
    image: erp-app:latest
    command: php artisan horizon
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "5"

  postgres:
    image: postgres:16-alpine
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "5"

  nginx:
    image: nginx:alpine
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "5"

  redis:
    image: redis:7-alpine
    logging:
      driver: "json-file"
      options:
        max-size: "5m"
        max-file: "3"

  websocket:
    image: erp-app:latest
    command: php artisan reverb:start
    logging:
      driver: "json-file"
      options:
        max-size: "10m"
        max-file: "5"
```

Or set it as the default for the entire Docker daemon in `/etc/docker/daemon.json`:

```json
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "5"
  }
}
```

Then restart Docker: `sudo systemctl restart docker`.

---

## Step 2: Laravel Log Channel Configuration

Send application logs to `stderr` so Docker captures them (instead of writing to a file inside the container):

```php
// config/logging.php — set LOG_CHANNEL=stderr in production .env
'stderr' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'info'),
    'handler' => StreamHandler::class,
    'with' => [
        'stream' => 'php://stderr',
    ],
    'formatter' => env('LOG_STDERR_FORMATTER'),
],
```

In `.env.production`:

```dotenv
LOG_CHANNEL=stderr
LOG_LEVEL=warning
```

This way `docker logs erp-app` shows application logs alongside nginx/php-fpm output, and the json-file driver rotation applies.

---

## Step 3: Viewing Logs (Current Tooling)

Without a centralized aggregator, use these commands on the Docker host:

```bash
# Tail application logs
docker logs -f --tail 100 erp-app

# Tail all services
docker service logs -f erp_app

# Search for errors across all containers
docker logs erp-app 2>&1 | grep -i "error\|exception\|fatal" | tail -50

# PostgreSQL slow queries (if log_min_duration_statement is set)
docker logs erp-postgres 2>&1 | grep "duration:" | tail -20

# Queue failures
docker logs erp-queue-worker 2>&1 | grep -i "failed\|exception" | tail -20
```

---

## Step 4: Centralized Aggregation (Future)

When the team or log volume grows, consider one of these lightweight options:

### Option A: Loki + Grafana (Recommended)

Lightweight, fits Docker Swarm well, pairs with existing Grafana if monitoring is added.

```yaml
# Add to docker-compose
services:
  loki:
    image: grafana/loki:2.9.0
    ports:
      - "3100:3100"
    volumes:
      - loki_data:/loki
    logging:
      driver: "json-file"
      options:
        max-size: "5m"
        max-file: "3"

  promtail:
    image: grafana/promtail:2.9.0
    volumes:
      - /var/log:/var/log
      - /var/lib/docker/containers:/var/lib/docker/containers:ro
      - ./promtail-config.yml:/etc/promtail/config.yml
    command: -config.file=/etc/promtail/config.yml
```

### Option B: Docker Loki Log Driver

Even simpler -- no Promtail needed. Install the Loki Docker driver plugin:

```bash
docker plugin install grafana/loki-docker-driver:latest --alias loki --grant-all-permissions
```

Then use it per service:

```yaml
services:
  app:
    logging:
      driver: loki
      options:
        loki-url: "http://localhost:3100/loki/api/v1/push"
        loki-batch-size: "400"
```

### Option C: Ship to Sentry (Quick Win)

Already have Sentry. Upgrade the plan or self-hosted instance to ingest log events beyond just exceptions. Configure Monolog to send warning+ to Sentry:

```php
// config/logging.php
'stack' => [
    'driver' => 'stack',
    'channels' => ['stderr', 'sentry'],
],
'sentry' => [
    'driver' => 'sentry',
    'level' => 'warning',
],
```

---

## Priority Order

1. **Now**: Add json-file log rotation to all services (prevents disk fill, zero cost).
2. **Now**: Switch Laravel to `LOG_CHANNEL=stderr` in production.
3. **Soon**: Set `log_min_duration_statement = 1000` in PostgreSQL to capture slow queries.
4. **Later**: Deploy Loki + Grafana when you need cross-service search or dashboards.
