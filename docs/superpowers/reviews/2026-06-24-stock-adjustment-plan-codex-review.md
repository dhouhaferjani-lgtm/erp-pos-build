# Adversarial Review: Stock Adjustment / Write-Off Audit + Plan

Reviewed plan: `docs/superpowers/plans/2026-06-23-stock-adjustment-writeoff-audit-and-plan.md`  
Source spec: `docs/stock-adjustment-writeoff-spec.md`  
Media caveat honored: media facts below were checked against `origin/dev`, not the stale working tree.

## Verdict

**REJECT** as an implementation plan to execute as-is.

Confidence: **88%**.

Severity counts: **BLOCKER 2, HIGH 7, MED 6, LOW 2**.

The high-level reframe is mostly right: the current inventory system is state-first, `stock_levels.quantity` is directly mutated, stock-movement events are after-commit advisory, and there is no stock-movement hash chain. But the plan misses or understates enough correctness risks that executing it phase-by-phase would likely ship bad stock and accounting behavior.

## Findings

### BLOCKER 1. Batch write-off is not "mostly built": it double-decrements lot stock.

The plan treats `BatchWriteOffService` as an existing sound backend path that mainly needs reason persistence and UI. It is not sound as written. `BatchWriteOffService::writeOff()` calls `StockAdjustmentService::issue()` with `batchId` (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:63-71`), and `StockAdjustmentService::issue()` records a negative batch movement when `batchId` is provided (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:208-216`) through `recordBatchMovement()`, which also updates `inventory_batch_stock.quantity` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:825-857`). Then `BatchWriteOffService` calls `BatchStockService::issueBatchStock()` for the same movement and quantity (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:73-80`), and that service also records a negative batch movement and updates the batch stock row (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:136-167`, `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:224-261`).

This is not theoretical. The existing test fixture explicitly seeds double the needed batch stock because the write-off path deducts twice (`apps/api/tests/Feature/BatchExpiry/BatchWriteOffScalingTest.php:123-126`, `apps/api/tests/Feature/BatchExpiry/BatchWriteOffScalingTest.php:148-155`). Phase B must start by fixing this, before any UI or grouped multi-lot API. The plan's §1.4c and §6-A overstate the backend maturity.

### BLOCKER 2. Reversal without a status lifecycle is not enough, and the plan omits cost, GL, batch, and scope reversal semantics.

Adding only `reverses_movement_id` does not enforce immutability. `StockMovement` currently has fillable mutable fields and no `status`/`reverses_movement_id`/guard state (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:56-77`). The repository already mutates stock movements after creation: `StockTransferService::markMovementAsTransfer()` updates `movement_type`, `reference_type`, and `reference_id` after `StockAdjustmentService::issue()` creates the row (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:440-451`, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:618-624`). A generic "posted movement is immutable" rule would break this existing path unless creation APIs stop needing post-create mutation first.

The reverse flow also needs an accounting and costing design. WAC movement rows carry cost fields in `WeightedAverageCostService` (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:405-422`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:737-755`), while the `StockAdjustmentService` issue/adjust path creates movements with no unit/total cost (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:782-794`). Batch write-off separately creates a GL entry (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1459-1513`). A reversal must define whether it reverses at original unit cost, current WAC, or explicit stored movement cost; whether it creates a reversing journal entry for `source_id = original movement`; how it restores `inventory_batch_stock` and `inventory_batch_movements`; and how tenant/company scope is enforced. The plan's Phase C is underspecified for a posted financial inventory operation.

### HIGH 1. The audit wrongly says movement quantity is always positive magnitude with direction from movement type.

That claim is false across current code paths. `StockAdjustmentService::issue()` stores positive `quantity` for an issue movement (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:194-205`). `WeightedAverageCostService::recordSale()` stores a negative quantity for an issue movement (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:405-416`). POS sale stock movement creation stores an `Issue` with a positive quantity (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:955-964`). Batch movements are signed negative for issues (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:208-216`, `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:161-167`).

Any reversal, report, `isInbound()` fix, or reason-direction validation that assumes "positive magnitude plus type" will be wrong.

