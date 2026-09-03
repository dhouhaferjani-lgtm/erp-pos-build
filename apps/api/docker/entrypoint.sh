#!/bin/sh
set -e

if [ "${1:-}" = "--check-only" ]; then
    SCRIPT_DIR="$(CDPATH= cd "$(dirname "$0")" && pwd)"
    cd "$SCRIPT_DIR/.."
    trap 'php artisan config:clear >/dev/null 2>&1 || true' EXIT
    php artisan config:cache
    . "$SCRIPT_DIR/verify-cache-store.sh"
    exit 0
fi

echo "========================================"
echo "Starting AutoERP API..."
echo "========================================"
echo "Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Create required directories with proper permissions
mkdir -p /var/run
mkdir -p /var/log/php /var/log/supervisor /var/log/nginx
chown -R www:www /var/log/php /var/log/supervisor /var/log/nginx 2>/dev/null || true
chmod 755 /var/run

# Verify critical environment variables
echo "Checking environment variables..."
if [ -z "$APP_KEY" ]; then
    echo "ERROR: APP_KEY environment variable is not set!"
    echo "Please set APP_KEY in your Dokploy environment variables."
    echo "Generate one with: php artisan key:generate --show"
    exit 1
fi
echo "  APP_KEY: [set]"

if [ -z "$DB_HOST" ]; then
    echo "WARNING: DB_HOST is not set, using default 'localhost'"
fi
echo "  DB_HOST: ${DB_HOST:-localhost}"
echo "  DB_DATABASE: ${DB_DATABASE:-autoerp}"

# Check Redis availability
REDIS_AVAILABLE=false
if [ -n "$REDIS_HOST" ]; then
    echo "  REDIS_HOST: $REDIS_HOST"
    # Test Redis connection (timeout after 2 seconds)
    if nc -z -w2 "$REDIS_HOST" "${REDIS_PORT:-6379}" 2>/dev/null; then
        echo "  Redis: [reachable]"
        REDIS_AVAILABLE=true
    else
        echo "  Redis: [not reachable - Horizon will remain disabled]"
    fi
else
    echo "  REDIS_HOST: not set - Horizon will remain disabled"
fi

# Verify critical files exist
if [ ! -f /var/www/html/public/index.php ]; then
    echo "ERROR: /var/www/html/public/index.php not found!"
    echo "Contents of /var/www/html:"
    ls -la /var/www/html/
    echo "Contents of /var/www/html/public:"
    ls -la /var/www/html/public/ 2>/dev/null || echo "public directory missing!"
    exit 1
fi
echo "  public/index.php: [exists]"

# Verify file permissions for www user
echo ""
echo "Verifying file permissions..."
echo "  Owner of public/index.php: $(ls -la /var/www/html/public/index.php | awk '{print $3":"$4}')"
echo "  Public dir permissions: $(ls -ld /var/www/html/public | awk '{print $1}')"

# Test PHP-FPM socket directory
if [ -d /var/run ]; then
    echo "  /var/run: [exists]"
else
    echo "  /var/run: [creating]"
    mkdir -p /var/run
fi

# Change to app directory
cd /var/www/html

# Clear any stale caches from the image build
echo ""
echo "Clearing Laravel caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

# Link storage if not linked
if [ ! -L /var/www/html/public/storage ]; then
    echo "Linking storage..."
    php artisan storage:link 2>/dev/null || true
fi

# Fix permissions (artisan commands above run as root and may create root-owned files)
echo "Setting permissions..."
touch /var/www/html/storage/logs/laravel.log
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# ---------------------------------------------------------------------------
# Migrations & Seeding — connect directly to PostgreSQL, not PgBouncer.
# PgBouncer in transaction mode doesn't support advisory locks used by
# Laravel's migration runner.  DB_DIRECT_HOST defaults to "postgres"
# (the docker-compose service name).
# ---------------------------------------------------------------------------
DIRECT_DB_HOST="${DB_DIRECT_HOST:-postgres}"

