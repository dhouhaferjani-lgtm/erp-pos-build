#!/usr/bin/env bash
#
# Pass 2B sequencing sentinel — synthesis v5 §8.A + Codex N-25 closure.
#
# Pass 2A.TS lands the device-side validator + .PASS_2B_PENDING marker
# without wiring `FiscalEventEngine` into the device-side checkout path.
# Pass 2B atomically removes the marker AND adds the engine wiring in
# the same commit.
#
# This sentinel enforces that sequencing without depending on
# commit-message discipline: while the marker exists, any PR that
# imports / invokes `FiscalEventEngine` (directly or via the singleton
# accessor / mutex helper / append-call signature) from the device-side
# checkout path is rejected. The marker removal and the engine wiring
# must land together.
#
# Once Pass 2B ships, the marker file is deleted and this sentinel
# becomes a no-op until Pass 2C / next contract change.
#
set -euo pipefail

MARKER="apps/pos/src/lib/offline/.PASS_2B_PENDING"
RECEIPT_SVC="apps/pos/src/lib/offline/receiptService.ts"
PAYMENT_STORE="apps/pos/src/stores/paymentStore.ts"

if [ ! -f "$MARKER" ]; then
  # Pass 2B has shipped (marker removed); no further checks needed.
  exit 0
fi

# Marker exists → Pass 2A landed, Pass 2B not yet shipped.
# Reject any PR that wires the engine into the device-side checkout path.

FORBIDDEN_PATTERNS=(
  # Direct class name (covers imports, type refs, and `new FiscalEventEngine`).
  'FiscalEventEngine'
  # Singleton accessor (added in Pass 2B per Amended A1).
  'getFiscalEventEngine'
  # The mutex helper Pass 2B will introduce per Amended A5.
  'lockTerminal'
  # Engine.append() invocation pattern (the canonical engine seal call).
  '\.append\(.*event_type'
)

violation=0
for file in "$RECEIPT_SVC" "$PAYMENT_STORE"; do
  if [ ! -f "$file" ]; then
    continue
  fi
  for pattern in "${FORBIDDEN_PATTERNS[@]}"; do
    if grep -qE "$pattern" "$file" 2>/dev/null; then
      echo "ERROR: .PASS_2B_PENDING marker exists but $file matches forbidden pattern: $pattern"
      echo "  Pass 2A's contract sequencing has been violated."
      echo "  Pass 2B MUST atomically remove the marker AND add the engine wiring."
      echo "  Either complete Pass 2B in this PR (remove $MARKER + add wiring together),"
      echo "  OR revert the $file change."
      violation=1
    fi
  done
done

if [ "$violation" -ne 0 ]; then
  exit 1
fi

exit 0
