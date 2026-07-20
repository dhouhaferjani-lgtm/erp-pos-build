#!/bin/bash
set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}🔍 Running preflight checks...${NC}"
echo ""

# -----------------------------------------------------------------------------
# PREFLIGHT_SCOPE — laptop-safe by default, full suite on the VPS/CI.
#
# The FULL PHPUnit suite is FORBIDDEN on the owner's laptop: it exhausts memory
# and crashes the machine. It is desired on the VPS / CI, where resources are
# not shared with the owner's work. This variable gates the PHPUnit invocation
# ONLY. Every other gate (Pint, PHPStan, TypeScript, ESLint, TanStack key audit,
# Vitest, fiscal-fixture parity, §14.3 chokepoint) runs UNCONDITIONALLY in both
# modes.
#
#   PREFLIGHT_SCOPE=paths  (DEFAULT — laptop-safe)
#       Runs ONLY the tests listed in PREFLIGHT_TEST_PATHS (space-separated),
#       or SKIPS PHPUnit with a loud warning when PREFLIGHT_TEST_PATHS is empty.
#       Examples:
#         ./scripts/preflight.sh
#         PREFLIGHT_TEST_PATHS='tests/Feature/Partner tests/Unit/Fiscal' ./scripts/preflight.sh
#
#   PREFLIGHT_SCOPE=full   (VPS / CI ONLY — CRASHES the laptop)
#       Runs the entire PHPUnit suite via `php artisan test`.
#       Example:
#         PREFLIGHT_SCOPE=full ./scripts/preflight.sh
#
# Optional additive scopes (unset preserves the existing full checks):
#   PREFLIGHT_PINT_PATHS='app/Modules/Foo tests/Feature/Foo'
#   PREFLIGHT_PHPSTAN_PATHS='app/Modules/Foo app/Shared/DTOs/FooData.php'
#   PREFLIGHT_VITEST_PATHS='src/features/foo src/components/Foo.test.tsx'
# -----------------------------------------------------------------------------
PREFLIGHT_SCOPE="${PREFLIGHT_SCOPE:-paths}"
PHPUNIT_SKIPPED=0

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
ROOT_DIR="$( cd "$SCRIPT_DIR/.." && pwd )"

# Backend checks
echo -e "${YELLOW}📦 Backend Checks${NC}"
echo "=================================="

cd "$ROOT_DIR/apps/api"

echo -e "\n${YELLOW}Running Pint (code style)...${NC}"
if [ -n "${PREFLIGHT_PINT_PATHS:-}" ]; then
    # shellcheck disable=SC2086
    ./vendor/bin/pint --test ${PREFLIGHT_PINT_PATHS}
else
    ./vendor/bin/pint --test
fi
echo -e "${GREEN}✓ Pint passed${NC}"

echo -e "\n${YELLOW}Running PHPStan (static analysis)...${NC}"
if [ -n "${PREFLIGHT_PHPSTAN_PATHS:-}" ]; then
    # shellcheck disable=SC2086
    ./vendor/bin/phpstan analyse ${PREFLIGHT_PHPSTAN_PATHS} --level=8 --memory-limit=2G
else
    ./vendor/bin/phpstan analyse --level=8 --memory-limit=2G
fi
echo -e "${GREEN}✓ PHPStan passed${NC}"

echo -e "\n${YELLOW}Running PHPUnit tests (scope: ${PREFLIGHT_SCOPE})...${NC}"
case "$PREFLIGHT_SCOPE" in
    full)
        echo -e "${YELLOW}  Scope=full — running the ENTIRE PHPUnit suite (VPS/CI only).${NC}"
        php artisan test
        echo -e "${GREEN}✓ PHPUnit passed (full suite)${NC}"
        ;;
    paths)
        if [ -n "${PREFLIGHT_TEST_PATHS:-}" ]; then
            echo -e "${YELLOW}  Scope=paths — running only: ${PREFLIGHT_TEST_PATHS}${NC}"
            # shellcheck disable=SC2086
            php artisan test ${PREFLIGHT_TEST_PATHS}
            echo -e "${GREEN}✓ PHPUnit passed (scoped paths)${NC}"
        else
            PHPUNIT_SKIPPED=1
            echo -e "${RED}⚠️  ============================================================${NC}"
            echo -e "${RED}⚠️  SKIPPING PHPUnit: PREFLIGHT_SCOPE=paths (default) and no${NC}"
            echo -e "${RED}⚠️  PREFLIGHT_TEST_PATHS were provided.${NC}"
            echo -e "${RED}⚠️  Backend tests were NOT run — this preflight is INCOMPLETE.${NC}"
            echo -e "${RED}⚠️${NC}"
            echo -e "${RED}⚠️  Run the tests that cover your change (laptop-safe):${NC}"
            echo -e "${RED}⚠️    PREFLIGHT_TEST_PATHS='tests/Feature/Foo tests/Unit/Bar' ./scripts/preflight.sh${NC}"
            echo -e "${RED}⚠️  Or run the FULL suite on the VPS/CI (NEVER on the laptop):${NC}"
            echo -e "${RED}⚠️    PREFLIGHT_SCOPE=full ./scripts/preflight.sh${NC}"
            echo -e "${RED}⚠️  ============================================================${NC}"
        fi
        ;;
    *)
        echo -e "${RED}✗ Unknown PREFLIGHT_SCOPE='${PREFLIGHT_SCOPE}' (expected 'paths' or 'full')${NC}"
        exit 1
        ;;