echo ""
echo "Testing database connection (direct -> $DIRECT_DB_HOST)..."
DB_CONNECTED=false
for i in 1 2 3 4 5; do
    # No config cache yet, so env() reads live env vars.  Override DB_HOST
    # so artisan talks to PostgreSQL directly instead of PgBouncer.
    if DB_HOST="$DIRECT_DB_HOST" php artisan db:monitor --max=1 2>/dev/null; then
        echo "  Database: [connected]"
        DB_CONNECTED=true
        break
    else
        echo "  Database: attempt $i failed, retrying in 2s..."
        sleep 2
    fi
done

if [ "$DB_CONNECTED" = "false" ]; then
    echo "  Database: [not available - app may have issues until DB is ready]"
else
    echo ""
    echo "Running database migrations (direct -> $DIRECT_DB_HOST)..."
    if DB_HOST="$DIRECT_DB_HOST" php artisan migrate --force; then
        echo "  Migrations: [completed successfully]"
    else
        echo "  Migrations: [failed - check logs]"
    fi

    # -----------------------------------------------------------------------
    # Roll pending TENANT-scoped migrations across every existing per-tenant
    # database. The central `migrate --force` above only touches the central
    # DB; tenant migrations otherwise run ONLY at signup, so tenants provisioned
    # before a migration was added never receive it (e.g.
    # document_lines.free_quantity / is_bonus_line), causing runtime 500s.
    # `tenants:migrate-rolling` is idempotent (already-migrated tenants are a
    # no-op) and isolates per-tenant failures. Uses the DIRECT db host because
    # migration advisory locks + per-tenant DDL must bypass PgBouncer.
    # -----------------------------------------------------------------------
    echo ""
    echo "Rolling tenant migrations across existing tenant databases (direct -> $DIRECT_DB_HOST)..."
    if DB_HOST="$DIRECT_DB_HOST" php artisan tenants:migrate-rolling --force; then
        echo "  Tenant migrations: [completed]"
    else
        echo "  Tenant migrations: [completed with per-tenant errors - check logs]"
    fi

    # Sync the canonical role/permission catalog across all tenant databases.
    # OPT-IN (SYNC_PERMISSIONS_ON_BOOT=true): RolesAndPermissionsSeeder uses
    # syncPermissions, which resets BUILT-IN roles to the canonical set — safe
    # on staging, but a product decision for production tenants that may have
    # customized built-in roles. Closes the recurring "new permission missing
    # on existing tenants -> 403" gap (uom.view 2026-06; loyalty.enroll 2026-07).
    if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then
        echo ""
        echo "Syncing role/permission catalog across tenant databases (direct -> $DIRECT_DB_HOST)..."
        if DB_HOST="$DIRECT_DB_HOST" php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'; then
            echo "  Permission sync: [completed]"
        else
            echo "  Permission sync: [completed with per-tenant errors - check logs]"
        fi
    fi

    # Flush the shared Spatie permission cache (key spatie.permission.cache,
    # default/redis store, 24h TTL) so role/permission grants applied out of
    # band to existing tenants take effect immediately after deploy instead of
    # serving a stale cached snapshot. Never blocks boot.
    php artisan permission:cache-reset 2>/dev/null || true

    # Run seeders only if AUTO_SEED is set to true (prevents re-seeding on every restart)
    if [ "$AUTO_SEED" = "true" ]; then
        echo ""
        echo "Running database seeders..."

        TENANT_COUNT=$(DB_HOST="$DIRECT_DB_HOST" php artisan tinker --execute="echo \App\Modules\Tenant\Domain\Tenant::count();" 2>/dev/null || echo "0")

        if [ "$TENANT_COUNT" = "0" ]; then
            echo "  Database is empty, running initial seed..."
            if DB_HOST="$DIRECT_DB_HOST" php artisan db:seed --force; then
                echo "  Seeding: [completed successfully]"
            else
                echo "  Seeding: [failed - check logs]"
            fi
        else
            echo "  Database already contains data ($TENANT_COUNT tenants), skipping seed"
            echo "  To force reseed, run: php artisan db:seed --force manually"
        fi
    else
        echo ""
        echo "  AUTO_SEED not enabled, skipping database seeding"
        echo "  To enable automatic seeding on first deploy, set AUTO_SEED=true"
    fi

    # Tunisia parapharmacy DEMO tenant (DemoPharmacySeeder). Independent of
    # AUTO_SEED. The seeder is additive/idempotent — it provisions the
    # demo-pharmacy-tn tenant on first run and is a safe no-op afterwards, so
    # it can run on every deploy. Uses the DIRECT db host because provisioning
    # the per-tenant database is DDL (must not go through PgBouncer).
    if [ "$SEED_DEMO_PHARMACY" = "true" ]; then
        echo ""
        echo "Seeding Tunisia parapharmacy demo (DemoPharmacySeeder, idempotent)..."
        if DB_HOST="$DIRECT_DB_HOST" php artisan db:seed --class=DemoPharmacySeeder --force; then
            echo "  Demo seed: [completed]"
        else
            echo "  Demo seed: [failed - check logs]"
        fi
    fi
