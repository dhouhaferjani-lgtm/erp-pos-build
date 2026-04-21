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
./vendor/bin/phpstan analyse --level=8 --memory-limit=512M
echo -e "${GREEN}✓ PHPStan passed${NC}"

echo -e "\n${YELLOW}Running PHPUnit tests...${NC}"
php artisan test
echo -e "${GREEN}✓ PHPUnit passed${NC}"

# Type Generation — regenerate then fail on any drift between DTOs and committed generated.ts
echo -e "\n${YELLOW}Regenerating TypeScript types and checking for drift...${NC}"
php artisan typescript:transform
if ! git -C "$ROOT_DIR" diff --exit-code packages/shared/types/generated.ts > /dev/null 2>&1; then
    echo -e "${RED}✗ Generated TypeScript types are out of sync with DTOs.${NC}"
    echo -e "${RED}  Run 'cd apps/api && php artisan typescript:transform' and commit the diff.${NC}"
    git -C "$ROOT_DIR" --no-pager diff --stat packages/shared/types/generated.ts
    exit 1
fi
echo -e "${GREEN}✓ TypeScript types are in sync${NC}"

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

# Summary
echo ""
echo "=================================="
echo -e "${GREEN}✅ All preflight checks passed!${NC}"
echo "=================================="
