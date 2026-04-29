#!/bin/bash
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
PHP_FIXTURES="$REPO_ROOT/apps/api/tests/Fixtures/Fiscal/v3-golden-hashes"
TS_FIXTURES="$REPO_ROOT/apps/pos/src/lib/fiscal/v3/__fixtures__/v3-golden-hashes"

if ! diff -r "$PHP_FIXTURES" "$TS_FIXTURES" > /dev/null; then
  echo "❌ Fiscal v3 golden fixtures have drifted between PHP and TS." >&2
  echo "   PHP path: $PHP_FIXTURES" >&2
  echo "   TS path:  $TS_FIXTURES" >&2
  echo "   Run:" >&2
  echo "     diff -r \"$PHP_FIXTURES\" \"$TS_FIXTURES\"" >&2
  echo "   to see the divergence. The fixtures are intentionally byte-identical;" >&2
  echo "   any change must update both sides in the same commit. See the" >&2
  echo "   README.md inside each fixtures directory." >&2
  exit 1
fi

echo "✅ Fiscal v3 golden fixtures are in parity (PHP ↔ TS)."
