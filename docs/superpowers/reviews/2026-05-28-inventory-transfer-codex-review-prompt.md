# Adversarial Review — Inventory Transfer (PR #147)

You are Codex acting as an adversarial reviewer. The author thinks this PR is ready to merge to `dev`. Your job is to find what they got wrong and write the review to disk.

**Save the review to this exact path** (do not return it inline): `apps/erp/docs/superpowers/reviews/2026-05-28-inventory-transfer-codex-review.md`.

Use this structure:

```
# Inventory Transfer (PR #147) — Codex Adversarial Review

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

For every finding, cite the exact `path:line` so the author can navigate to it. Walk every concurrency claim through an explicit two-process scenario. Do not invent findings — confirm against the code first.

## Repo + branch

- Worktree root: `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`
- Branch: `feat/inventory-transfer` (vs `origin/dev`)
- PR: https://github.com/otospexsolutions/erp/pull/147
- Latest commit on branch should be near `189087122`.

## Scope on the table

Author shipped T1 Phase 2 intracompany scope and **explicitly defers**:
- Scenario B (intercompany sales/purchase auto-doc)
- Per-location tax_id / branch_code / legal_name
- InTransitAvailability per-company setting
- Batch preservation via `inventory_batch_movements` on transfer legs
- Variant-aware scoping (T2 dependency)

A follow-up session will close Scenario A end-to-end (tax sub-IDs + InTransitAvailability + batch preservation). Flag any structural choice this PR made that would have to be UNDONE by that next session.

## Required reading (in this order)

1. `apps/erp/docs/superpowers/specs/2026-05-24-t1-stock-transfer.md` (the spec)
2. `apps/erp/docs/superpowers/coordination/2026-05-28-inventory-transfer.md` (the author's design note)
3. `apps/erp/apps/api/database/migrations/2026_05_28_120000_create_stock_transfers_table.php`
4. `apps/erp/apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php`
5. `apps/erp/apps/api/app/Modules/Inventory/Domain/Enums/TransferType.php`
6. `apps/erp/apps/api/app/Modules/Inventory/Domain/Enums/TransferCostDistribution.php`
7. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockTransfer.php`
8. `apps/erp/apps/api/app/Modules/Inventory/Domain/StockTransferLine.php`
9. `apps/erp/apps/api/app/Modules/Inventory/Domain/Events/StockTransferInitiated.php`, `StockTransferCompleted.php`, `StockTransferCancelled.php`
10. `apps/erp/apps/api/app/Modules/Inventory/Domain/Exceptions/TransferStateException.php`
11. `apps/erp/apps/api/app/Modules/Inventory/Application/DTOs/InitiateTransferData.php`, `InitiateTransferLineData.php`
12. `apps/erp/apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` ← **the heart of this PR**
13. `apps/erp/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` (only the new `recordCostAdjustment` method)
14. `apps/erp/apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php`
15. `apps/erp/apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
16. `apps/erp/apps/api/app/Modules/Inventory/Presentation/routes.php` (the new `/stock-transfers` block)
17. `apps/erp/apps/api/database/seeders/RolesAndPermissionsSeeder.php` (only the new `inventory.transfers.*` lines)
18. `apps/erp/apps/api/tests/Feature/Inventory/InventoryTransferServiceTest.php`
19. Frontend feature dir: `apps/erp/apps/web/src/features/stock-transfers/**`
20. Frontend wiring: `apps/erp/apps/web/src/lib/i18n.ts`, `apps/erp/apps/web/src/hooks/usePermissions.ts`, `apps/erp/apps/web/src/routes/index.tsx` (diffs only)
21. Existing primitive — `apps/erp/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (specifically `issue`, `receive`, `transfer`) — needed to evaluate the re-label pattern in the new service

## Attack surface (cover every numbered item)

### A. Re-label-most-recent `stock_movement` pattern

In `StockTransferService` the service calls `StockAdjustmentService::issue/receive(reference: $transfer->transfer_number, ...)` and immediately runs

```
StockMovement::query()
  ->where('reference', $reference)
  ->where('product_id', ...)
  ->where('location_id', ...)
  ->where('movement_type', MovementType::Issue)  // or Receipt
  ->whereNull('reference_id')
  ->orderByDesc('created_at')
  ->limit(1)
  ->update([...])
```

Walk through:

1. Two concurrent `initiate()` calls for the **same source location** — does the source-row `lockForUpdate()` actually serialize them strongly enough that the re-label can never grab the wrong row?
2. The `reference` is the transfer's `transfer_number`, which is unique per company. Could a different (non-transfer) caller of `StockAdjustmentService::issue()` ever choose the same string by accident? (Hint: human-entered `reference` field on `StoreStockMovementRequest` — could an admin manually issue with `reference="TR-2026-00007"` and collide?)
3. What if the `StockAdjustmentService::issue()` call commits successfully but the subsequent `->update()` to re-label silently affects 0 rows (e.g. `created_at` tie-breaker selected a different row)? The transaction completes "successfully" but audit trail is wrong. Is there an assertion that exactly 1 row was retagged?
4. Why not pass the desired movement_type + reference_type + reference_id into `StockAdjustmentService::issue/receive` directly? Quantify the refactor cost.

### B. Concurrent transfers of the same product at different locations

Two transfers initiate simultaneously for product X — Transfer A: warehouse → shop1, Transfer B: warehouse → shop2. Both lock the warehouse `stock_levels` row. Is the WAC recompute on later `complete()` calls deterministic regardless of order? Walk through values.

### C. The `complete()` WAC recompute

- The recompute fires inside `complete()` AFTER incrementing destination stock. Verify the `company_on_hand_qty` used in `recordCostAdjustment` is taken with `lockForUpdate()` and reflects the just-incremented destination.
- Hand-compute the WAC for: (a) 1 product, transfer_cost=100, source 50 units at cost 5, dest 50 units at cost 5, transfer 10 units. Show expected final WAC. Then walk through the code path and confirm the code produces the same answer.
- Now do the same for (b) 2 products on one transfer: product A 10 units snapshot $5; product B 5 units snapshot $10; transfer_cost=$30, distribution=ProRataValue. Expected allocated_to_A and allocated_to_B?
- Now do the same with `ProRataQuantity` and `EqualPerLine`.
- What happens with `ProRataValue` when `unit_cost_snapshot=0`? Code falls back to equal-per-line. Does the spec endorse this fallback?

### D. Idempotency

- `initiate` has an `idempotency_key` short-circuit; `complete` and `cancel` do not. The spec's acceptance criteria say `complete` must be retry-safe. Is the status-transition guard equivalent under retry, or is there a hole?
- Two clients with the same `idempotency_key` POSTing in parallel — what wins? Is the `stock_transfers_idempotency_unique` index actually `(tenant_id, company_id, idempotency_key)`? If both rows pass the short-circuit check before either inserts, what happens?

### E. Tenant + company scoping

Grep every `find`/`where`/`findOrFail` in the new code and confirm it is scoped to both `tenant_id` and `company_id` (or that the scoping is provably inherited from a parent query). Track `loadLocationOrFail`, `loadAndVerifyProducts`, `lockTransfer`. Compare to the cluster of findings in `apps/erp/apps/api/tests/Feature/Inventory/InventoryTenantIsolationTest.php` — the same callsite classes (StockAdjustmentService, WeightedAverageCostService) had unscoped lookups that the api.inventory.011-019 sweep had to repair. Does the new service repeat any of those?

### F. Permissions wiring

- `RolesAndPermissionsSeeder.php` — were the four new `inventory.transfers.*` permissions added to both the declaration list AND granted to the admin role(s) that need them?
- `usePermissions.ts` — do the role mappings match the backend roles, so `RequirePermission` does not silently deny a user with backend permission?

### G. Migration safety

- `2026_05_28_120000_create_stock_transfers_table.php` adds CHECK constraints only when `DB::getDriverName() === 'pgsql'`. SQLite test runs skip them. Verify no test relies on the CHECK being enforced.
- Migration sits in `database/migrations/` not `database/migrations/tenant/`. T6 Phase 0b will move tables to per-tenant DBs. Is this a problem now? Does the `bootstrap` / tenancy config ever apply migrations from `tenant/` AND `migrations/` to the same DB, leading to duplicate runs after the flip?

### H. Status / lifecycle

- `TransferStatus::canBe*` predicates — read each, confirm they match the spec's draft → in_transit → completed | cancelled flow.
- `cancel` from `draft` does no stock motion. `cancel` from `in_transit` re-uses `receive()` to put stock back at source. Is the audit trail readable? Could the `-CANCEL` suffix on `reference` cause downstream consumers (reports, exports) that filter by `reference LIKE 'TR-%'` to mistake a cancellation for a new transfer?

### I. Frontend

- `CreateStockTransferPage.tsx` — does it generate an `idempotency_key` to make POST retry-safe? If not, flag it.
- Error handling on the mutation — does it surface `error.details.product_id` / `error.details.requested` / `error.details.available` from `INSUFFICIENT_STOCK` so the user can correct, or is the user told only "could not create"?
- `StockTransferDetailPage.tsx` — the inline cancel modal duplicates the shared `ConfirmDialog`. Is this acceptable, or should `ConfirmDialog` be extended?
- `stockTransferApi.ts` — the list endpoint uses `api.get` directly (preserves `meta`); the rest use `apiGet`/`apiPost`. Verify the unwrap semantics. (Memory: `apiGet`/`apiPost` already unwrap `response.data.data`.)
- i18n: every key in `en/stock-transfers.json` should also exist in `fr/stock-transfers.json`. Verify by diffing keys.
- Routes registered behind `RequirePermission` — match the backend `can:` middleware on routes.

### J. Tests

- 12 tests cover the main scenarios. Are there missing scenarios?
  - Two simultaneous transfers of the same product (cannot test in PHPUnit without DB-level locks; flag as "best-effort coverage in unit tests is impossible — manual or integration testing only").
  - Cost allocation for `ProRataQuantity` and `EqualPerLine` modes — the existing test only exercises `ProRataValue` (default).
  - Cancel from `draft` does not touch stock — covered.
  - Cancel from `cancelled` rejects — covered? (check)
  - `idempotency_key` reused with different payload — does the service reject the second call or silently return the first transfer ignoring the new payload? Is this safe?

### K. Spec drift / next-session blockers

The follow-up session will add:
- Per-location tax sub-IDs
- `InTransitAvailability` per-company setting
- Batch preservation on transfer legs (`inventory_batch_movements` for both TransferOut + TransferIn movements)
- Variation scoping (likely T2-owned)

For each of those, identify whether anything in this PR's schema, enums, service contract, or HTTP surface would have to be **changed** (not just extended) to land that work. Specifically:
- The `unit_cost_snapshot` column on `stock_transfer_lines` — does it accommodate batch-level cost snapshots, or will the follow-up have to add a `batch_id` column to the lines table?
- The service flow re-calls `StockAdjustmentService::issue/receive` — but the existing `StockAdjustmentService::transfer` already has `recordBatchMovement` plumbing. Did the new service deliberately skip that path, or did it duplicate? If duplicated, is the batch-on-transfer-legs follow-up going to fight this code?

## Workflow

1. Open the worktree at `/Users/houssamr/Projects/syneriva/apps/erp.inventory-transfer`.
2. `git fetch && git log --oneline origin/dev..origin/feat/inventory-transfer` to confirm what's on the branch.
3. Read the spec + design note first, then the code in the order above.
4. For each lettered focus area, write down your finding(s) with citations.
5. Where you can, run `cd apps/erp/apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/InventoryTransferServiceTest.php` to corroborate behavior.
6. Save the review to the path above.
7. Reply with the file path and the one-line verdict.
