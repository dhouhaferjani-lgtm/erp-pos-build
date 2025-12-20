#!/bin/sh
set -e

echo "========================================"
echo "Starting AutoERP API..."
echo "========================================"

# Wait for PHP-FPM socket directory to exist
mkdir -p /var/run

# Create log directories if they don't exist
mkdir -p /var/log/php /var/log/supervisor /var/log/nginx

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

if [ -z "$REDIS_HOST" ]; then
    echo "WARNING: REDIS_HOST is not set, using default 'localhost'"
fi
echo "  REDIS_HOST: ${REDIS_HOST:-localhost}"

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

# Test database connection (non-blocking)
echo ""
echo "Testing database connection..."
if php artisan db:monitor --max=1 2>/dev/null; then
    echo "  Database: [connected]"
else
    echo "  Database: [not available yet - will retry on first request]"
fi

echo ""
echo "========================================"
echo "Laravel ready, starting supervisord..."
echo "========================================"

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
