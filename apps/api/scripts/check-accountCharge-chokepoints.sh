#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
cd "$REPO_ROOT"

FAILED=0

fail() {
    echo "ACCOUNT_CHARGE chokepoint gate: FAIL — $1" >&2
    FAILED=1
}

require_rg() {
    if ! command -v rg >/dev/null 2>&1; then
        echo "ACCOUNT_CHARGE chokepoint gate: FAIL — ripgrep (rg) is required" >&2
        exit 127
    fi
}

require_pattern() {
    local pattern="$1"
    local label="$2"
    shift 2

    if ! rg -q "$pattern" "$@"; then
        fail "$label"
    fi
}

forbid_pattern() {
    local pattern="$1"
    local label="$2"
    shift 2

    if rg -n "$pattern" "$@"; then
        fail "$label"
    fi
}

require_rg

forbid_pattern \
    "ACCOUNT_CHARGE" \
    "ReceiptCreationService::createReceipt must not author or branch on ACCOUNT_CHARGE" \
    "apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php"

forbid_pattern \
    "ACCOUNT_CHARGE" \
    "ReceiptController legacy receipt endpoints must not author or branch on ACCOUNT_CHARGE" \
    "apps/api/app/Modules/POS/Presentation/Controllers/ReceiptController.php"

forbid_pattern \
    "ACCOUNT_CHARGE" \
    "legacy /pos/receipts route definitions must not expose ACCOUNT_CHARGE authoring" \
    "apps/api/app/Modules/POS/routes.php"

forbid_pattern \
    "/pos/receipts/sync" \
    "retired /pos/receipts/sync route resurfaced" \
    "apps/api/app/Modules/POS/routes.php" \
    "apps/api/routes"

forbid_pattern \
    "api(Post|Fetch|Request)[^\\n]*['\"]/pos/receipts/sync" \
    "retired /pos/receipts/sync client transport resurfaced" \
    "apps/pos/src"

forbid_pattern \
    "/pos/receipts" \
    "device account-charge path must not call legacy receipt endpoints" \
    "apps/pos/src/lib/accountCharge"

require_pattern \
    "FiscalEventType::ACCOUNT_CHARGE->value => \\[AccountChargePayload::class, 1\\]" \
    "FiscalEventPayloadRegistry must register ACCOUNT_CHARGE v1" \
    "apps/api/app/Modules/Fiscal/Application/Services/FiscalEventPayloadRegistry.php"

require_pattern \
    "case 'ACCOUNT_CHARGE'" \
    "device FiscalEventEngine must route ACCOUNT_CHARGE validation" \
    "apps/pos/src/lib/fiscal/FiscalEventEngine.ts"

require_pattern \
    "validateAccountChargePayload" \
    "device FiscalEventEngine must keep ACCOUNT_CHARGE payload validation live" \
    "apps/pos/src/lib/fiscal/FiscalEventEngine.ts"

require_pattern \
    "getFiscalEventEngine" \
    "accountChargeService must resolve the FiscalEventEngine" \
    "apps/pos/src/lib/accountCharge/accountChargeService.ts"

require_pattern \
    "engine\\.append" \
    "accountChargeService must append through FiscalEventEngine" \
    "apps/pos/src/lib/accountCharge/accountChargeService.ts"

require_pattern \
    "event_type: 'ACCOUNT_CHARGE'" \
    "accountChargeService append payload must declare ACCOUNT_CHARGE" \
    "apps/pos/src/lib/accountCharge/accountChargeService.ts"

if [[ "$FAILED" -ne 0 ]]; then
    exit 1
fi

echo "ACCOUNT_CHARGE chokepoint gate: PASS"
