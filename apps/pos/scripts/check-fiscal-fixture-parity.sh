#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
POS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$POS_DIR"

pnpm test -- src/lib/fiscal/__tests__/FiscalEventCanonicalEncoder.test.ts src/lib/fiscal/__tests__/sha256BoundaryVectors.test.ts