fi

# ---------------------------------------------------------------------------
# Build config/route/view caches for runtime (with PgBouncer as DB_HOST)
# ---------------------------------------------------------------------------
echo ""
echo "Building Laravel caches..."
if ! php artisan config:cache; then
    echo "ERROR: config:cache failed! Check your environment variables."
    echo "Continuing without config cache..."
fi

# Fail closed: the default cache store must serve tenant-tagged operations.
. /var/www/html/docker/verify-cache-store.sh

if ! php artisan route:cache; then
    echo "WARNING: route:cache failed, continuing without route cache..."
fi

if ! php artisan view:cache; then
    echo "WARNING: view:cache failed, continuing without view cache..."
fi

# Fix permissions again after cache rebuild
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Configure services based on CONTAINER_ROLE
# In split mode (CONTAINER_ROLE=api), only nginx + php-fpm run.
# In bundled mode (default, no CONTAINER_ROLE), Horizon/Reverb/Scheduler also run.
CONTAINER_ROLE="${CONTAINER_ROLE:-bundled}"
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/supervisord.conf"

if [ "$CONTAINER_ROLE" = "api" ]; then
    echo ""
    echo "Running in split-container mode (api only)..."
    echo "  Horizon, Reverb, and Scheduler run in dedicated containers."

    # Update nginx config if REVERB_SERVER_HOST is set (proxy to external websocket container)
    if [ -n "${REVERB_SERVER_HOST:-}" ]; then
        echo "  Reverb proxy → $REVERB_SERVER_HOST:8080"
        sed -i "s|http://127.0.0.1:8080|http://${REVERB_SERVER_HOST}:8080|" /etc/nginx/http.d/default.conf
    fi
else
    echo ""
    echo "Running in bundled mode (all services in one container)..."

    # Enable Horizon in supervisor config if Redis is available
    if [ "$REDIS_AVAILABLE" = "true" ]; then
        echo "  Enabling Horizon (Redis available)..."
        sed -i '/\[program:horizon\]/,/^\[/{s/autostart=false/autostart=true/}' "$SUPERVISOR_CONFIG" 2>/dev/null || true
    fi

    # Enable Reverb WebSocket server if BROADCAST_CONNECTION is reverb
    if [ "${BROADCAST_CONNECTION:-}" = "reverb" ]; then
        echo "  Enabling Reverb WebSocket server..."
        sed -i '/\[program:reverb\]/,/^\[/{s/autostart=false/autostart=true/}' "$SUPERVISOR_CONFIG" 2>/dev/null || true
    fi

    # Enable Scheduler in bundled mode
    echo "  Enabling Scheduler..."
    sed -i '/\[program:scheduler\]/,/^\[/{s/autostart=false/autostart=true/}' "$SUPERVISOR_CONFIG" 2>/dev/null || true
fi

echo ""
echo "========================================"
echo "Laravel ready, starting supervisord..."
echo "========================================"

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
