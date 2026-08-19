#!/usr/bin/env bash
# i18n-baseline-authority.sh — LOCAL authority setup for the i18n completeness gate.
#
# WHY THIS EXISTS
# ---------------
# `apps/web/tools/audit-i18n-completeness.mjs` fails closed unless
# I18N_BASELINE_PROTECTED_BLOB names the git blob of the gate-reviewed seed
# baseline. In CI that value comes from the OWNER-SET GitHub Actions repository
# variable, which no developer machine has — so a bare `pnpm lint` would hard-fail
# for everyone. This script performs the exact ritual the dispatch brief
# prescribes for local runs, then execs the checker:
#
#   1. read the seed commit + protected blob MIRROR pins from the package
#      progress YAML;
#   2. re-derive the blob from the seed commit (`git rev-parse <seed>:<path>`);
#   3. assert the derived blob EQUALS the mirror pin — a mismatch means the seed
#      commit and the pin have drifted apart and is a hard failure here too;
#   4. export it under the same env-var name the checker reads and run the
#      checker unchanged.
#
# THIS IS NOT A BYPASS. It supplies the same value from the same reviewed seed
# commit; it cannot make CI pass. CI's authority stays the repository variable,
# and a candidate that edits the mirror + baseline together is still caught there
# (variable ≠ mirror → MIRROR DRIFT; variable's blob ≠ working file → RATCHET
# GROWTH). What this removes is only the developer-machine hard-fail.
#
# Used by: `pnpm --filter @autoerp/web lint` and `scripts/preflight.sh`.
# CI uses `pnpm audit:i18n` with the repository variable instead.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROGRESS="$ROOT_DIR/docs/handoff/progress/enforcement-p2.progress.yaml"
BASELINE_REL="apps/web/tools/i18n-completeness-baseline.json"

if [ -n "${I18N_BASELINE_PROTECTED_BLOB:-}" ]; then
    # Already supplied (CI, or a caller that ran the ritual itself) — respect it.
    exec node "$ROOT_DIR/apps/web/tools/audit-i18n-completeness.mjs" "$@"
fi

if [ ! -f "$PROGRESS" ]; then
    echo "i18n authority setup FAILED: progress file not found: $PROGRESS" >&2
    echo "  The mirror pins live there. If the file moved, update this script and the" >&2
    echo "  checker's --mirror default together." >&2
    exit 1
fi

seed_commit="$(sed -n 's/^i18n_baseline_seed_commit:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$PROGRESS" | head -1)"
mirror_blob="$(sed -n 's/^i18n_baseline_protected_blob:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$PROGRESS" | head -1)"

if [ -z "$seed_commit" ] || [ "$seed_commit" = "null" ] || [ -z "$mirror_blob" ] || [ "$mirror_blob" = "null" ]; then
    echo "i18n authority setup FAILED: the mirror pins are unset in $PROGRESS" >&2
    echo "  i18n_baseline_seed_commit=${seed_commit:-<empty>} i18n_baseline_protected_blob=${mirror_blob:-<empty>}" >&2
    exit 1
fi

if ! derived="$(git -C "$ROOT_DIR" rev-parse "${seed_commit}:${BASELINE_REL}" 2>/dev/null)"; then
    echo "i18n authority setup FAILED: cannot resolve ${seed_commit}:${BASELINE_REL}" >&2
    echo "  Fetch the seed commit (it must be reachable locally) and retry." >&2
    exit 1
fi

if [ "$derived" != "$mirror_blob" ]; then
    echo "i18n authority setup FAILED: SEED/MIRROR DRIFT" >&2
    echo "  git rev-parse ${seed_commit}:${BASELINE_REL} = $derived" >&2
    echo "  progress-YAML i18n_baseline_protected_blob  = $mirror_blob" >&2
    echo "  The seed commit and the mirror pin disagree — re-record both (they are" >&2
    echo "  written in two commits: the seed, then the metadata commit)." >&2
    exit 1
fi

export I18N_BASELINE_PROTECTED_BLOB="$derived"
exec node "$ROOT_DIR/apps/web/tools/audit-i18n-completeness.mjs" "$@"
