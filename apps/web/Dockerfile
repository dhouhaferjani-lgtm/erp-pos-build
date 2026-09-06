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

# Install pnpm — version is resolved from the root package.json `packageManager`
# field (copied below) so the image always builds with the workspace-pinned pnpm.
# Never `corepack prepare pnpm@latest`: a floating version broke the build on
# 2026-06-12 against the pnpm@9.14.2 lockfile.
ENV COREPACK_ENABLE_DOWNLOAD_PROMPT=0
RUN corepack enable

WORKDIR /app

# Copy workspace configuration and lock file from root
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./

# Copy the web app package.json
COPY apps/web/package.json ./apps/web/

# Copy shared workspace packages (manifests + generated TS types incl. the `App`
# namespace from packages/shared/types) BEFORE install + build, else tsc fails
# with "Cannot find namespace 'App'" / unresolved vite/client types.
COPY packages/ ./packages/

# Install dependencies (workspace-aware)
RUN pnpm install --frozen-lockfile

# Copy web app source code
COPY apps/web/ ./apps/web/

# Checked-in route manifest — the ONLY input to build-fingerprint.json's
# feature_fingerprint (apps/web/tools/write-build-fingerprint.mjs resolves it at
# ../../scripts/factory/manifests/routes-web.yaml). Load-bearing: without it the image
# would emit "feature_fingerprint": "unknown" and the staging push manifest §3 shape
# check would silently pass on a stale bundle. The CI=true re-run after the build makes
# its absence a hard build failure instead.
COPY scripts/factory/manifests/ ./scripts/factory/manifests/

# Build the application
WORKDIR /app/apps/web

# Build arguments for Vite environment variables
ARG VITE_REVERB_APP_KEY
ARG VITE_API_URL
ARG VITE_APP_PRODUCT=izipos

# Commit sha stamped into dist/build-fingerprint.json by
# tools/write-build-fingerprint.mjs (run by `pnpm build`). The builder stage copies
# apps/web/ only — there is NO .git here — so the sha cannot be read from git and must
# arrive as a build argument.
#
# DOKPLOY: the staging/production web application MUST pass `BUILD_SHA` as a build
# argument (the deployed commit sha). Without it the served
# /build-fingerprint.json reports "build_sha": "unknown" and the staging push
# manifest §3 sha check cannot close — tracked as UNVERIFIED U-9 in
# docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md §6.
ARG BUILD_SHA
ENV BUILD_SHA=$BUILD_SHA

RUN pnpm build

# Re-emit the fingerprint with CI=true so a missing route manifest FAILS the image
# build (exit 1) rather than shipping "feature_fingerprint": "unknown" unnoticed.
# `pnpm build` already wrote the file; this rewrites the identical payload (modulo
# build_time) under the strict guard. Scoped to this command only — CI is deliberately
# NOT set for the vite build itself.
RUN CI=true node tools/write-build-fingerprint.mjs

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
