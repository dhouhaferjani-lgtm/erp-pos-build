#!/bin/sh
set -e

echo "========================================"
echo "Starting AutoERP Web Frontend..."
echo "========================================"
echo "Timestamp: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo ""

# Check required environment variable
if [ -z "$API_URL" ]; then
    echo "ERROR: API_URL environment variable is not set!"
    echo "Please set API_URL to the backend API URL (e.g., https://api.example.com)"
    exit 1
fi

echo "API_URL: $API_URL"

# Extract hostname from API_URL for proxy Host header (required for Traefik routing)
API_HOST=$(echo "$API_URL" | sed -e 's|https\?://||' -e 's|/.*||' -e 's|:.*||')
echo "API_HOST: $API_HOST"

# WebSocket URL (optional, defaults to API_URL for bundled mode)
if [ -n "$WS_URL" ]; then
    WS_HOST=$(echo "$WS_URL" | sed -e 's|https\?://||' -e 's|/.*||' -e 's|:.*||')
    echo "WS_URL: $WS_URL"
    echo "WS_HOST: $WS_HOST"
else
    WS_URL="$API_URL"
    WS_HOST="$API_HOST"
    echo "WS_URL: $WS_URL (same as API, bundled mode)"
fi

# Generate nginx config with API URL substitution
echo "Generating nginx configuration..."

# Create the nginx server config with the API URL
cat > /etc/nginx/conf.d/default.conf << EOF
# =============================================================================
# Nginx Server Configuration (generated at runtime)
# =============================================================================

# Pass through the original protocol from the upstream reverse proxy (Traefik).
# Defaults to https when accessed directly (no X-Forwarded-Proto header).
map \$http_x_forwarded_proto \$forwarded_proto {
    default \$http_x_forwarded_proto;
    ''      'https';
}

server {
    listen 80;
    listen [::]:80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    # Docker embedded DNS resolver — required for resolving Docker service names
    # at request time (not just startup). valid=30s re-resolves periodically so
    # nginx picks up container IP changes after redeploys.
    resolver 127.0.0.11 valid=30s ipv6=off;
    resolver_timeout 5s;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Cache static assets aggressively
    location ~* \.(?:css|js|woff|woff2|ttf|eot|ico)$ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
    }

    # Cache images
    location ~* \.(?:jpg|jpeg|gif|png|svg|webp)$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public";
    }

    # Don't cache HTML (SPA needs fresh index.html)
    location ~* \.html$ {
        expires -1;
        add_header Cache-Control "no-store, no-cache, must-revalidate";
    }

    # API proxy - forward to backend
    # Using nginx variable for proxy_pass enables runtime DNS resolution
    # (hardcoded hostnames are resolved only at startup and cached forever)
    location /api/ {
        set \$upstream_api ${API_URL};
        client_max_body_size 64M;
        proxy_pass \$upstream_api;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$forwarded_proto;
        proxy_set_header X-Forwarded-Host \$host;

        # WebSocket support (for future use)
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";

        # Timeouts
        proxy_connect_timeout 60s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;

        # Buffer settings
        proxy_buffer_size 128k;
        proxy_buffers 4 256k;
        proxy_busy_buffers_size 256k;
    }

    # Sanctum CSRF cookie endpoint
    location /sanctum/ {
        set \$upstream_sanctum ${API_URL};
        proxy_pass \$upstream_sanctum;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$forwarded_proto;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # WebSocket proxy to Reverb
    # If WS_URL is set, proxy directly to the websocket container.
    # Otherwise, proxy through the API container (bundled mode).
    location /app/ {
        set \$upstream_ws ${WS_URL};
        proxy_pass \$upstream_ws;
        proxy_http_version 1.1;
        proxy_set_header Host ${WS_HOST};
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$forwarded_proto;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }

    # Broadcasting auth endpoint proxy
    location /broadcasting/ {
        set \$upstream_broadcast ${API_URL};
        proxy_pass \$upstream_broadcast;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$forwarded_proto;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # SPA fallback - serve index.html for all routes
    location / {
        try_files \$uri \$uri/ /index.html;
    }

    # Health check endpoint
    location = /health {
        access_log off;
        return 200 "OK";
        add_header Content-Type text/plain;
    }

    # Deny access to hidden files
    location ~ /\. {
        deny all;
    }
}
EOF

echo "Nginx configuration generated successfully."
echo ""
echo "========================================"
echo "Starting nginx..."
echo "========================================"

# Start nginx
exec nginx -g "daemon off;"
