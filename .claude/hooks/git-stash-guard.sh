#!/usr/bin/env bash
#
# git-stash-guard — PreToolUse(Bash) guard against mutating `git stash` in a
# repo shared by multiple worktrees / parallel sessions.
#
# Why (see memory: project_git_stash_shared_across_worktrees.md): the stash
# stack is REPO-GLOBAL — every worktree and every parallel session shares the
# same stack. On 2026-08-01 a worktree agent ran `git stash pop` and popped
# ANOTHER session's WIP, silently mixing unrelated changes into its tree.
#
# It BLOCKS (deny) a real `git stash` SUBCOMMAND when the stash operation is
# MUTATING: bare `git stash` (implicit push), or push / save / pop / apply /
# drop / clear / branch / create / store. Read-only subcommands (`list`,
# `show`) are ALLOWED.
#
# Detection is deliberately conservative, mirroring git-dev-push-guard: `git`
# must appear at a command boundary (start, or after ; & | ( ` && ||) and
# `stash` must be the git SUBCOMMAND. So `echo "stash"`, `git commit -m
# "...stash..."`, and paths containing "stash" are NOT treated as stashes.
#
# No escape hatch (same as git-dev-push-guard): if a stash is genuinely
# required, do it deliberately outside Claude.
#
# Fail-OPEN: if jq is missing or anything is unparseable it allows the command
# (a guard must never wedge unrelated work). Reads the PreToolUse payload on
# stdin; emits a JSON deny decision on stdout.

set -uo pipefail

input="$(cat 2>/dev/null || true)"
command -v jq >/dev/null 2>&1 || exit 0

cmd="$(printf '%s' "$input" | jq -r '.tool_input.command // empty' 2>/dev/null)"
[ -n "$cmd" ] || exit 0

# Strip single- and double-quoted spans so `git stash pop` mentioned INSIDE a
# string (echo, commit message, heredoc, docs) is never mistaken for a real
# invocation. All command-shape detection below runs on this quote-stripped
# skeleton.
skel="$(printf '%s' "$cmd" | sed -E "s/'[^']*'//g; s/\"[^\"]*\"//g")"

# Is this a real `git stash` SUBCOMMAND? git at a command boundary, then only
# global options (-C path, -c x=y, --long ...) before the `stash` subcommand.
printf '%s' "$skel" | grep -Eq '(^|[;&|(`]|&&|\|\|)[[:space:]]*git([[:space:]]+-[^[:space:]]+([[:space:]]+[^-[:space:]][^[:space:]]*)?)*[[:space:]]+stash([[:space:]]|$)' || exit 0

deny() {
  jq -n --arg r "$1" \
    '{hookSpecificOutput:{hookEventName:"PreToolUse",permissionDecision:"deny",permissionDecisionReason:$r}}'
  exit 0
}

deny_msg="🛑 git-stash-guard: mutating 'git stash' is forbidden here — the stash stack is REPO-GLOBAL, shared across ALL worktrees and parallel sessions (on 2026-08-01 a worktree agent popped another session's WIP; see memory: project_git_stash_shared_across_worktrees.md). Instead: commit WIP to a temp branch ('git checkout -b wip/<lane> && git commit -am wip') or simply leave the tree dirty. Read-only 'git stash list' / 'git stash show' are allowed."

# Split the skeleton into command segments (on ; & | ( ` — && and || fall out
# of ; & | naturally) and classify EVERY `git stash` segment, so a mutating
# stash cannot hide behind a later read-only one in a compound command. For
# each stash segment, the first non-flag token after `stash` is the stash
# SUBcommand; flags like -u / -q / --include-untracked are skipped.
while IFS= read -r seg; do
  printf '%s' "$seg" | grep -Eq '^[[:space:]]*git([[:space:]]+-[^[:space:]]+([[:space:]]+[^-[:space:]][^[:space:]]*)?)*[[:space:]]+stash([[:space:]]|$)' || continue
  after="$(printf '%s' "$seg" | sed -E 's/^[[:space:]]*git([[:space:]]+-[^[:space:]]+([[:space:]]+[^-[:space:]][^[:space:]]*)?)*[[:space:]]+stash//')"
  sub="$(printf '%s' "$after" | awk '{for(i=1;i<=NF;i++){if($i !~ /^-/){print $i; exit}}}')"
  case "$sub" in
    list|show)
      # Read-only — allowed; keep checking remaining segments.
      ;;
    ""|push|save|pop|apply|drop|clear|branch|create|store)
      # Bare `git stash` (implicit push, even with flags like -u) or an
      # explicitly mutating subcommand — blocked.
      deny "$deny_msg"
      ;;
    *)
      # Unknown token after stash (fail-open, consistent with guard
      # philosophy); keep checking remaining segments.
      ;;
  esac
done <<EOF
$(printf '%s' "$skel" | tr ';&|(`' '\n\n\n\n\n')
EOF

exit 0
