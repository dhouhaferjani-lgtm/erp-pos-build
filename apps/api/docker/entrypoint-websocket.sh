#!/bin/sh
set -e

echo "========================================"
echo "Starting AutoERP WebSocket (Reverb)..."
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

# Cache config for performance
echo "Building config cache..."
php artisan config:cache 2>/dev/null || true

echo ""
echo "========================================"
echo "Starting Reverb on 0.0.0.0:8080..."
echo "========================================"

# Run as www user
exec su-exec www php artisan reverb:start --host=0.0.0.0 --port=8080
