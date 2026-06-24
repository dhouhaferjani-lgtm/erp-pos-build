# Adversarial Review: Stock Adjustment / Write-Off Plan v2

Reviewed plan: `docs/superpowers/plans/2026-06-24-stock-adjustment-writeoff-plan-v2.md`  
Superseded plan/audit reference: `docs/superpowers/plans/2026-06-23-stock-adjustment-writeoff-audit-and-plan.md`  
Prior review cross-checked but independently re-verified against the working tree, with media claims checked against `origin/dev` as requested.

## Verdict

**APPROVE-WITH-CHANGES** for the revised plan.

Confidence: **87%**.

Severity counts: **BLOCKER 0, HIGH 5, MED 4, LOW 2**.

**B0 alone is safe to execute now: YES**, with the narrow scope stated in v2. It fixes the actual double-decrement and should not wait for the larger reason/cost/GL work.

## B0 Safety Assessment

The proposed B0 fix is correct for the double-decrement. Today `BatchWriteOffService::writeOff()` calls `StockAdjustmentService::issue()` with `batchId` (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:63-71`), which creates the aggregate movement and then writes `inventory_batch_movements`/`inventory_batch_stock` via `StockAdjustmentService::recordBatchMovement()` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:208-216`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:825-857`). It then calls `BatchStockService::issueBatchStock()` for the same movement and quantity (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:73-80`), which locks the batch-stock row, checks `available_quantity`, writes a negative batch movement, and decrements batch stock (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:143-167`, `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:224-261`).

Calling `issue()` without `batchId` still creates the `stock_movements` row, updates aggregate `stock_levels`, and dispatches the after-commit events (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:173-206`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:219-257`). Passing that movement id into `issueBatchStock()` still creates the `inventory_batch_movements` link (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:161-167`, `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:238-242`).

The batch-availability guard remains sufficient for B0 because `BatchWriteOffService::writeOff()` wraps both calls in one transaction (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:59-109`). If `issueBatchStock()` throws `InsufficientBatchStockException`, the earlier aggregate decrement from `issue()` rolls back with the outer transaction. I found no write-off-specific invariant that requires `issue(batchId)` itself to create the batch link; `BatchWriteOffService` already passes the aggregate movement id into `issueBatchStock()`.

Caveat: B0 does not solve reason persistence, movement cost, GL posting, or reversal. That is acceptable for an isolated bug fix, but do not let B0's green tests imply Phase B/C readiness.

## Findings

### HIGH 1. A1/A1b/B2 still omit the needed `issue()` contract changes for write-off reason and cost.

The plan says A1 persists `?MovementReason` through `StockAdjustmentService::recordMovement()`/`adjust()`, and B2 stores `unit_cost`/`total_cost` on the write-off `issue()` movement. That is not achievable through the current public `issue()` signature, and v2 does not explicitly require changing it.

`BatchWriteOffService` maps the reason to `MovementReason` (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:52-57`) but calls `StockAdjustmentService::issue()` with no reason or cost arguments (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:63-71`). `issue()` accepts only product/location/quantity/reference/user/batch/company/variant (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:158-167`). The private `recordMovement()` likewise accepts no reason, unit cost, or total cost and writes none of those fields (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:762-794`).

This means A1 must cover `receive()` and `issue()` too, not only `adjust()`/`recordMovement()`, and B2 needs an explicit design for how write-off cost is injected into `issue()` without polluting unrelated issue callers. Otherwise BatchExpiry still cannot persist `Expiry`/`Damage`/`WriteOff` or original cost on the movement it returns.

### HIGH 2. `MovementReason::CountCorrection` is not behavior-neutral unless every enum helper and generated type is updated.

Adding a new enum case will immediately break the exhaustive `label()` match unless v2 requires a label branch (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:75-94`). The defaulted helpers are also semantically wrong for count corrections: `getMovementType()` defaults every unlisted case to `'out'` (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:31-40`), and `requiresGLEntry()` defaults every unlisted case to `false` while generic adjustment reasons require GL (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:57-72`).

The generated shared TypeScript union also lacks the new case (`packages/shared/types/generated.d.ts:696`). If regenerated types are part of the workflow, v2 should say so; if they are checked in manually, the plan must include that file. Otherwise frontend/API typing can reject or fail to display the new reason.

