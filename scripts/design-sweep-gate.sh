#!/usr/bin/env bash
# Autonomous gate review for the design-system sweep.
# Usage: scripts/design-sweep-gate.sh <gate-label> <base-commit>
# Invoked by the implementor (Codex) at each gate boundary. Runs an independent
# headless Claude review (Opus) with no shared context, saves the review to
# docs/handoff/gate-reviews/, and exits 0 ONLY on VERDICT: APPROVE.
# Implementor loop contract: on nonzero exit, read the review, apply the fixes,
# commit, and re-run with the SAME base commit. After 2 consecutive non-APPROVE
# rounds on the same gate, STOP and escalate to the owner.
set -euo pipefail

GATE_LABEL="${1:?usage: design-sweep-gate.sh <gate-label> <base-commit>}"
BASE_COMMIT="${2:?usage: design-sweep-gate.sh <gate-label> <base-commit>}"

ROOT="$(git rev-parse --show-toplevel)"
PROMPT_FILE="$ROOT/docs/handoff/design-sweep-gate-review-prompt.md"
HEAD_SHORT="$(git rev-parse --short HEAD)"
OUT_DIR="$ROOT/docs/handoff/gate-reviews"
OUT="$OUT_DIR/${GATE_LABEL}-${HEAD_SHORT}.md"
mkdir -p "$OUT_DIR"

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERROR: worktree not clean — commit before requesting a gate review." >&2
  exit 2
fi

INVOCATION="$(cat "$PROMPT_FILE")

---
GATE_LABEL: $GATE_LABEL
BASE_COMMIT: $BASE_COMMIT
HEAD: $(git rev-parse HEAD)
Write your full review to: $OUT
Remember: end your final message with exactly one 'VERDICT: ...' line."

# Independent reviewer: fresh headless Claude session, Opus, capped turns.
# --allowedTools grants read/verify surface + Write (for the review file only per prompt).
claude -p "$INVOCATION" \
  --model opus \
  --allowedTools "Read,Grep,Glob,Write,Agent,Bash(git *),Bash(rg *),Bash(node *),Bash(pnpm *),Bash(npx *),Bash(ls *),Bash(wc *),Bash(mkdir *),Bash(pkill -f *),Bash(ps *)" \
  --max-turns 200 \
  | tee "$OUT_DIR/${GATE_LABEL}-${HEAD_SHORT}.log"

VERDICT_LINE="$(grep -Eo 'VERDICT: (APPROVE-WITH-FIXES|APPROVE|REJECT)' "$OUT_DIR/${GATE_LABEL}-${HEAD_SHORT}.log" | tail -1 || true)"
echo "----------------------------------------"
echo "Gate ${GATE_LABEL} @ ${HEAD_SHORT}: ${VERDICT_LINE:-NO VERDICT EMITTED}"
echo "Review: $OUT"

[ "$VERDICT_LINE" = "VERDICT: APPROVE" ] && exit 0
exit 1
