# =============================================================================
# AutoERP Web Frontend - Production Dockerfile
# Multi-stage build optimized for Dokploy deployment
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: Build the React application
# -----------------------------------------------------------------------------
FROM node:22-alpine AS builder

# Install pnpm
RUN corepack enable && corepack prepare pnpm@latest --activate

WORKDIR /app

# Copy package files
COPY package.json pnpm-lock.yaml ./

# Install dependencies
RUN pnpm install --frozen-lockfile

# Copy source code
COPY . .

# Build the application
# Note: API URL is configured at runtime via nginx proxy, not at build time
RUN pnpm build

# -----------------------------------------------------------------------------
# Stage 2: Production image with Nginx
# -----------------------------------------------------------------------------
FROM nginx:alpine AS production

# Remove default nginx config
RUN rm -f /etc/nginx/conf.d/default.conf

# Copy custom nginx base configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy entrypoint script (generates runtime nginx config with API_URL)
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy built assets from builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD wget -q --spider http://localhost/health || exit 1

# Start via entrypoint (generates nginx config then starts nginx)
CMD ["/usr/local/bin/entrypoint.sh"]