I did not find another exhaustive `match()` on `MovementReason` outside the enum itself, and current GL write-off code calls `$reason->label()` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1482-1488`), so the immediate hard break is the enum method.

### HIGH 3. B3's deterministic batch lock ordering is insufficient across the aggregate-stock and batch-stock lock domains.

The grouped write-off plan sorts only `inventory_batch_stock` row acquisition. Current write-off ordering is aggregate first, batch second: `StockAdjustmentService::issue()` locks `stock_levels` and updates aggregate stock (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:173-192`), then `BatchStockService::issueBatchStock()` locks the batch-stock row (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:143-148`).

For grouped multi-lot write-off, sorting batch ids is not enough if aggregate stock-level locks are still acquired per line in request order. Two requests over products/lots `[A, B]` and `[B, A]` can still deadlock through stock-level locks before they ever reach the sorted batch-lock phase. The plan needs one canonical order across both domains, for example sorted `(product_id, variant_id, location_id)` aggregate locks followed by sorted batch-stock locks, or a single orchestration method that acquires all locks consistently before mutation.

The same lock-domain split exists in GoodsReceipt and delivery-note flows: WAC/aggregate movement first, batch-stock update second (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:161-191`, `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:253-271`). B3 should be designed against that whole pattern, not just the new endpoint.

### HIGH 4. Phase G's boundary-cleanup caller list is inaccurate and misses a batch-only transfer path.

The v2 list says to migrate "all `issue(batchId)`/`receive(batchId)` callers (GoodsReceipt, transfers, FEFO, delivery notes, reservations, AccountingOpeningService)." That is not the actual shape of the code.

Direct `StockAdjustmentService::receive(... batchId)` / `issue(... batchId)` callers I found are BatchWriteOff and StockTransfer (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:63-71`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:227-236`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:323-332`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:440-449`). GoodsReceipt and delivery notes use WAC plus `BatchStockService`, not `StockAdjustmentService` batchId (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:161-191`, `apps/api/app/Modules/Document/Domain/Services/DeliveryNoteService.php:253-271`). FEFO consumes batch rows directly and links to a caller-created movement (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:124-162`, `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:194-210`). Reservations only carry batch ids into reservation records (`apps/api/app/Modules/Inventory/Application/Services/StockReservationService.php:474-488`). `AccountingOpeningService`'s `batchId` is an accounting opening event payload, not inventory batch stock (`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:316-322`).

More importantly, v2 does not call out `BatchController::transfer()`, which invokes `BatchStockService::transferBatchStock()` directly (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:311-331`). That service moves batch stock between locations without creating or linking aggregate `stock_movements` (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:178-216`). If Phase G is about making BatchExpiry the single owner of batch tables while preserving aggregate/batch ledger consistency, this path must be explicitly audited and either paired with aggregate transfer movements or restricted/retired.

### HIGH 5. The B2 Draft-vs-posted decision is riskier than v2 states because accounting reports exclude draft entries.

Current write-off GL entries are created as draft (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1482-1490`). Accounting reports aggregate posted entries only; for example Trial Balance filters `journal_entries.status = 'posted'` (`apps/api/app/Modules/Accounting/Application/Services/Reports/TrialBalanceService.php:150-188`).

Switching write-offs to posted is not just a local status flip. The canonical `postEntry()` path computes the GL fiscal hash and requires a user (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1091-1111`). Conversely, leaving write-off entries in Draft means inventory write-offs will not affect posted financial reports. B2 should explicitly choose one of these workflows and test the reporting consequence. "Recommend posting" is directionally reasonable, but unsafe unless the plan names the posting user/system actor, hash-chain behavior, and whether missing GL accounts should still be swallowed.

The swallow point matters: `BatchWriteOffService` catches `RuntimeException` from GL creation and still commits stock write-off (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:82-106`). If the new contract says write-off GL is mandatory or posted, that catch must be revisited.

### MED 1. A5 should be dropped unless a real "manual path" marker is added.

The column is nullable today (`apps/api/database/migrations/tenant/2025_12_24_133827_extend_stock_movements_table.php:13-17`) and historical rows can legitimately have NULL reason. The base `stock_movements` quantity fields are non-null (`apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:32-40`), but reason is not.

The proposed conditional guard "movement_type = adjustment AND created via manual path" is not expressible from current columns. Both manual adjust and WAC cost-adjustment rows use `MovementType::Adjustment`; WAC cost adjustments currently write reason NULL and `quantity_before == quantity_after` (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:737-755`). Counting also enters through the same `adjust()` service path and today only distinguishes itself by `reference = COUNTING:<number>` (`apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:43`, `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:84-92`).

V2 says to skip A5 if it cannot be scoped. Based on the current schema, it cannot be scoped cleanly. Make that a locked decision unless the implementation first adds a durable source/path discriminator.

### MED 2. C0's immutability whitelist is feasible, but only if `movement_type` is not broadly writable after creation.

The plan lists protected fields but also says to preserve `StockTransferService::markMovementAsTransfer()`. That method updates `movement_type`, `reference_type`, and `reference_id` after the movement is created (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:618-624`), after calls to `receive()`/`issue()` that initially create receipt/issue rows (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:227-238`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:440-451`).

Allowing arbitrary post-create `movement_type` mutation is dangerous because movement type is a reporting and direction field, even if it is not a numeric cost column. The safer design is to change the creation API to create transfer movements with the final type/reference in the first insert. If v2 keeps a guard whitelist, it should be very narrow: allow only the transfer service's expected transition from receipt/issue to transfer_in/transfer_out while `reference_type` and `reference_id` are empty, and block every other `movement_type` change.

I found no other legitimate `StockMovement` update path besides `markMovementAsTransfer()` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:620-624`), so this refactor is bounded.

