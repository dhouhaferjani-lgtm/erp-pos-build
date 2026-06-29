#!/usr/bin/env bash
# visual-test-gate.sh -- Playwright pre-commit gate for UI changes
#
# Checks if staged files include UI changes (*.tsx, *.css, apps/spa/, apps/web/).
# If yes: runs Playwright tests (headless). Blocks commit on failure.
# If no UI files staged: exits 0 immediately.
#
# Works on both macOS and Linux (headless mode).
# Install as pre-commit hook or run manually before committing.

set -euo pipefail

# Colors (disable if not a terminal)
if [ -t 1 ]; then
  RED='\033[0;31m'
  GREEN='\033[0;32m'
  YELLOW='\033[0;33m'
  NC='\033[0m'
else
  RED=''
  GREEN=''
  YELLOW=''
  NC=''
fi

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || echo "$(cd "$(dirname "$0")/.." && pwd)")"

echo "${YELLOW}[visual-test-gate]${NC} Checking staged files for UI changes..."

# Get list of staged files (added, modified, renamed)
STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACMR 2>/dev/null || true)

if [ -z "$STAGED_FILES" ]; then
  # No staged files -- might be running manually outside of a commit
  # Fall back to checking uncommitted changes
  STAGED_FILES=$(git diff --name-only --diff-filter=ACMR HEAD 2>/dev/null || true)
fi

if [ -z "$STAGED_FILES" ]; then
  echo "${GREEN}[visual-test-gate]${NC} No changed files detected. Skipping."
  exit 0
fi

# Check for UI-related file patterns
UI_PATTERNS=(
  '\.tsx$'
  '\.css$'
  '^apps/web/src/'
  '^apps/spa/src/'
  '^apps/web/e2e/'
  'designTokens\.ts$'
  'tailwind\.config'
)

HAS_UI_CHANGES=false

for pattern in "${UI_PATTERNS[@]}"; do
  if echo "$STAGED_FILES" | grep -qE "$pattern"; then
    HAS_UI_CHANGES=true
    break
  fi
done

if [ "$HAS_UI_CHANGES" = false ]; then
  echo "${GREEN}[visual-test-gate]${NC} No UI files changed. Skipping Playwright tests."
  exit 0
fi

echo "${YELLOW}[visual-test-gate]${NC} UI changes detected. Running Playwright tests..."

# Show which UI files triggered the gate
echo ""
echo "UI files changed:"
for pattern in "${UI_PATTERNS[@]}"; do
  echo "$STAGED_FILES" | grep -E "$pattern" | sed 's/^/  /' || true
done
echo ""

# Determine the web app directory
WEB_DIR="${REPO_ROOT}/apps/web"

if [ ! -d "$WEB_DIR" ]; then
  echo "${RED}[visual-test-gate]${NC} ERROR: apps/web/ directory not found at ${WEB_DIR}"
  exit 1
fi

# Check if Playwright is installed
if [ ! -f "${WEB_DIR}/node_modules/.bin/playwright" ] && ! command -v npx &>/dev/null; then
  echo "${RED}[visual-test-gate]${NC} ERROR: Playwright not found. Run 'cd apps/web && pnpm install' first."
  exit 1
fi

# Detect OS for headless configuration
case "$(uname -s)" in
  Linux*)
    # On Linux (CI/VPS), ensure Xvfb or headless mode
    export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-${HOME}/.cache/ms-playwright}"
    # Headless is the default -- no extra config needed
    ;;
  Darwin*)
    # On macOS (laptop), headless works out of the box
    ;;
  *)
    echo "${YELLOW}[visual-test-gate]${NC} Unknown OS: $(uname -s). Attempting headless mode."
    ;;
esac

# Run Playwright tests in headless mode
cd "$WEB_DIR"

echo "${YELLOW}[visual-test-gate]${NC} Running: npx playwright test --reporter=list"
echo ""

if npx playwright test --reporter=list 2>&1; then
  echo ""
  echo "${GREEN}[visual-test-gate]${NC} Playwright tests PASSED. Commit allowed."
  exit 0
else
  EXIT_CODE=$?
  echo ""
  echo "${RED}[visual-test-gate]${NC} Playwright tests FAILED (exit code: ${EXIT_CODE})."
  echo "${RED}[visual-test-gate]${NC} Fix the failing tests before committing."
  echo ""
  echo "To debug interactively:"
  echo "  cd apps/web && npx playwright test --ui"
  echo ""
  echo "To update snapshots (if intentional visual changes):"
  echo "  cd apps/web && npx playwright test --update-snapshots"
  echo ""
  exit 1
fi
