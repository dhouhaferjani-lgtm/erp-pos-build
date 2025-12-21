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
    location /api/ {
        proxy_pass ${API_URL}/api/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
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
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
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