### MED 3. C2 still needs an explicit original-GL lookup and double-reverse database guard.

V2 correctly mentions aggregate reversal, batch restore, reversing journal, tenant/company scope, and double-reverse prevention. It does not specify how to find and handle the original write-off journal entry. Today write-off GL uses `source_type = 'batch_write_off'` and `source_id = $movementId` (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1488-1490`), but it may be absent if the amount is non-positive (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1467-1470`) or if GL account creation throws and BatchWriteOffService logs-and-continues (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:99-106`).

Double-reverse prevention also should not rely only on application checks. `StockMovement` currently has no `reverses_movement_id` field or relation (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:56-77`), and v2 names only a self-FK. Add a unique partial index on `reverses_movement_id` where not null, or an equivalent DB constraint, so two concurrent reversals cannot both pass an application-level check.

### MED 4. The plan references an `AdjustStockRequest` file that does not exist in this branch.

V2 scopes A4 to `Inventory/Presentation/Requests/AdjustStockRequest.php`, but manual stock adjustment currently validates inline in `StockMovementController::adjust()` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:214-245`). I found no `ReceiveStockRequest`, `IssueStockRequest`, or `AdjustStockRequest` under `Inventory/Presentation/Requests`.

This is not a conceptual blocker, but it is execution friction: the task should either create the FormRequest and wire the controller to it, or say "move inline validation into a new `AdjustStockRequest`." Otherwise an implementer may patch a nonexistent path and miss the live endpoint.

### LOW 1. A3's direction helper is sound, but consumers must handle `'flat'`.

The proposed `directionForRow()` based on `bccomp(quantity_after, quantity_before)` is reliable across the movement-create paths I checked. The schema makes `quantity_before` and `quantity_after` non-null (`apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:32-40`), and the direct creation paths populate them: StockAdjustmentService (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:782-794`), WAC purchase/sale/return/cost adjustment (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:254-274`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:405-422`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:550-567`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:737-755`), POS sale/return/void (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:955-973`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1168-1184`, `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:181-198`), and opening balances (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:279-292`).

The only important nuance is WAC cost adjustment: it intentionally creates an adjustment with `quantity = 0` and before/after equal (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:736-755`). That should be classified as `'flat'`, and every consumer updated from `MovementType::isInbound()` must explicitly handle that third state.

### LOW 2. Media is correctly deferred; keep it that way.

I verified the media facts against `origin/dev`: `MediaOwnerType` is currently Product/ProductVariant/Category only (`origin/dev:apps/api/app/Modules/Catalog/Domain/Enums/MediaOwnerType.php:7-11`), `media_attachments` is a generic owner-type/owner-id link (`origin/dev:apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:14-30`), and `MediaUploadService::uploadForProduct()` remains product-image-specific (`origin/dev:apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php:80-98`). V2 defers justification documents, which is the right outcome. No additional stock-adjustment work should be attached to Phase F until media genericization lands.

## Direct Answers To The Requested Checks

1. **B0 double-decrement fix:** Correct and safe independently. One aggregate movement remains; one batch decrement/link remains; nested transaction rollback covers batch guard failure. It does not solve reason/cost/GL.
2. **A3 direction helper:** Reliable on current movement rows. All checked creation paths populate before/after. Ensure `'flat'` is handled for WAC cost adjustments.
3. **A1/A1b/A5:** Optional reason is backward-compatible, but v2 must include `issue()`/`receive()` reason plumbing. A5 cannot be expressed today without a new source/path marker; drop it for now.
4. **A2/4b CountCorrection:** New enum case is acceptable only if `label()`, `getMovementType()`, `requiresGLEntry()`/report semantics, tests, and generated TS types are updated. Otherwise it breaks.
5. **C0 immutability:** Feasible only with a very tight transfer exception or by creating transfer movements with final metadata at insert time.
6. **C2 reverse:** Better than v1 but still needs original-GL absent/draft/posted handling and DB-level double-reverse prevention.
7. **B2 cost/GL:** Not currently achievable through `issue()` without a signature/contract change. Draft-vs-posted is not safe to leave vague because posted-only reports exclude drafts.
8. **B3 grouped write-off:** Idempotency is directionally right; lock ordering is incomplete across stock-level and batch-stock domains.
9. **Phase G:** Caller list is inaccurate. Include `BatchController::transfer()`/`BatchStockService::transferBatchStock()`; remove `AccountingOpeningService`; distinguish direct `issue/receive(batchId)` callers from batch-service and FEFO callers.
10. **New v2 problems:** The main new risks are the `CountCorrection` enum break, the missing `issue()` reason/cost contract, and overconfidence in batch-only lock ordering.
