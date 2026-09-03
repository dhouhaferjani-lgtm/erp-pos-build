#!/bin/sh
set -e

if [ "${1:-}" = "--check-only" ]; then
    SCRIPT_DIR="$(CDPATH= cd "$(dirname "$0")" && pwd)"
    cd "$SCRIPT_DIR/.."
    php artisan config:cache
    . "$SCRIPT_DIR/verify-cache-store.sh"
    exit 0
fi

echo "========================================"
echo "Starting AutoERP Worker (Horizon)..."
echo "========================================"
echo "Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo ""

cd /var/www/html

# Fix storage permissions
mkdir -p storage/logs
touch storage/logs/laravel.log
chown -R www:www storage bootstrap/cache 2>/dev/null || true
chmod -R 775 storage bootstrap/cache 2>/dev/null || true

# Wait for Redis
echo "Waiting for Redis..."
REDIS_HOST="${REDIS_HOST:-redis}"
REDIS_PORT="${REDIS_PORT:-6379}"
until nc -z -w2 "$REDIS_HOST" "$REDIS_PORT" 2>/dev/null; do
    echo "  Redis not ready, retrying in 2s..."
    sleep 2
done
echo "  Redis: [reachable]"

# Wait for database
echo "Waiting for database..."
for i in 1 2 3 4 5 6 7 8 9 10; do
    if php artisan db:monitor --max=1 2>/dev/null; then
        echo "  Database: [connected]"
        break
    fi
    echo "  Database: attempt $i failed, retrying in 3s..."
    sleep 3
done

# Cache config for performance
echo "Building config cache..."
php artisan config:cache 2>/dev/null || true

# Fail closed: the default cache store must serve tenant-tagged operations.
. /var/www/html/docker/verify-cache-store.sh

echo ""
echo "========================================"
echo "Starting Horizon..."
echo "========================================"

# Run as www user
exec su-exec www php artisan horizon
