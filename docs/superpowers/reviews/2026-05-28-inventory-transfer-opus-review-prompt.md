# Adversarial Review — Inventory Transfer (PR #147)

You are an adversarial reviewer. The author thinks this PR is ready to merge to `dev`. Your job is to **find what they got wrong** and write the review to disk.

## Output

Save your review to `apps/erp/docs/superpowers/reviews/2026-05-28-inventory-transfer-opus-review.md` (path is relative to repo root `/Users/houssamr/Projects/syneriva`). Do not return the review inline. Use this structure:

```
# Inventory Transfer (PR #147) — Opus Adversarial Review

**Verdict:** APPROVE | APPROVE-WITH-MINOR-EDITS | REQUEST-CHANGES | BLOCKER
**Confidence:** low | medium | high
**Date:** 2026-05-28

## Summary

## BLOCKERS (must fix before merge)

## P1 (strong concerns)

## P2 (worth fixing this PR)

## P3 (nits, future)

## What I verified by reading code

## What I could not verify
```

Cite file paths + line numbers for every finding. **Read the actual code before claiming a finding** — do not invent issues. If you assert a bug, write the failing scenario in plain English so the author can reproduce.

## Context

- **Branch:** `feat/inventory-transfer` (worktree at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`; same repo `otospexsolutions/erp`).
- **PR:** https://github.com/otospexsolutions/erp/pull/147
- **Scope:** T1 spec Phase 2 (intracompany scope only). The author has explicitly deferred Scenario B intercompany, per-location tax_id, InTransitAvailability per-company setting, and batch preservation via `inventory_batch_movements` on transfer legs.
- **Spec:** `apps/erp/docs/superpowers/specs/2026-05-24-t1-stock-transfer.md`
- **Design note:** `apps/erp/docs/superpowers/coordination/2026-05-28-inventory-transfer.md`
- **CLAUDE.md operational rules:** repo root + `apps/erp/CLAUDE.md` — strict typing, hexagonal architecture, constructor injection only, no `mixed` / `any`, tests-first, route middleware `['api', 'auth:sanctum', SetPermissionsTeam::class]`, frontend design tokens + i18n, etc.
- **Memory anchor (locked):** `project_inventory_costing.md` — WAC is company-wide, never per-location. The new `WeightedAverageCostService::recordCostAdjustment` is the single seam any future WAC-affecting event (revaluation, rebate, duty, write-down) must call.

## Files to read first (in order)

1. `apps/erp/apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php` — schema, indexes, CHECK constraints
2. `apps/erp/apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php`, `TransferType.php`, `TransferCostDistribution.php`
3. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockTransfer.php`, `StockTransferLine.php`
4. `apps/erp/apps/api/app/Modules/Inventory/Domain/Events/StockTransferInitiated.php`, `StockTransferCompleted.php`, `StockTransferCancelled.php`
5. `apps/erp/apps/api/app/Modules/Inventory/Domain/Exceptions/TransferStateException.php`
6. `apps/erp/apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php`, `InitiateTransferLineData.php`
7. `apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` — **the heart of this PR**
8. `apps/erp/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` — only the new `recordCostAdjustment` method (around line 440+)
9. `apps/erp/apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`
10. `apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
11. `apps/erp/apps/api/app/Modules/Inventory/Presentation/routes.php` (only the new `/stock-transfers` block + diff context)
12. `apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php` (only the new `inventory.transfers.*` lines)
13. `apps/erp/apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`
14. Frontend: `apps/erp/apps/web/src/features/stock-transfers/**`, `apps/erp/apps/web/src/locales/{en,fr}/stock-transfers.json`, `apps/erp/apps/web/src/lib/i18n.ts` (only the diff), `apps/erp/apps/web/src/hooks/usePermissions.ts` (only the new entries), `apps/erp/apps/web/src/routes/index.tsx` (only the new routes block)

## Adversarial focus — what to attack hard

### 1. The "re-label most recent stock_movement" pattern in `StockTransferService` ⚠️

`initiate()`, `complete()`, and `cancel()` all call `StockAdjustmentService::issue()` / `receive()` to move stock, then immediately run a `StockMovement::query()->where('reference', $reference)->orderByDesc('created_at')->limit(1)->update(['movement_type' => TransferOut/In, 'reference_type' => StockTransfer::class, 'reference_id' => ...])` to retag the resulting movement.

**Attack:**
- What happens if a second concurrent transfer of the **same product at the same location** is initiated mid-transaction? Both transactions run `issue()` which creates a `stock_movement` with the **same `reference` string** (the other transfer's `transfer_number`)? Could the re-label pick up the wrong row?
- Is the source `stock_levels` lock the only thing serializing this? Are the re-label's `where` predicates strict enough that the worst case is "the wrong reference, same product, same location" being retagged? Walk this through.
- Even with serialization at the source row, could a concurrent **sale (issue)** on the same product at the same source location, inside the same transaction window, share the `reference` somehow? Spec the failure mode if so.
- Why not pass the desired `movement_type` / `reference_type` / `reference_id` into `StockAdjustmentService::issue/receive` directly so this re-label dance is unnecessary? Is that a worthwhile refactor for this PR, or a follow-up?

### 2. WAC `recordCostAdjustment` correctness

The math is `new_avg = current_avg + additional_cost / company_on_hand_qty`, where `company_on_hand_qty` = `SUM(stock_levels.quantity) WHERE product+tenant+company`, taken with `lockForUpdate()`.

**Attack:**
- The author calls `recordCostAdjustment` **inside** `complete()`, AFTER incrementing the destination `stock_levels`. So `company_on_hand_qty` at recompute time already includes the just-received quantity. Is the math correct relative to the spec wording "capitalize the extra cost into the company-wide WAC across all on-hand units"?
- Walk through two products with different WACs and a freight bill split pro-rata-by-value. Hand-verify the algebra.
- What about pro-rata-by-quantity when products have very different unit costs? Does the allocated cost lift WAC by sane amounts?
- What about `EqualPerLine` when one product on the transfer has a huge value share and another a tiny share? Is the chosen mode honored?
- `recordCostAdjustment` is called per line. If the same product appears on two lines of one transfer (the service rejects this via `collectProductIds`), good — but verify the rejection actually closes the loop (no path to call `recordCostAdjustment` twice for the same product within one `complete()`).
- The on-hand sum: is the `stock_levels` lock taken **before** the source decrement on `initiate`? Could two concurrent transfers + a complete observe an inconsistent on-hand snapshot?
- `WeightedAverageCostService::recordCostAdjustment` no-ops when `onHandFloat <= 0`. Is this a correctness bug or a documented choice? What if the transferred qty equals total on-hand (so after the source decrement, on-hand drops to zero during in_transit)? Is the WAC recompute deferred to the complete-time stock state, and is that observable correctly?
- Use of `(float) $product->cost_price` and `round($x, $scale)` in the WAC method — is precision loss material for currencies with 4-decimal cost scale? Compare to `bcmath` usage in surrounding code.

### 3. Idempotency

`initiate()` short-circuits on `idempotency_key`. `complete()` and `cancel()` do **not** accept an idempotency_key — they rely on the status transition itself (`canBeCompleted` / `canBeCancelled`) being the guard.

**Attack:**
- The spec asks for `complete` to be idempotent via `idempotency_key`. Is "status guard" actually equivalent under retry? Walk through: client calls complete, server commits, response is lost on the wire, client retries — what happens server-side? Does it fail with `TransferStateException`? Is that a regression compared to the spec, or acceptable?
- What if the controller request is replayed before the first server transaction commits (i.e. concurrent identical retries from a fragile network)? Does the `lockForUpdate()` on `lockTransfer` serialize correctly, or could both transactions believe they have the lock?

### 4. Tenant + company isolation

**Attack:**
- Every read in the controller is scoped to `tenant_id + company_id` from `CompanyContext::requireCompany()`. Verify by grep that nothing in `StockTransferService` queries a model without those predicates.
- `StoreStockTransferRequest` uses `ScopedExists::company('locations', ...)` and `ScopedExists::tenantAndCompany('products', ...)`. Does the service do its own re-verification, or rely on the request? Specifically check: if a caller bypasses the FormRequest (e.g. internal service call), does the service still catch a cross-company location/product?
- Look at `loadAndVerifyProducts` and `loadLocationOrFail` — are these airtight, or could a UUID that exists in another tenant slip through?

### 5. Permissions

`'inventory.transfers.view' | 'inventory.transfers.create' | 'inventory.transfers.complete' | 'inventory.transfers.cancel'`.

**Attack:**
- Are these added to **both** the `createPermissions()` array and granted in the appropriate admin roles in `RolesAndPermissionsSeeder.php`? Verify the existing admin pattern is matched.
- Frontend `usePermissions.ts` — are the new keys mapped to roles such that `RequirePermission` actually lets the intended users see the pages? Check the role names match the backend role names.
- Routes file: do the `can:inventory.transfers.*` middlewares cover every endpoint, including `show`?

### 6. Status transitions + cancel semantics

**Attack:**
- `cancel` from `draft` does nothing to stock — correct? But: does it leave dangling `stock_transfer_lines` with `unit_cost_snapshot = NULL` and `quantity > 0` that downstream reports might mistake for "still pending"? Should there be a UI/API "list active transfers" filter that excludes `cancelled`?
- `cancel` from `in_transit` re-uses `StockAdjustmentService::receive()` to return stock to source, then re-labels to `TransferIn` with `-CANCEL` suffix on `reference`. Is this audit trail confusing — a `TransferIn` at source with a `-CANCEL` reference reads weirdly compared to a `TransferOut` reversal? Is there a better movement type? Walk through what an accountant would see in `stock_movements`.

### 7. Per-line constraints

**Attack:**
- The migration has CHECK `quantity > 0` on `stock_transfer_lines` (PG-only). Does the service mirror this in `initiate()`? Yes, but what about the controller request? Yes, `min:0.0001`. But: is there any path where a 0-quantity line could be created (e.g. via the cancel rollback)?
- Unique `(transfer_id, product_id)` on `stock_transfer_lines` — does the service rely on this DB constraint or fail soft if violated?

### 8. Frontend

**Attack:**
- The list page calls `useStockTransferList` with filters that include pagination, but the UI does **not actually render pagination controls** — the `total > 0` footer just shows `{total} / {total}`. Is this a deferrable nit or a real UX gap?
- `CreateStockTransferPage` validates client-side and calls `mutateAsync`. Does the error-toast catch *all* server-side errors with useful messages, or does it always fall back to `t('create.error')` even when the server provided a structured `error.message`?
- `StockTransferDetailPage` has a custom inline cancel modal (because the shared `ConfirmDialog` does not accept children). This duplicates the modal styles. Is the right fix to extend `ConfirmDialog` to accept children, and is doing that out of scope here?
- Idempotency key on the frontend: the create form does not generate one. Should it, to make POST safe-to-retry on flaky networks?
- Translations: are EN and FR in sync (every key present in both)? Are any strings hardcoded?
- The list page table uses `tokens.card.hoverPrimary` for row hover — verify that's the intended look (it has a blue-on-blue tint vs the more usual gray-50 hover).

### 9. Spec drift

Compare what shipped vs `apps/erp/docs/superpowers/specs/2026-05-24-t1-stock-transfer.md`:
- Author claims out-of-scope: Scenario B, per-location tax_id, InTransitAvailability, batch preservation on transfer legs. Verify these are **structurally seamed** so the next PR can land them without re-doing this work. Specifically:
  - The `TransferType` enum already includes `Intercompany`; the service rejects it at the seam. Is that the right seam?
  - The `idempotency_key` column exists; is it used only for `initiate` today? Should it be propagated through `complete`/`cancel` for the follow-up PR?
  - The reviewer notes that the **next session** (the user just said this) will close Scenario A end-to-end (tax sub-IDs + InTransitAvailability + batch preservation). Surface any structural choice this PR made that would have to be **undone** by that work.

### 10. Migration placement

The migration is in `apps/api/database/migrations/`, NOT `apps/api/database/migrations/tenant/`. The T1 spec calls for tenant/. The memory says current row-level tenancy makes main folder correct. The design note documents the choice.

**Attack:** is leaving the migration in `migrations/` a correctness issue once T6 Phase 0b lands? Will the migration get applied twice (once in the central run, once in the tenant pass)?  Or is this safe and explicitly handled by the bootstrap?

---

## Workflow

1. Check out the worktree at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer` (`git status` should show `feat/inventory-transfer` at `189087122` — or whatever the latest is on `origin/feat/inventory-transfer`).
2. Read the files in the order above. Take notes as you go.
3. For each adversarial focus area, write down the failure scenario, run a thought experiment, and **confirm against the code**.
4. If you can run tests locally, run them: `cd apps/erp/apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php`. Optional but useful: try to write a new failing test for any concurrency claim you make.
5. Write the review to the file path above.
6. Reply to the dispatcher with **just** the file path and a one-line verdict — the body is on disk.

Be specific. Be ruthless. "I'm not sure" is OK; invented findings are not.
