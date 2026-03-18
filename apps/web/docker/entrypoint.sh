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

server {
    listen 80;
    listen [::]:80;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

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
    # Host header must match the API domain so Traefik routes correctly
    location /api/ {
        proxy_pass ${API_URL}/api/;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
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
        proxy_pass ${API_URL}/sanctum/;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
    }

    # WebSocket proxy to Reverb
    # If WS_URL is set, proxy directly to the websocket container.
    # Otherwise, proxy through the API container (bundled mode).
    location /app/ {
        proxy_pass ${WS_URL:-${API_URL}}/app/;
        proxy_http_version 1.1;
        proxy_set_header Host ${WS_HOST:-${API_HOST}};
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 86400s;
        proxy_send_timeout 86400s;
    }

    # Broadcasting auth endpoint proxy
    location /broadcasting/ {
        proxy_pass ${API_URL}/broadcasting/;
        proxy_http_version 1.1;
        proxy_set_header Host ${API_HOST};
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
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
