#!/usr/bin/env bash
# Scan-to-document test stack (worktree apps/erp.scan-to-doc)
# Starts: API on :8011, ingestion queue worker, web on :5174.
# Assumes docker services (postgres/redis/minio) and erp-ml (:8002) are already up.
# The worktree's apps/api/.env must have APP_URL=http://localhost:8011 and
# REDIS_PREFIX=scanverify_ (restore original via apps/api/.env.bak-preverify).
# apps/web/vite.config.ts proxy must point at :8011 (temporary uncommitted edit).
# Stop everything with Ctrl+C.
set -euo pipefail
cd "$(dirname "$0")/.."

INI_DIR="$(mktemp -d)"
printf 'upload_max_filesize=25M\npost_max_size=30M\ndisplay_errors=0\n' > "$INI_DIR/99-dev.ini"

(cd apps/api && PHP_INI_SCAN_DIR=":$INI_DIR" php artisan serve --host=127.0.0.1 --port=8011) &
(cd apps/api && php artisan queue:work redis --queue=ingestion,default,images --tries=1) &
(cd apps/web && pnpm exec vite --port 5174 --strictPort) &

echo ""
echo "  Scan stack starting:"
echo "    web    → http://localhost:5174   (owner@pharmabio.tn / password)"
echo "    api    → http://localhost:8011"
echo "    worker → ingestion queue (redis prefix scanverify_)"
echo ""
trap 'kill 0' EXIT INT TERM
wait