### HIGH 2. Phase A3 cannot be implemented as described by changing only `MovementType::isInbound()`.

`MovementType::isInbound()` has no quantity argument, and currently classifies every `Adjustment` as inbound (`apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php:16-19`). `isOutbound()` excludes `Adjustment` entirely (`apps/api/app/Modules/Inventory/Domain/Enums/MovementType.php:21-24`). A negative adjustment is only knowable from the movement row quantity or before/after delta (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:589-604`), not from the enum value. Fixing the enum method alone either cannot work or would make all adjustments neither inbound nor outbound, which may silently change report behavior. Add a row-level direction helper on `StockMovement` or a function that accepts `MovementType + quantity`.

### HIGH 3. Reason persistence on the shared service path is additive only if DB constraints are deferred; the plan blurs that.

The existing `reason` column is nullable (`apps/api/database/migrations/tenant/2025_12_24_133827_extend_stock_movements_table.php:13-17`), and `StockMovement` already casts it to `MovementReason|null` (`apps/api/app/Modules/Inventory/Domain/StockMovement.php:82-87`). Persisting an optional reason through `StockAdjustmentService::recordMovement()` is backwards-compatible. Making reason `NOT NULL` or adding a broad CHECK is not.

There are many current direct `StockMovement::create()` paths that omit `reason`: WAC purchase/return/sale/cost adjustment (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:254-274`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:550-567`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:737-755`), inventory opening (`apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:279-292`), and the shared service itself (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:782-794`). POS does set reasons on some projections (`apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:955-964`, `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1168-1177`). The migration plan must be staged: add optional reason support, backfill/cover all create sites that should have reasons, then add any conditional DB constraint only for a precisely defined movement subset.

### HIGH 4. The "counting reason discriminator" decision lacks a correct enum value.

The plan correctly observes there is no `COUNT_CORRECTION` enum. But §6-B locks "set a typed `MovementReason` when counting posts" without specifying a semantically safe value. The counting listener computes a signed delta (`apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:63-82`) and calls `adjust()` with `reference = COUNTING:<number>` (`apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:43`, `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:84-92`). Existing `MovementReason` has only generic `AdjustmentPositive` and `AdjustmentNegative`, not count correction (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:12`, `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:20`).

If counting uses `AdjustmentPositive/Negative`, reports cannot distinguish manual adjustments from count corrections except by the free-text `COUNTING:` prefix, which weakens the whole "reason as discriminator" argument. If the plan wants a typed discriminator, adding a count-specific reason may be cleaner than relying on a reference prefix while saying not to invent one.

### HIGH 5. §6-A's dependency-direction rationale ignores that Inventory already imports BatchExpiry domain classes.

The plan says keeping `Inventory::adjust()` lot-agnostic avoids coupling core Inventory to optional BatchExpiry. But `StockAdjustmentService` already imports `BatchMovement` and `BatchStock` from `BatchExpiry` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:7-8`) and mutates batch stock internally when `receive()`/`issue()` get a `batchId` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:93-101`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:208-216`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:825-857`).

Routing lot write-offs through `BatchWriteOffService` is directionally right, but the plan should explicitly include a boundary cleanup: either move batch stock mutation fully into BatchExpiry/Application orchestration, or introduce an Inventory-facing port owned by Inventory that BatchExpiry implements. Otherwise §6-A claims hexagonal soundness that the code does not currently have.

### HIGH 6. WAC/COGS and GL posting are underplanned.

`BatchWriteOffService` computes write-off value from `$product->weighted_average_cost ?? $product->cost_price`, but the test comments state `weighted_average_cost` is not a DB column and `cost_price` is the fallback (`apps/api/tests/Feature/BatchExpiry/BatchWriteOffScalingTest.php:110-112`). The stock movement created by `StockAdjustmentService::issue()` carries no cost fields (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:782-794`), so a later reversal cannot recover the original write-off cost from the movement itself. The GL entry created for write-off is created as `JournalEntryStatus::Draft`, not posted (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1482-1490`), despite the plan's acceptance wording saying GL/COGS "post" as today.

