#!/bin/sh
set -e

echo "Starting AutoERP API..."

# Wait for PHP-FPM socket directory to exist
mkdir -p /var/run

# Create log directories if they don't exist
mkdir -p /var/log/php /var/log/supervisor /var/log/nginx

# Verify critical files exist
if [ ! -f /var/www/html/public/index.php ]; then
    echo "ERROR: /var/www/html/public/index.php not found!"
    echo "Contents of /var/www/html:"
    ls -la /var/www/html/
    echo "Contents of /var/www/html/public:"
    ls -la /var/www/html/public/ 2>/dev/null || echo "public directory missing!"
    exit 1
fi

# Change to app directory
cd /var/www/html

# Clear and rebuild cache with runtime environment variables
echo "Clearing Laravel caches..."
php artisan config:clear 2>/dev/null || true
php artisan route:clear 2>/dev/null || true
php artisan view:clear 2>/dev/null || true

# Rebuild caches with actual environment variables
echo "Building Laravel caches..."
php artisan config:cache || echo "Warning: config:cache failed"
php artisan route:cache || echo "Warning: route:cache failed"
php artisan view:cache || echo "Warning: view:cache failed"

# Link storage if not linked
if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link 2>/dev/null || true
fi

# Fix permissions
chown -R www:www /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "Laravel caches rebuilt successfully"
echo "Starting supervisord..."

# Start supervisord
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
