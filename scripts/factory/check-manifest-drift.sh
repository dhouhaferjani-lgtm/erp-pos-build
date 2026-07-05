#!/bin/bash
# Route manifest drift check (dark-factory page-coverage, spec §4).
# Regenerates the route manifests into a temp dir and diffs them against the
# committed scripts/factory/manifests/. Any drift (a new/changed/removed route
# that was not regenerated and committed) fails with exit 1.
set -euo pipefail

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT_DIR="$( cd "$SCRIPT_DIR/../.." && pwd )"
MANIFEST_DIR="$ROOT_DIR/scripts/factory/manifests"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

node "$ROOT_DIR/scripts/factory/gen-route-manifest.mjs" --out "$TMP_DIR" >/dev/null

if ! diff -ru "$MANIFEST_DIR" "$TMP_DIR"; then
    echo ""
    echo "Route manifest drift — run: node scripts/factory/gen-route-manifest.mjs && commit the manifests"
    exit 1
fi