esac

# Type Generation — drift guard
# Regenerates packages/shared/types/generated.d.ts from the current PHP DTOs
# and fails if the committed version is out of sync. This keeps the backend-
# driven types pipeline honest: any DTO change must ship with a matching
# regenerated .d.ts, never silently drift.
echo -e "\n${YELLOW}Generating TypeScript types...${NC}"
if ! CACHE_STORE=array php artisan typescript:transform; then
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
    echo -e "${RED}    (cd apps/api && CACHE_STORE=array php artisan typescript:transform)${NC}"
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

# Frontend permission map — drift guard
# Regenerates the frontend fallback map from RolesAndPermissionsSeeder and
# fails if the committed artifact no longer matches the backend source.
echo -e "\n${YELLOW}Generating frontend permission map...${NC}"
if ! php artisan permissions:export-frontend-map; then
    echo -e "${RED}✗ Frontend permission map exporter failed (see output above)${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Frontend permission map generated${NC}"

echo -e "\n${YELLOW}Checking frontend permission map is in sync with committed version...${NC}"
GENERATED_PERMISSIONS="$ROOT_DIR/apps/web/src/hooks/permissionsMap.generated.ts"
if ! git -C "$ROOT_DIR" diff --quiet -- "$GENERATED_PERMISSIONS" 2>/dev/null; then
    echo -e "${RED}✗ apps/web/src/hooks/permissionsMap.generated.ts is out of date.${NC}"
    echo -e "${RED}  To fix:${NC}"
    echo -e "${RED}    (cd apps/api && php artisan permissions:export-frontend-map)${NC}"
    echo -e "${RED}    git add apps/web/src/hooks/permissionsMap.generated.ts${NC}"
    echo ""
    echo -e "${YELLOW}Drift summary:${NC}"
    git -C "$ROOT_DIR" --no-pager diff --stat -- "$GENERATED_PERMISSIONS"
    echo ""
    echo -e "${YELLOW}First 200 lines of the drift diff:${NC}"
    git -C "$ROOT_DIR" --no-pager diff -- "$GENERATED_PERMISSIONS" | head -200
    exit 1
fi
echo -e "${GREEN}✓ Frontend permission map in sync${NC}"

# Frontend checks
echo -e "\n${YELLOW}🌐 Frontend Checks${NC}"
echo "=================================="

cd "$ROOT_DIR/apps/web"

echo -e "\n${YELLOW}Running TypeScript check...${NC}"
pnpm typecheck
echo -e "${GREEN}✓ TypeScript passed${NC}"

echo -e "\n${YELLOW}Running ESLint...${NC}"
pnpm lint:eslint
echo -e "${GREEN}✓ ESLint passed${NC}"

echo -e "\n${YELLOW}Running TanStack query key audit...${NC}"
pnpm audit:keys
echo -e "${GREEN}✓ TanStack query key audit passed${NC}"

echo -e "\n${YELLOW}Running design-system audit...${NC}"
pnpm audit:design-system
echo -e "${GREEN}✓ Design-system audit passed${NC}"

echo -e "\n${YELLOW}Running quantity-display audit...${NC}"
pnpm audit:quantity
echo -e "${GREEN}✓ Quantity-display audit passed${NC}"

echo -e "\n${YELLOW}Running POS ESLint rule tests...${NC}"
( cd "$ROOT_DIR/apps/pos" && pnpm test:eslint-rules )
echo -e "${GREEN}✓ POS ESLint rule tests passed${NC}"

echo -e "\n${YELLOW}Running route manifest drift check...${NC}"
bash "$ROOT_DIR/scripts/factory/check-manifest-drift.sh"
echo -e "${GREEN}✓ Route manifests in sync${NC}"

echo -e "\n${YELLOW}Running Vitest tests...${NC}"
if [ -n "${PREFLIGHT_VITEST_PATHS:-}" ]; then
    # shellcheck disable=SC2086
    pnpm vitest run ${PREFLIGHT_VITEST_PATHS}
else
    pnpm test
fi
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
if [ "$PHPUNIT_SKIPPED" -eq 1 ]; then
    echo -e "${YELLOW}⚠️  Preflight finished — but PHPUnit was SKIPPED (no PREFLIGHT_TEST_PATHS).${NC}"
    echo -e "${YELLOW}⚠️  This run is NOT a full green. Backend tests were not exercised.${NC}"
    echo -e "${YELLOW}⚠️  Run the covering tests, or PREFLIGHT_SCOPE=full on the VPS/CI.${NC}"
else
    echo -e "${GREEN}✅ All preflight checks passed!${NC}"
fi
echo "=================================="