Before Phase C and grouped write-offs, the plan needs a cost contract: store movement unit/total cost at write-off time, define journal status/posting behavior, define COGS/write-off expense account selection, and define reversal accounting.

### HIGH 7. Multi-lot grouped write-off needs idempotency and locking design before UI.

Current write-off is a one-batch endpoint (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22-25`) that runs a nested service transaction (`apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:59-109`). It has no group id, no idempotency key, and no deterministic lock ordering across multiple batch rows. `BatchStockService::issueBatchStock()` locks one batch stock row at a time (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:143-148`), while aggregate stock is locked separately in `StockAdjustmentService` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:173-192`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:697-727`).

A multi-lot grouped write-off should not be bolted onto this by looping the one-lot method. It needs an idempotency key, group/reference model, sorted lock acquisition, all-or-nothing behavior, and tests for concurrent requests over overlapping lots/products.

### MED 1. Absolute vs delta is acceptable only with explicit API semantics; the plan does not define them tightly enough.

The existing manual adjust endpoint takes absolute `new_quantity` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:219-245`), and `StockAdjustmentService::adjust()` computes a delta internally (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:571-604`). Write-offs take positive quantity-out deltas (`apps/api/app/Modules/BatchExpiry/Presentation/Requests/WriteOffBatchRequest.php:31-37`, `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:44-51`). §6-F is a reasonable split, but the UI/API contracts need to make it impossible to confuse "set new physical count" with "remove this quantity." This is especially important for reversal/correction screens.

### MED 2. Expired lot query can show reserved stock as writable stock.

`getExpiringProducts()` filters `available_quantity > 0` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:267-268`), but `getExpiredBatchesWithStock()` filters raw `quantity > 0` (`apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:284-294`). Write-off validation uses available quantity in `BatchStockService::issueBatchStock()` (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:150-158`). If the new UI lists expired lots from `quantity > 0`, users can select reserved stock and hit a late 422. The plan should require `available_quantity > 0` and display both on-hand and reserved.

### MED 3. Permissions are partly existing and partly missing; the plan proposes the wrong names without reconciling current routes.

The plan proposes `inventory.writeoff`, but existing permissions include `batches.write-off` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:270-277`). `WriteOffBatchRequest::authorize()` already checks `batches.write-off` (`apps/api/app/Modules/BatchExpiry/Presentation/Requests/WriteOffBatchRequest.php:19-22`). However, the BatchExpiry route group has only auth/tenant middleware and no `module:BatchExpiry` or per-route `can:*` middleware (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:11-25`), unlike Inventory routes (`apps/api/app/Modules/Inventory/Presentation/routes.php:25`, `apps/api/app/Modules/Inventory/Presentation/routes.php:60-100`).

The plan should decide whether write-off remains a batch permission, an inventory permission, or both, and should add route-level middleware/module gating consistently.

### MED 4. Frontend scope is under-specified and current batch API has no write-off client.

The frontend currently exposes batch list/detail/create/edit routes under Inventory module gating (`apps/web/src/routes/index.tsx:911-949`). The batch API wrapper includes list/detail/create/update/delete/recall/expiring/stock/FEFO, but no write-off call (`apps/web/src/features/batches/api/batches.ts:60-134`). The plan says "reuse design tokens; `t()` for all copy," which is directionally correct but too thin for a pharmacy-critical workflow. It needs explicit i18n namespaces/keys, permission guards matching the backend decision, no raw text fallbacks for new UI, scale-4 quantity input behavior, and an e2e path for selecting lots and confirming write-off.

### MED 5. Media facts are mostly corrected, but "add `MediaOwnerType::StockMovement`" is not a complete cheap path.

On `origin/dev`, `MediaOwnerType` is limited to Product/ProductVariant/Category (`apps/api/app/Modules/Catalog/Domain/Enums/MediaOwnerType.php:7-11` via `git show origin/dev`). The link table is generic owner-type/owner-id (`apps/api/database/migrations/tenant/2026_06_12_100003_create_media_attachments_table.php:14-30` via `git show origin/dev`), and `MediaAttachment` casts `owner_type` to that enum (`apps/api/app/Modules/Catalog/Domain/Media/MediaAttachment.php:35-40` via `git show origin/dev`). So adding an enum case is structurally plausible.

