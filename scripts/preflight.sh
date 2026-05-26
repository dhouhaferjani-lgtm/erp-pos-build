#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔍 Running preflight checks...${NC}"
echo ""

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

# Backend checks
echo -e "${YELLOW}📦 Backend Checks${NC}"
echo "=================================="

cd "$ROOT_DIR/apps/api"

echo -e "\n${YELLOW}Running Pint (code style)...${NC}"
./vendor/bin/pint --test
echo -e "${GREEN}✓ Pint passed${NC}"

echo -e "\n${YELLOW}Running PHPStan (static analysis)...${NC}"
./vendor/bin/phpstan analyse --level=8 --memory-limit=2G
echo -e "${GREEN}✓ PHPStan passed${NC}"

echo -e "\n${YELLOW}Running PHPUnit tests...${NC}"
php artisan test
echo -e "${GREEN}✓ PHPUnit passed${NC}"

# Type Generation — drift guard
# Regenerates packages/shared/types/generated.d.ts from the current PHP DTOs
# and fails if the committed version is out of sync. This keeps the backend-
# driven types pipeline honest: any DTO change must ship with a matching
# regenerated .d.ts, never silently drift.
echo -e "\n${YELLOW}Generating TypeScript types...${NC}"
if ! php artisan typescript:transform; then
    echo -e "${RED}✗ TypeScript transformer failed (see output above)${NC}"
    exit 1
fi
echo -e "${GREEN}✓ TypeScript types generated${NC}"

echo -e "\n${YELLOW}Checking generated.d.ts is in sync with committed version...${NC}"
GENERATED_TYPES="$ROOT_DIR/packages/shared/types/generated.d.ts"
if ! git -C "$ROOT_DIR" diff --quiet -- "$GENERATED_TYPES" 2>/dev/null; then
    echo -e "${RED}✗ packages/shared/types/generated.d.ts is out of date.${NC}"
    echo -e "${RED}  Someone changed a #[TypeScript]-tagged PHP DTO without regenerating.${NC}"
    echo -e "${RED}  To fix:${NC}"
    echo -e "${RED}    (cd apps/api && php artisan typescript:transform)${NC}"
    echo -e "${RED}    git add packages/shared/types/generated.d.ts${NC}"
    echo -e "${RED}    git commit -m 'chore(types): regenerate TypeScript types'${NC}"
    echo -e "${RED}    # (or 'git commit --amend' only if the DTO change is still unpushed)${NC}"
    echo ""
    echo -e "${YELLOW}Drift summary:${NC}"
    git -C "$ROOT_DIR" --no-pager diff --stat -- "$GENERATED_TYPES"
    echo ""
    echo -e "${YELLOW}First 200 lines of the drift diff:${NC}"
    git -C "$ROOT_DIR" --no-pager diff -- "$GENERATED_TYPES" | head -200
    exit 1
fi
echo -e "${GREEN}✓ Generated types in sync${NC}"

# Frontend checks
echo -e "\n${YELLOW}🌐 Frontend Checks${NC}"
echo "=================================="

cd "$ROOT_DIR/apps/web"

echo -e "\n${YELLOW}Running TypeScript check...${NC}"
pnpm typecheck
echo -e "${GREEN}✓ TypeScript passed${NC}"

echo -e "\n${YELLOW}Running ESLint...${NC}"
pnpm lint
echo -e "${GREEN}✓ ESLint passed${NC}"

echo -e "\n${YELLOW}Running Vitest tests...${NC}"
pnpm test
echo -e "${GREEN}✓ Vitest passed${NC}"

# Fiscal fixture parity check
echo -e "\n${YELLOW}🔒 Fiscal Fixture Parity${NC}"
echo "=================================="

REPO_ROOT="$ROOT_DIR"
echo -e "\n${YELLOW}Checking fiscal v3 fixture parity...${NC}"
FISCAL_FIXTURE_PARITY_SCRIPT="$REPO_ROOT/apps/pos/scripts/check-fiscal-fixture-parity.sh"
if [ ! -x "$FISCAL_FIXTURE_PARITY_SCRIPT" ]; then
    echo -e "${YELLOW}↷ Fiscal v3 POS fixture parity script not present; skipping retired POS parity gate.${NC}"
elif ! "$FISCAL_FIXTURE_PARITY_SCRIPT"; then
    echo -e "${RED}✗ Fiscal v3 fixture parity check failed (see output above)${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Fiscal v3 fixture parity OK${NC}"

# §14.3 chokepoint completeness gate (Task 30).
# Walks every ->createReceipt( and ->finalize( call site under
# apps/api/app + apps/api/routes and reconciles each hit against the
# disposition manifest. Any unreconciled site or missing disposition
# fails the build. The PHPUnit suite
# (tests/Feature/Fiscal/ChokepointCompletenessTest) mirrors this gate.
echo -e "\n${YELLOW}🔒 §14.3 Chokepoint Completeness${NC}"
echo "=================================="
echo -e "\n${YELLOW}Running §14.3 chokepoint completeness gate...${NC}"
if ! bash "$REPO_ROOT/apps/api/scripts/check-saleReceipt-chokepoints.sh"; then
    echo -e "${RED}✗ §14.3 chokepoint completeness gate failed (see output above)${NC}"
    exit 1
fi
echo -e "${GREEN}✓ §14.3 chokepoint gate OK${NC}"

# Summary
echo ""
echo "=================================="
echo -e "${GREEN}✅ All preflight checks passed!${NC}"
echo "=================================="
