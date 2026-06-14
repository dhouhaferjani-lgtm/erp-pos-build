#!/usr/bin/env bash
#
# git-dev-push-guard — PreToolUse(Bash) guard against messy local/remote `dev`
# divergence.
#
# Workflow this enforces (see CLAUDE.md "Dev branch sync discipline"):
#   - Feature work merges into LOCAL dev first, then gets promoted to remote in
#     verified batches.
#   - Promotion to origin/dev must always be a clean FAST-FORWARD. Never rewrite
#     shared history.
#
# It BLOCKS (deny) only `git push` commands that target the `dev` branch when:
#   1. the push is a force-push (--force / --force-with-lease / -f / +dev), or
#   2. local `dev` is BEHIND / DIVERGED from origin/dev (so the push would either
#      be rejected as non-ff or tempt a force-push). In that case it tells the
#      caller to integrate origin/dev first (fetch + ff-merge / rebase), which
#      keeps remote dev a clean fast-forward.
#
# Everything else passes through untouched. The guard is intentionally
# fail-OPEN: if jq is missing or anything is unparseable it allows the command
# (a guard must never wedge unrelated work).
#
# Reads the PreToolUse payload on stdin; emits a JSON deny decision on stdout.

set -uo pipefail

PROTECTED="dev"

input="$(cat 2>/dev/null || true)"
command -v jq >/dev/null 2>&1 || exit 0

cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)"
cwd="$(printf '%s' "$input" | jq -r '.cwd // empty' 2>/dev/null)"
[ -n "$cmd" ] || exit 0
[ -n "$cwd" ] && [ -d "$cwd" ] || cwd="$PWD"

# Only consider `git ... push ...` (and not a "push" that appears after a pipe
# or in an unrelated subcommand like `git log | grep push`).
printf '%s' "$cmd" | grep -Eq '\bgit\b[^|&;]*\bpush\b' || exit 0

gitc() { git -C "$cwd" "$@" 2>/dev/null; }

# Does this push target the protected branch?
targets=0
# explicit refspec/branch token equal to dev or ending in :dev (optional + force)
if printf '%s' "$cmd" | grep -Eq "(^|[[:space:]])\+?(${PROTECTED}|[^[:space:]]+:${PROTECTED})([[:space:]]|\"|'|\$)"; then
  targets=1
fi
# bare `git push` (no non-flag ref token after push) -> current branch
if [ "$targets" -eq 0 ]; then
  after="$(printf '%s' "$cmd" | sed -E 's/.*\bpush\b//')"
  if ! printf '%s' "$after" | grep -Eq '[[:space:]][^-[:space:]]'; then
    [ "$(gitc rev-parse --abbrev-ref HEAD)" = "$PROTECTED" ] && targets=1
  fi
fi
[ "$targets" -eq 1 ] || exit 0

deny() {
  jq -n --arg r "$1" \
    '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}

# 1) Never force-push the shared dev branch.
if printf '%s' "$cmd" | grep -Eq '(--force-with-lease|--force([^a-z-]|$)|[[:space:]]-f([[:space:]]|$)|\+[^[:space:]]*'"${PROTECTED}"')'; then
  deny "🛑 dev-push-guard: force-pushing the shared '${PROTECTED}' branch is forbidden — never rewrite shared history. Promotions to origin/${PROTECTED} must be clean fast-forwards. If a rewrite is genuinely required, do it deliberately outside Claude."
fi

# 2) Block pushing a behind / diverged local dev.
gitc fetch -q origin "$PROTECTED"
behind="$(gitc rev-list --count ${PROTECTED}..origin/${PROTECTED})"; behind="${behind:-0}"
ahead="$(gitc rev-list --count origin/${PROTECTED}..${PROTECTED})"; ahead="${ahead:-0}"

if [ "$behind" -gt 0 ] 2>/dev/null; then
  if [ "$ahead" -gt 0 ] 2>/dev/null; then
    deny "🛑 dev-push-guard: local '${PROTECTED}' has DIVERGED from origin/${PROTECTED} (ahead ${ahead}, behind ${behind}). Integrate first so the push stays a clean fast-forward — from the ${PROTECTED} worktree: 'git fetch origin ${PROTECTED} && git merge origin/${PROTECTED}' (or rebase your local commits onto origin/${PROTECTED}), resolve, then re-run the push."
  else
    deny "🛑 dev-push-guard: local '${PROTECTED}' is ${behind} behind origin/${PROTECTED}. Fast-forward first: 'git fetch origin ${PROTECTED} && git merge --ff-only origin/${PROTECTED}', then re-run the push."
  fi
fi

exit 0