But upload is still product-image-specific: `MediaUploadService::uploadForProduct()` stores under `products/{tenant}/{product}/...` and only allows images (`apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php:45-50`, `apps/api/app/Modules/Catalog/Application/Services/MediaUploadService.php:80-98` via `git show origin/dev`). Justification documents need PDFs/images, generic owner storage paths, and non-Catalog ownership. Deferring justification docs and recommending a separate media-unification session is right; presenting the future as only "add one enum case + rows" is too cheap.

### MED 6. Precision contract is real, but the plan does not say how to avoid more hardcoded scale drift.

Inventory quantities are scale 4 in models (`apps/api/app/Modules/Inventory/Domain/StockLevel.php:57-64`, `apps/api/app/Modules/Inventory/Domain/StockMovement.php:87-89`) and `StockAdjustmentService` hardcodes `SCALE = 4` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:28-37`). `QuantityScale` exists as a bcmath helper (`apps/api/app/Shared/Domain/QuantityScale.php:14-56`) and is used in some query/UOM paths, while BatchExpiry and counting still have literal `4`s (`apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php:152-165`, `apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:63-82`). The plan should make "canonical quantity scale 4" executable: one helper/constant, validation regex on every write endpoint, and tests for 4dp preservation through stock, batch stock, GL amount calculation, and UI.

### LOW 1. The state-first reframe is correct, and I found no lingering event-first implementation task in the adapted phases.

`StockAdjustmentService` mutates `stock_levels.quantity` directly inside transactions (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:71-91`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:173-206`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:587-607`) and dispatches stock events with `DB::afterCommit()` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:110-143`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:225-257`, `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:614-645`). The only hash chain I found in the audited inventory area is `InventoryCountingEvent` (`apps/api/app/Modules/Inventory/Domain/InventoryCountingEvent.php:57-80`). The plan's foundational correction is accurate.

### LOW 2. Test strategy needs to be promoted from examples to phase gates.

The plan lists some useful unit/request tests, but missing phase gates are significant: regression test proving write-off no longer double-decrements lot stock; reason persistence coverage for every stock movement creation path that will be constrained; reversal tests for aggregate stock, batch stock, movement linkage, WAC/cost fields, and GL reversal; concurrency/idempotency tests for grouped write-off; tenant/company isolation tests for cross-company batch/location/product IDs; and frontend tests for i18n, permissions, scale-4 quantity entry, and lot selection.

## Suggested Sequencing Changes

1. Split Phase B before UI:
   - B0: Fix one-lot batch write-off double-decrement and reason persistence.
   - B1: Add cost/GL posting contract for write-off movement and journal status.
   - B2: Expose expired/expiring lot queries with `available_quantity` semantics.
   - B3: Only then build UI for one-lot or grouped write-off.

2. Move reversal after cost/GL/batch semantics:
   - Add `reverses_movement_id` only after the reverse operation has explicit stock, batch, WAC, and GL behavior.
   - If no status lifecycle is added, add model/database immutability guardrails for protected fields after creation, while preserving legitimate creation-time updates such as stock transfer references by changing those APIs first.

3. Rework reason hardening:
   - First persist optional reasons through `StockAdjustmentService`.
   - Add reason coverage to direct create sites or deliberately exclude them.
   - Decide whether counting gets a new count-specific reason; do not rely on `COUNTING:` forever if reason is supposed to be the discriminator.
   - Add DB constraints only after backfill.

4. Treat media as deferred:
   - Do not implement attachments in this feature.
   - When resumed, create/genericize a media module/upload path for non-product owners and PDFs/images rather than importing Catalog services directly from Inventory.

## Final Assessment

The plan is correct to reject event-first/hash-chain assumptions and to reuse the existing inventory posting path. It is not safe to execute because it misses an existing double-decrement bug in the write-off path, under-specifies reversal/cost/accounting behavior, overstates movement quantity semantics, and does not stage reason constraints across all movement writers.
