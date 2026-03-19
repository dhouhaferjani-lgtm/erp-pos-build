# =============================================================================
# AutoERP Web Frontend - Production Dockerfile
# Multi-stage build for pnpm monorepo - build from repo ROOT
# =============================================================================
#
# IMPORTANT: This Dockerfile must be built from the repository ROOT directory
# with the context set to the root, not from apps/web.
#
# In Dokploy:
#   - Source Path: . (root)
#   - Dockerfile Path: apps/web/Dockerfile
#
# Or use: docker build -f apps/web/Dockerfile .
#

# -----------------------------------------------------------------------------
# Stage 1: Build the React application
# -----------------------------------------------------------------------------
FROM node:22-alpine AS builder

# Install pnpm
RUN corepack enable && corepack prepare pnpm@latest --activate

WORKDIR /app

# Copy workspace configuration and lock file from root
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./

# Copy the web app package.json
COPY apps/web/package.json ./apps/web/

# Copy shared packages (uses .dockerignore to handle missing dirs)

# Install dependencies (workspace-aware)
RUN pnpm install --frozen-lockfile

# Copy web app source code
COPY apps/web/ ./apps/web/

# Build the application
WORKDIR /app/apps/web

# Build arguments for Vite environment variables
ARG VITE_REVERB_APP_KEY
ARG VITE_API_URL

RUN pnpm build

# -----------------------------------------------------------------------------
# Stage 2: Production image with Nginx
# -----------------------------------------------------------------------------
FROM nginx:alpine AS production

# Remove default nginx config
RUN rm -f /etc/nginx/conf.d/default.conf

# Copy custom nginx base configuration
COPY apps/web/docker/nginx.conf /etc/nginx/nginx.conf

# Copy entrypoint script (generates runtime nginx config with API_URL)
COPY apps/web/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Copy built assets from builder stage
COPY --from=builder /app/apps/web/dist /usr/share/nginx/html

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD wget -q --spider http://localhost/health || exit 1

# Start via entrypoint (generates nginx config then starts nginx)
CMD ["/usr/local/bin/entrypoint.sh"]
