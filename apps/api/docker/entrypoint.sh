#!/bin/sh
set -e

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

# Change to app directory
cd /var/www/html

# Clear and rebuild cache with runtime environment variables
echo ""
echo "Clearing Laravel caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

# Rebuild caches with actual environment variables
echo "Building Laravel caches..."
if ! php artisan config:cache; then
    echo "ERROR: config:cache failed! Check your environment variables."
    echo "Continuing without config cache..."
fi

if ! php artisan route:cache; then
    echo "WARNING: route:cache failed, continuing without route cache..."
fi

if ! php artisan view:cache; then
    echo "WARNING: view:cache failed, continuing without view cache..."
fi

# Link storage if not linked
if [ ! -L /var/www/html/public/storage ]; then
    echo "Linking storage..."
    php artisan storage:link 2>/dev/null || true
fi

# Fix permissions
echo "Setting permissions..."
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Test database connection with retry
echo ""
echo "Testing database connection..."
DB_CONNECTED=false
for i in 1 2 3 4 5; do
    if php artisan db:monitor --max=1 2>/dev/null; then
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
fi

# Enable Horizon in supervisor config if Redis is available
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/supervisord.conf"
if [ "$REDIS_AVAILABLE" = "true" ]; then
    echo ""
    echo "Enabling Horizon (Redis available)..."
    # Enable horizon by changing autostart=false to autostart=true for horizon program
    sed -i '/\[program:horizon\]/,/^\[/{s/autostart=false/autostart=true/}' "$SUPERVISOR_CONFIG" 2>/dev/null || true
fi

echo ""
echo "========================================"
echo "Laravel ready, starting supervisord..."
echo "========================================"

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
