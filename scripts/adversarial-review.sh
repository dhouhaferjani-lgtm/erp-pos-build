#!/usr/bin/env bash
# adversarial-review.sh — invoke Opus (via the claude CLI) as an inline adversarial
# reviewer of one milestone's diff, capture its register to a file, and exit with a
# machine-checkable verdict code. Called by Codex during a self-reviewing wave.
#
# Usage:
#   scripts/adversarial-review.sh \
#     --brief <path-to-CODEX-DISPATCH-*.md> \
#     --milestone <M-id> \
#     --lenses "inventory-costing,fiscal-pos" \
#     --range <git-diff-range e.g. BASE_SHA..HEAD | --staged | --worktree> \
#     --out <path-to-write-verdict.md> \
#     [--authority <path-to-amending-ruling>] \
#     [--round <n>]
#
# Exit codes: 0 = ACCEPT · 2 = CHANGES-REQUIRED · 3 = tool/parse error (treat as CHANGES-REQUIRED, fail-closed)
#
# Runs read-only: plan-mode permissions enforce no writes, and the script captures Opus's stdout.
# Requires: claude CLI on PATH (verified 2026-08-11 @ 2.1.227) supporting --print/--model/--permission-mode.
set -euo pipefail

BRIEF="" ; MILESTONE="" ; LENSES="" ; RANGE="" ; OUT="" ; AUTHORITY="" ; ROUND="1"
while [ $# -gt 0 ]; do
  case "$1" in
    --brief) BRIEF="$2"; shift 2 ;;
    --milestone) MILESTONE="$2"; shift 2 ;;
    --lenses) LENSES="$2"; shift 2 ;;
    --range) RANGE="$2"; shift 2 ;;
    --out) OUT="$2"; shift 2 ;;
    --authority) AUTHORITY="$2"; shift 2 ;;
    --round) ROUND="$2"; shift 2 ;;
    *) echo "unknown arg: $1" >&2; exit 3 ;;
  esac
done

for req in BRIEF MILESTONE RANGE OUT; do
  case "$req" in
    BRIEF) req_flag="brief" ;;
    MILESTONE) req_flag="milestone" ;;
    RANGE) req_flag="range" ;;
    OUT) req_flag="out" ;;
  esac
  if [ -z "${!req}" ]; then echo "missing --${req_flag}" >&2; exit 3; fi
done
if ! command -v claude >/dev/null 2>&1; then echo "claude CLI not on PATH" >&2; exit 3; fi
if [ ! -f "$BRIEF" ]; then echo "brief not found: $BRIEF" >&2; exit 3; fi
if [ -n "$AUTHORITY" ] && [ ! -f "$AUTHORITY" ]; then echo "authority not found: $AUTHORITY" >&2; exit 3; fi

# Resolve the diff instruction the reviewer will run itself (large diffs: reviewer runs git, not embedded).
case "$RANGE" in
  --staged)   DIFFCMD="git diff --staged" ;;
  --worktree) DIFFCMD="git diff HEAD" ;;
  *)          DIFFCMD="git diff ${RANGE}" ;;
esac

mkdir -p "$(dirname "$OUT")"

PROMPT=$(cat <<PROMPT_EOF
You are an ADVERSARIAL merge-gate reviewer. READ-ONLY: do not modify, stage, or commit any file.
You are reviewing milestone ${MILESTONE} of an implementation wave, round ${ROUND}.

SCOPE — read the milestone's own section in the dispatch brief and hold the implementation to it:
  Brief: ${BRIEF}  (open it; find the "${MILESTONE}" milestone section; its scope, invariants, and
  required tests are your acceptance criteria — do not invent scope beyond it, do not relitigate the design.)

AMENDING AUTHORITY — when present, this ruling changes the milestone gate and takes precedence over
conflicting acceptance wording in the brief. Open it and apply it exactly:
  ${AUTHORITY:-none}

DOMAIN LENSES for this milestone: ${LENSES:-general}
  Apply each named lens (e.g. inventory-costing = WAC/stock-movement/cost-path integrity;
  fiscal-pos = hash-chain/sealed-bytes/projection correctness; tenancy-authz = permission/module-gating/
  tenant scoping; treasury = payments/GL/partial-write atomicity). If a lens does not apply, say so.

WHAT TO REVIEW — run this yourself and read surrounding code as needed:
  ${DIFFCMD}
  Verify every claim against the actual code (cite file:line). Never assert from the brief alone.

STANDING CHECKS (apply to all findings):
  - Rule 19: no float touches money/quantity; bcmath/string; scale resolver injected with explicit currency.
  - Red-first evidence: each behavioral change has a test shown failing before the fix (check the report/commits).
  - Tenant scoping, constructor injection (no app() in production), en+fr on user-facing strings.
  - Migrations additive/unattended-safe; new named queues have horizon coverage.
  - The milestone's OWN named invariants and required tests from the brief section are present and non-vacuous.

OUTPUT — a numbered register. Each finding: severity (P1 blocks / P2 fix-before-merge / P3 note),
file:line, CONFIRMED vs PLAUSIBLE, and the concrete failure scenario. Record bypasses you tried that FAILED.
Then, as the ABSOLUTE LAST LINE of your response, emit exactly one of:
  VERDICT: ACCEPT
  VERDICT: CHANGES-REQUIRED
Nothing after that line.
PROMPT_EOF
)

TMP="$(mktemp)"
if ! claude -p "$PROMPT" \
      --model opus \
      --permission-mode plan \
      --output-format text > "$TMP" 2>"${TMP}.err"; then
  echo "claude invocation failed:" >&2; tail -5 "${TMP}.err" >&2
  { echo "# REVIEW TOOL ERROR (milestone ${MILESTONE}, round ${ROUND})"; echo; cat "${TMP}.err"; } > "$OUT"
  rm -f "$TMP" "${TMP}.err"
  exit 3
fi

cp "$TMP" "$OUT"
rm -f "$TMP" "${TMP}.err"

VERDICT_LINE="$(grep -E '^VERDICT:' "$OUT" | tail -1 || true)"
case "$VERDICT_LINE" in
  *ACCEPT*)             echo "ACCEPT";             exit 0 ;;
  *CHANGES-REQUIRED*)   echo "CHANGES-REQUIRED";   exit 2 ;;
  *) echo "NO PARSEABLE VERDICT (fail-closed → CHANGES-REQUIRED)" >&2; exit 3 ;;
esac
