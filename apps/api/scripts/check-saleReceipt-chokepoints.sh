#!/usr/bin/env bash
#
# §14.3 chokepoint completeness gate.
#
# Spec: docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md §14.3
#
# Walks every `->createReceipt(` and `->finalize(` call site under
# apps/api/app + apps/api/routes and reconciles each hit against the
# checked-in disposition manifest at
# apps/api/scripts/saleReceipt-chokepoint-manifest.json.
#
# Exits non-zero if:
#   - any call site has no matching manifest entry (file + line_anchor
#     substring match), or
#   - any non-`unrelated` entry is missing a disposition in {a, b, c}, or
#   - (with --phase1-complete) any non-`unrelated` `live: true` entry has
#     a disposition that isn't (b) or (c).
#
# Mirrored at the PHPUnit layer by
# tests/Feature/Fiscal/ChokepointCompletenessTest.php (defense in depth).
#
# Resolves its paths relative to the repo root so it runs identically from
# either the repo root (preflight.sh + CI) or apps/api/ (developer
# workflows).

set -euo pipefail

# ---------------------------------------------------------------------
# Resolve paths
# ---------------------------------------------------------------------
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO_ROOT="$( cd "$SCRIPT_DIR/../../.." && pwd )"
MANIFEST_DEFAULT="$REPO_ROOT/apps/api/scripts/saleReceipt-chokepoint-manifest.json"

# ---------------------------------------------------------------------
# Parse flags
# ---------------------------------------------------------------------
MANIFEST="$MANIFEST_DEFAULT"
PHASE1_COMPLETE=""

while [[ $# -gt 0 ]]; do
    case "$1" in
        --phase1-complete)
            PHASE1_COMPLETE="1"
            shift
            ;;
        --manifest)
            MANIFEST="$2"
            shift 2
            ;;
        -h|--help)
            cat <<EOF
Usage: $0 [--phase1-complete] [--manifest <path>]

Enforces spec v7 §14.3 chokepoint completeness against the manifest.

Options:
  --phase1-complete    Additionally fail on any live, non-unrelated
                       chokepoint caller whose disposition isn't (b) or
                       (c). Run this in the Phase 1 release gate.
  --manifest PATH      Override the manifest path (default:
                       apps/api/scripts/saleReceipt-chokepoint-manifest.json).
EOF
            exit 0
            ;;
        *)
            echo "Unknown arg: $1" >&2
            exit 2
            ;;
    esac
done

# ---------------------------------------------------------------------
# Sanity checks on prerequisites
# ---------------------------------------------------------------------
if [[ ! -f "$MANIFEST" ]]; then
    echo "Manifest not found: $MANIFEST" >&2
    exit 2
fi

if ! command -v rg >/dev/null 2>&1; then
    echo "rg (ripgrep) is required by this gate but is not installed." >&2
    exit 2
fi

if ! command -v jq >/dev/null 2>&1; then
    echo "jq is required by this gate but is not installed." >&2
    exit 2
fi

# ---------------------------------------------------------------------
# Collect every call site
# ---------------------------------------------------------------------
cd "$REPO_ROOT"

# rg returns exit 1 when there are no matches; tolerate it via || true and
# normalize the output through `cat -`. The `--` separates the fixed-string
# pattern from positional args (the needle starts with `->`).
HITS_CREATE="$(rg -n --no-heading --fixed-strings -- '->createReceipt(' apps/api/app apps/api/routes 2>/dev/null || true)"
HITS_FINAL="$(rg -n --no-heading --fixed-strings -- '->finalize(' apps/api/app apps/api/routes 2>/dev/null || true)"

HITS="$(printf '%s\n%s\n' "$HITS_CREATE" "$HITS_FINAL")"

# ---------------------------------------------------------------------
# Reconcile each hit against the manifest
# ---------------------------------------------------------------------
FAILED=0
TOTAL_HITS=0

while IFS= read -r LINE; do
    [[ -z "$LINE" ]] && continue
    TOTAL_HITS=$((TOTAL_HITS + 1))

    FILE="${LINE%%:*}"
    REST="${LINE#*:}"
    LINENO="${REST%%:*}"
    TEXT_RAW="${REST#*:}"
    # Strip leading whitespace from the source text (rg preserves the
    # indentation as-written).
    TEXT="${TEXT_RAW#"${TEXT_RAW%%[![:space:]]*}"}"

    # Match the entry whose `file == FILE` and whose `line_anchor` is a
    # substring of TEXT. jq's `contains` is haystack-contains-needle, but
    # the needle (.line_anchor) lives on the entry; we have to bind the
    # entry to a variable first so .line_anchor doesn't get interpreted
    # as a key on $t (the string `contains` is invoked on).
    MATCH="$(jq -c --arg f "$FILE" --arg t "$TEXT" '
        .entries[]
        | select(.file == $f)
        | . as $e
        | select($t | contains($e.line_anchor))
    ' "$MANIFEST")"

    if [[ -z "$MATCH" ]]; then
        echo "UNRECONCILED: $FILE:$LINENO — $TEXT" >&2
        FAILED=1
        continue
    fi

    CHOKEPOINT="$(echo "$MATCH" | jq -r '.chokepoint // ""')"
    DISPOSITION="$(echo "$MATCH" | jq -r '.disposition // ""')"
    LIVE="$(echo "$MATCH" | jq -r '.live // false')"

    if [[ "$CHOKEPOINT" != "unrelated" && -z "$DISPOSITION" ]]; then
        echo "NO_DISPOSITION: $FILE:$LINENO ($CHOKEPOINT)" >&2
        FAILED=1
        continue
    fi

    if [[ -n "$PHASE1_COMPLETE" \
          && "$CHOKEPOINT" != "unrelated" \
          && "$LIVE" == "true" \
          && "$DISPOSITION" != "b" \
          && "$DISPOSITION" != "c" ]]; then
        echo "PHASE1_LIVE_NON_CARVEOUT: $FILE:$LINENO disposition=$DISPOSITION" >&2
        FAILED=1
        continue
    fi
done <<< "$HITS"

# ---------------------------------------------------------------------
# Report
# ---------------------------------------------------------------------
if [[ "$FAILED" -eq 0 ]]; then
    PHASE_TAG=""
    if [[ -n "$PHASE1_COMPLETE" ]]; then
        PHASE_TAG=" (--phase1-complete)"
    fi
    echo "§14.3 chokepoint gate: PASS — $TOTAL_HITS call site(s) reconciled${PHASE_TAG}"
fi

exit "$FAILED"
