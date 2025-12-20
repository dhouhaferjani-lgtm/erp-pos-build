# =============================================================================
# AutoERP API - Production Dockerfile
# Multi-stage build optimized for Dokploy deployment
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: Composer dependencies
# -----------------------------------------------------------------------------
FROM php:8.4-cli-alpine AS composer-deps

# Install dependencies needed for composer
RUN apk add --no-cache unzip git

# Install composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Install dependencies without dev packages
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist

# -----------------------------------------------------------------------------
# Stage 2: Build the production image
# -----------------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS production

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    # Build dependencies
    $PHPIZE_DEPS \
    # PostgreSQL client
    postgresql-dev \
    postgresql-client \
    # Redis extension deps
    libzip-dev \
    zip \
    unzip \
    # Image processing
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    # Internationalization
    icu-dev \
    # For PDF generation
    libxml2-dev \
    # Nginx and supervisor
    nginx \
    supervisor \
    # Git for composer
    git \
    # Curl for healthchecks
    curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_pgsql \
        pgsql \
        opcache \
        intl \
        gd \
        zip \
        bcmath \
        pcntl \
    # Install Redis extension
    && pecl install redis \
    && docker-php-ext-enable redis \
    # Cleanup
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/* /tmp/*

# Create non-root user
RUN addgroup -g 1000 www && \
    adduser -u 1000 -G www -s /bin/sh -D www

# Set working directory
WORKDIR /var/www/html

# Create log directories
RUN mkdir -p /var/log/php /var/log/supervisor

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Copy Nginx configuration
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy Supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy composer binary from composer image
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Copy Composer dependencies from build stage
COPY --from=composer-deps /app/vendor ./vendor

# Copy application code
COPY --chown=www:www . .

# Generate optimized autoloader
RUN composer dump-autoload --optimize --classmap-authoritative

# Create required directories
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www:www storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Optimize Laravel for production
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

# Create log directory for nginx
RUN mkdir -p /var/log/nginx && \
    chown -R www:www /var/log/nginx

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
    CMD curl -f http://localhost/api/health || exit 1

# Start supervisord
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
