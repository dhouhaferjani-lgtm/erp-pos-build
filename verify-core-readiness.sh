#!/bin/bash

# Core Readiness Verification Script
# This script runs automated checks for the core platform

set -e

echo "========================================="
echo "Core Readiness Verification"
echo "Date: $(date)"
echo "========================================="
echo ""

API_URL="http://localhost:8000/api/v1"
TOKEN=""

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Login and get token
echo "🔐 Logging in..."
LOGIN_RESPONSE=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password"}')

TOKEN=$(echo $LOGIN_RESPONSE | jq -r '.data.token // empty')

if [ -z "$TOKEN" ]; then
  echo -e "${RED}❌ Login failed${NC}"
  exit 1
fi

echo -e "${GREEN}✅ Login successful${NC}"
echo ""

# Test endpoints
declare -A ENDPOINTS=(
  ["Partners"]="/partners"
  ["Products"]="/products"
  ["Accounts"]="/accounts"
  ["Stock Levels"]="/stock-levels"
  ["Documents"]="/documents"
  ["Payments"]="/payments"
  ["Payment Methods"]="/payment-methods"
  ["Payment Repositories"]="/payment-repositories"
)

echo "📋 Testing API Endpoints..."
echo "-------------------------------------------"

for name in "${!ENDPOINTS[@]}"; do
  endpoint="${ENDPOINTS[$name]}"
  response=$(curl -s -w "\n%{http_code}" -X GET "$API_URL$endpoint" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Accept: application/json")

  http_code=$(echo "$response" | tail -n1)
  body=$(echo "$response" | sed '$d')

  if [ "$http_code" = "200" ]; then
    count=$(echo "$body" | jq -r '.data | length // 0')
    echo -e "${GREEN}✅${NC} $name: $http_code (${count} items)"
  else
    echo -e "${RED}❌${NC} $name: $http_code"
    echo "   Response: $(echo "$body" | jq -r '.message // .error // "Unknown error"')"
  fi
done

echo ""
echo "📊 Testing Accounting Reports..."
echo "-------------------------------------------"

# Trial Balance
TB_RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$API_URL/reports/trial-balance" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json")
TB_CODE=$(echo "$TB_RESPONSE" | tail -n1)

if [ "$TB_CODE" = "200" ]; then
  echo -e "${GREEN}✅${NC} Trial Balance: $TB_CODE"
else
  echo -e "${RED}❌${NC} Trial Balance: $TB_CODE"
fi

# General Ledger
GL_RESPONSE=$(curl -s -w "\n%{http_code}" -X GET "$API_URL/ledger" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json")
GL_CODE=$(echo "$GL_RESPONSE" | tail -n1)

if [ "$GL_CODE" = "200" ]; then
  echo -e "${GREEN}✅${NC} General Ledger: $GL_CODE"
else
  echo -e "${RED}❌${NC} General Ledger: $GL_CODE"
fi

echo ""
echo "🗄️  Testing Database State..."
echo "-------------------------------------------"

cd apps/api

# Check key tables have data
PARTNERS_COUNT=$(php artisan tinker --execute="echo App\Modules\Partner\Domain\Partner::count();" 2>/dev/null)
PRODUCTS_COUNT=$(php artisan tinker --execute="echo App\Modules\Product\Domain\Product::count();" 2>/dev/null)
ACCOUNTS_COUNT=$(php artisan tinker --execute="echo App\Modules\Accounting\Domain\Account::count();" 2>/dev/null)
DOCUMENTS_COUNT=$(php artisan tinker --execute="echo App\Modules\Document\Domain\Document::count();" 2>/dev/null)

echo "Partners: $PARTNERS_COUNT"
echo "Products: $PRODUCTS_COUNT"
echo "Accounts: $ACCOUNTS_COUNT"
echo "Documents: $DOCUMENTS_COUNT"

cd ../..

echo ""
echo "========================================="
echo "Verification Complete"
echo "========================================="
