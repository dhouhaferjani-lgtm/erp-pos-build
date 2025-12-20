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

# Build arguments for API URL (injected at build time)
ARG VITE_API_URL
ENV VITE_API_URL=${VITE_API_URL}

# Build the application
RUN pnpm build

# -----------------------------------------------------------------------------
# Stage 2: Production image with Nginx
# -----------------------------------------------------------------------------
FROM nginx:alpine AS production

# Copy custom nginx configuration
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Copy built assets from builder stage
COPY --from=builder /app/dist /usr/share/nginx/html

# Create non-root user for security
RUN addgroup -g 1000 www && \
    adduser -u 1000 -G www -s /bin/sh -D www && \
    chown -R www:www /usr/share/nginx/html && \
    chown -R www:www /var/cache/nginx && \
    chown -R www:www /var/log/nginx && \
    touch /var/run/nginx.pid && \
    chown -R www:www /var/run/nginx.pid

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD wget -q --spider http://localhost/ || exit 1

# Start nginx
CMD ["nginx", "-g", "daemon off;"]
