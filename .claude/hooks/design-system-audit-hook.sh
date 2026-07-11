#!/usr/bin/env bash
# PostToolUse hook (Edit/Write): runs the design-system audit scoped to an edited
# apps/web source file and surfaces NEW violations as feedback to the agent.
# Fast path: exits silently for non-web files, tests, or when tooling is absent.
set -uo pipefail

INPUT="$(cat)"
FILE_PATH="$(printf '%s' "$INPUT" | /usr/bin/python3 -c 'import json,sys
try:
    d = json.load(sys.stdin)
    print(d.get("tool_input", {}).get("file_path", ""))
except Exception:
    print("")' 2>/dev/null)"

case "$FILE_PATH" in
  */apps/web/src/features/*|*/apps/web/src/pages/*) : ;;
  *) exit 0 ;;
esac
case "$FILE_PATH" in
  *.test.*|*__tests__*|*.stories.*) exit 0 ;;
esac

WEB_DIR="${FILE_PATH%%/src/*}"
AUDIT="$WEB_DIR/tools/audit-design-system.mjs"
[ -f "$AUDIT" ] || exit 0

OUT="$(cd "$WEB_DIR" && node tools/audit-design-system.mjs 2>&1)"
REL="${FILE_PATH#"$WEB_DIR"/}"
NEW_HITS="$(printf '%s\n' "$OUT" | grep -F "$REL" | head -5)"

if [ -n "$NEW_HITS" ] && printf '%s' "$OUT" | grep -q '[1-9][0-9]* new'; then
  echo "design-system audit: this edit introduced NEW violations in $REL —"
  printf '%s\n' "$NEW_HITS"
  echo "Use the canonical atoms/tokens (see .claude/agents/frontend-conventions-reviewer.md for the ruleset). Never alias tokens to hide raw controls; never interpolate variant prefixes/opacity onto tokens."
  exit 2
fi
exit 0
