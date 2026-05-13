#!/usr/bin/env bash
# Pre-deploy gate: scan docs/pos-operations/ for unresolved
# TBD/TODO/PLACEHOLDER markers. Exits non-zero with the list of hits.
#
# Run from repo root:
#
#   ./scripts/preflight-runbooks.sh
#
# Wire into the deploy pipeline ahead of any tagged release. Per the
# 2026-05-13 first-tenant handoff (Item 4) — operator must clear every
# hit before launch.
#
# Excludes template-substitution markers like <companyId>, <terminalCode>,
# etc. (angle-bracket placeholders are intentional and supplied by the
# operator at runtime; they are not unresolved authoring placeholders).

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUNBOOKS_DIR="${ROOT}/docs/pos-operations"

if [[ ! -d "${RUNBOOKS_DIR}" ]]; then
  echo "ERROR: runbook directory not found at ${RUNBOOKS_DIR}" >&2
  exit 2
fi

# Collect hits: TBD, TODO, or PLACEHOLDER as a whole word, anywhere in
# any .md file under docs/pos-operations/ EXCEPT lines whose ONLY hit is
# inside an inline code span like `TBD` (we want those flagged) or
# inside the literal documentation header that explains the markers
# themselves. Markdown angle-bracket templates (<companyId>) are not
# matched; only the three sentinel words.
HITS_FILE=$(mktemp)
trap 'rm -f "${HITS_FILE}"' EXIT

# rg is preferred (respects .gitignore + faster); fall back to grep -E.
if command -v rg >/dev/null 2>&1; then
  rg -n '\b(TBD|TODO|PLACEHOLDER)\b' "${RUNBOOKS_DIR}" > "${HITS_FILE}" || true
else
  grep -rnE '\b(TBD|TODO|PLACEHOLDER)\b' "${RUNBOOKS_DIR}" > "${HITS_FILE}" || true
fi

# Allowlist: lines that are pure documentation about the marker
# convention itself rather than unresolved values. Each entry is a
# fixed-string substring; lines containing it are removed from hits.
ALLOWLIST=(
  "this script scans for"
  "preflight-runbooks.sh"
)

for needle in "${ALLOWLIST[@]}"; do
  grep -v -F "${needle}" "${HITS_FILE}" > "${HITS_FILE}.tmp" && mv "${HITS_FILE}.tmp" "${HITS_FILE}"
done

HIT_COUNT=$(wc -l < "${HITS_FILE}" | tr -d ' ')

if [[ "${HIT_COUNT}" -eq 0 ]]; then
  echo "preflight-runbooks: OK — no unresolved TBD/TODO/PLACEHOLDER in docs/pos-operations/"
  exit 0
fi

echo "preflight-runbooks: FAIL — ${HIT_COUNT} unresolved marker(s) in docs/pos-operations/" >&2
echo "" >&2
cat "${HITS_FILE}" >&2
echo "" >&2
echo "Resolve every hit above (fill the value, replace with the operator's input, or remove the row), then re-run." >&2
exit 1
