# PR #113 — FullReceiptResponse line IDs — Codex review trail

**Branch:** `type/pos-receipt-line-ids`
**Base:** `dev`
**Reviewer:** Codex CLI (`codex review --base dev`)
**Final state:** 2 rounds, APPROVE.

This PR adds C2 Day 3 receipt-line identity fields (`product_id`, `composite_item_id`, `menu_category_id`) to the POS `FullReceiptResponse` type and keeps the offline pre-sync print adapter aligned with the same shape.

## Round 1 — APPROVE-WITH-MINOR-EDITS-APPLIED (1 P2 closed)

### P2 — Preserve menu category when building offline receipts

The first pass copied `menu_category_id` only if it was already present in the offline receipt JSON. `receiptService.createOfflineReceipt` stores Menu-tenant cart line IDs as the composite `${sellable_id}_${menu_category_id}` in `product_id` / `composite_item_id`, so newly-created offline receipts would still expose a composite `product_id` and `menu_category_id: null` in the print adapter.

**Closure (`9210e71a`):** `getOfflineReceiptForPrint` now uses `parseMenuCompositeId` to unpack both `product_id` and `composite_item_id`, preserving category context when the stored offline line is composite and degrading to `null` for bare IDs. Added a regression test covering the offline Menu composite path.

## Round 2 — APPROVE

> The changes consistently add line ID fields to the offline receipt-to-print path and update the typed/test fixtures accordingly. I did not identify any discrete introduced bug that would break existing behavior.

No findings. PR ready for merge.

## Final shape

- **2 commits** (initial type/fixture update + Codex P2 fix).
- **3 source/test files modified initially**:
  - `apps/pos/src/types/receipt.ts`
  - `apps/pos/src/lib/offline/getOfflineReceiptForPrint.ts`
  - `apps/pos/src/lib/__tests__/buildReceiptData.test.ts`
- **1 regression test added** in `apps/pos/src/lib/offline/__tests__/getOfflineReceiptForPrint.test.ts`.
- POS gates after round-1 fix:
  - `pnpm typecheck` — 0 errors.
  - `pnpm lint` — 0 errors / 41 warnings.
  - `pnpm test` — 1201/1201 pass across 133 files.

## Pre-flight audit

- **L9 ingress audit:** type-level receipt-line shape change. Audited `FullReceiptResponse` consumers: `receiptApi.fetchReceipt` wire boundary, `buildReceiptData` receipt printing, `getOfflineReceiptForPrint` offline pre-sync print adapter, and test fixtures.
- **Server receipt wire boundary:** additive nullable fields match `ReceiptController::show` / `$line->toArray()` semantics after C2 Day 3.
- **Offline pre-sync print boundary:** local SQLite lines now surface bare `product_id` / `composite_item_id` plus `menu_category_id` when composite IDs are stored; legacy/offline bare rows degrade to `null` category context.
- **L1 cross-tenant audit:** Menu tenants can carry product/composite/category context; standard-retail and non-Menu tenants receive bare IDs with nullable composite/category fields; hybrid/pre-C2 historical lines tolerate `null`.
- **L8 ownership audit:** no screen or state-transition ownership changes.
