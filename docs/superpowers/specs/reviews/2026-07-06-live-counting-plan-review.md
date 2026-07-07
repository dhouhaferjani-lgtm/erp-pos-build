# Verdict: do not run overnight unattended

The plan is directionally aligned with the approved spec, but it is not safe as an autonomous overnight execution artifact. It marks colliding tasks as parallel, assigns columns to earlier tasks that do not actually produce them, references a nonexistent mobile batch count endpoint, under-scopes `occurred_at` writer coverage, and leaves several replay/onboarding seams ambiguous enough for isolated subagents to implement incompatible behavior.

## 1. Wave / Task Dependency Correctness

### BLOCKER: Wave A is explicitly not file-disjoint

The plan says A1-A4 are "file-disjoint; safe to implement in parallel", but A1 and A4 both edit the same hot POS service method area. A1 changes `ReceiptCreationService::decrementStock()` to accept/stamp `occurred_at`; A4 changes the same service to resolve stock policy per location. The actual method signature and policy branch live in one method: [ReceiptCreationService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:883) accepts `PosStockPolicy $policy`, checks it at [ReceiptCreationService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:915), and creates the movement at [ReceiptCreationService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:955).

Failure scenario: A1 adds `?CarbonInterface $occurredAt` to `decrementStock()` and movement creation while A4 simultaneously changes policy resolution/call sites. The later merge wins or conflicts, and the overnight run can leave either per-location policy or event-time stamping dropped from the retired-but-still-present sync path.

### BLOCKER: C2/C3 require A3-owned columns that A3 never produces

The plan's C2 says A3 must include `inventory_countings.late_sales_flags`; C3 says A3 must include `inventory_countings.includes_zero_stock`. But A3's own "Produces" list only adds `block_sales` and `ambiguity_window_minutes` to `inventory_countings`, plus item-level replay/timestamp columns. The current model fillable list has no live-counting fields at all after `cancellation_reason` [InventoryCounting.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCounting.php:60), and the current create-table migration only defines the existing scope/status constraints [2025_12_02_070000_create_inventory_countings_table.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php:102).

Failure scenario: A3 completes and commits exactly as written. C2's late-sale flag write or C3's onboarding auto-exit check then targets columns that do not exist, producing SQL errors or silently forcing C2/C3 agents to edit a committed A3 migration after the fact.

### MAJOR: Wave D is not parallel-safe

D1 and D4 are both declared parallel after C, but both modify [LocationsPage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/settings/LocationsPage.tsx:1). The same file owns `LocationFormData` [LocationsPage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/settings/LocationsPage.tsx:36), the card actions [LocationsPage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/settings/LocationsPage.tsx:312), and the location list rendering [LocationsPage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/settings/LocationsPage.tsx:264).

Failure scenario: D1 adds a Zones drawer/section while D4 adds onboarding/policy controls to the same form and cards. Autonomous parallel agents will produce overlapping imports, form state, payload mapping, and tests in one file, then one branch of UI state can be lost during conflict resolution.

### MAJOR: B5's overlap guard ignores variant-grain stock lines

B1/B3 correctly include `?string $variantId`, and actual counting items already persist `variant_id` [InventoryCountingItem.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:54). Item generation propagates `variant_id` from stock levels [InventoryCountingService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:92). Stock levels are uniquely scoped as either `(tenant, product, location)` for null variants or `(tenant, product, variant, location)` for variants [2026_06_02_100005_add_variant_id_to_stock_levels.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_02_100005_add_variant_id_to_stock_levels.php:52).

Failure scenario: B5 tells the agent to intersect only `(product_id, location_id)`. Two counts for distinct variants of the same product/location will be falsely blocked, while any downstream replay/finalize code is variant-aware. That is a contract mismatch between B1/B3 and B5.

## 2. Interface Consistency

### BLOCKER: B2 references mobile batch count endpoints that do not exist in this API

The plan tells B2 to modify "mobile batch-count endpoints' requests" and points to batch routes. The actual batch routes are draft create/update and batch add-products only [routes.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/routes.php:181). The only submit-count route is the single-item route [routes.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/routes.php:233). `batchAddProducts()` validates only product identity fields [InventoryCountingController.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:829), while `CountingItemController::submitCount()` passes only quantity/notes/user to the service [CountingItemController.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:98).

Failure scenario: A B2 agent either cannot find the endpoint and skips it, or mistakenly adds timestamp fields to draft/add-products sync. Offline count submissions still arrive through the single submit route without `counted_at_device`/`device_now`, so B3/B4 replay uses server receipt time and loses the normative count instant.

### MAJOR: B2 does not list the controller that must pass the new timestamp fields

`InventoryCountingService::submitCount()` currently accepts `(item, countNumber, quantity, notes, user)` [InventoryCountingService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:302), and `InventoryCountingItem::submitCount()` stamps `now()` [InventoryCountingItem.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:213). B2 lists the request, service, item stamping, and imaginary batch endpoints, but not [CountingItemController.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:78), which is the only actual API boundary that has the request object and can pass `counted_at_device` / `device_now`.

Failure scenario: The service signature changes, tests using the controller fail at runtime with an argument mismatch, or the controller keeps compiling only because the service defaults the fields and replay silently never sees the submitted device timestamps.

### MAJOR: Manual override timestamp source is not representable

B3 says `manual_override` should use "the seeded count's estimate, else resolved_at". The current manual override request has only `quantity` and `notes` [ManualOverrideRequest.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Requests/ManualOverrideRequest.php:24), and the service stores only `final_qty`, `resolution_method`, notes, resolver, and `resolved_at` [InventoryCountingService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:524). There is no "seeded count" field on the item fillable list [InventoryCountingItem.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:54).

Failure scenario: Different B3/D3 agents choose different interpretations. One uses `resolved_at`, another guesses `count_1_at_estimate`, and replay boundaries differ for the same manually overridden item.

### MAJOR: C2 consumes `late_sales_flags` before any producer owns it

The current `inventory_countings` model exposes existing fields only [InventoryCounting.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCounting.php:60), and the base migration has no late-sale JSON column [2025_12_02_070000_create_inventory_countings_table.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php:102). C2 instructs the projection to append a flag to the count session but delegates the column to A3, which does not list it.

Failure scenario: C2's projection path accepts a late signed sale, tries to update `late_sales_flags`, and the queue job fails after the fiscal event was accepted.

### MINOR: A2 produces route contracts but no generated DTO/type ownership

The plan says new/changed payload shapes get PHP DTOs and regenerated `packages/shared/types`, but A2 creates models, service, controller, and requests only. Current web counting code uses local handwritten types with stale values like `'warehouse'` [types.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/inventory-counting/types.ts:6), and the import/zone UI task allows local matching DTOs if transform does not emit them.

Failure scenario: Web D1/D2 agents hand-code zone payload types that drift from A2 controller output, then later `typescript:transform` either does not cover zones or overwrites incompatible shapes.

## 3. Hidden Codebase Conflicts

### BLOCKER: A1 under-scopes `occurred_at` writer coverage

A1 lists `StockAdjustmentService`, `PosCoreReceiptProjection`, and `ReceiptCreationService`, but actual `StockMovement::create` call sites exist in active services outside that set: opening posting [OpeningBalancePostingService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:117), WAC purchase/sale/cost paths [WeightedAverageCostService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:258), POS return/scrap [ReceiptReturnService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1237), POS void [ReceiptVoidService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:181), and supplier credit note posting [SupplierCreditNotePostingService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Procurement/Application/SupplierCreditNotePostingService.php:668). The model currently has no `occurred_at` fillable/cast [StockMovement.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/StockMovement.php:60).

Failure scenario: A live count finalizes after a POS return, void, supplier credit note, WAC cost adjustment, or opening operation created post-deploy. Those rows have null `occurred_at` unless the agent finds and patches every direct writer, so replay falls back to `created_at` and can order physical events incorrectly.

### MAJOR: POS blocking should be implemented at the existing stock gate, not by hunting HomePage actions

C2 tells the agent to "locate the add-to-cart/create-sale action" and refuse there. The POS code already documents a single ingress seam: every stock-relevant cart addition routes through `gateStockForAdd()` [stockGate.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/pos/src/lib/stock/stockGate.ts:1). The terminal store currently only knows `pos_stock_policy` [terminalStore.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/pos/src/stores/terminalStore.ts:21), and the gate reads that field [stockGate.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/pos/src/lib/stock/stockGate.ts:62). HomePage imports gated helpers [HomePage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/pos/src/pages/HomePage.tsx:16), but it is not the pure gate.

Failure scenario: A C2 agent patches one HomePage add path, while barcode scan, variant picker, smart prompts, and quantity-increase paths keep routing through the existing gate without count-block awareness.

### MAJOR: D1 hides a backend import change inside a web task

D1 is labeled "zones management UI" but also says to extend product import server-side. The actual product import path maps columns in the web wizard [ImportWizardPage.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/import/pages/ImportWizardPage.tsx:70), applies the source-to-target mapping server-side [ImportService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Import/Services/ImportService.php:631), and imports products through `ProductService::upsert()` [ImportService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Import/Services/ImportService.php:503). `ProductService::upsert()` ignores unknown `zone` data today [ProductService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Product/Application/Services/ProductService.php:63).

Failure scenario: The D1 web subagent adds a `zone` target column and UI test, but no backend assignment is created; imports appear to accept the mapped column while all zone assignment rows are missing.

### MAJOR: Existing tests assert legacy listener behavior and need explicit legacy-path coverage

The listener currently computes `delta = final_qty - theoretical_qty` [ApplyStockAdjustmentsOnCountingCompleted.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:64) and calls `StockAdjustmentService::adjust()` with reason `count_correction` [ApplyStockAdjustmentsOnCountingCompleted.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Listeners/ApplyStockAdjustmentsOnCountingCompleted.php:85). Existing tests instantiate finalized items without any future `final_qty_as_of` column and assert a `count_correction` movement [CountingReasonTest.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/tests/Feature/Inventory/CountingReasonTest.php:122), [CountingReasonTest.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/tests/Feature/Inventory/CountingReasonTest.php:146), and [InventoryCountingDefaultBatchTest.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/tests/Feature/Inventory/InventoryCountingDefaultBatchTest.php:96).

Failure scenario: B3 rewrites the listener and treats missing `final_qty_as_of` as "use resolved_at" instead of legacy. The new replay path posts a different adjustment or flags the item, breaking existing tests and in-flight count rollout behavior.

## 4. Replay Math / Sign / Locking

### BLOCKER: FirstCountDetector's specified receipt rule misses real receipt movements with null reason

B3 defines first count as no prior `opening`, no prior `receipt` with reason in `goods_receipt/opening_balance`, and no `transfer_in`. But `WeightedAverageCostService::recordPurchase()` creates receipt movements without a `reason` field [WeightedAverageCostService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:258). The enum does define `MovementReason::GoodsReceipt = 'goods_receipt'` [MovementReason.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:10), but the existing WAC receipt row does not populate it.

Failure scenario: A product was received through WAC/GRN before onboarding count, producing `movement_type = receipt` and `reason = null`. FirstCountDetector returns true and B3 posts an onboarding opening movement on top of already received stock, corrupting on-hand and WAC basis.

### MAJOR: The replay index omits `variant_id` even though replay signatures require it

B1's `signedDelta()` signature includes `?string $variantId`, and stock movements have a variant column plus existing variant-created index [2026_06_02_100006_add_variant_id_to_stock_movements.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php:27). A1's proposed replay index is only `(product_id, location_id, occurred_at)`.

Failure scenario: For a variant-heavy SKU at one location, replay scans all variant movement rows for that product/location and then filters `variant_id`, making B1/B3 slow exactly on POS-heavy products.

### MAJOR: The plan overstates the shared advisory-lock seam

`StockAdjustmentService` serializes cost/row creation through `ProductCostLock` [StockAdjustmentService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:617), whose lock key is product-grain `(tenant, company, product)` [ProductCostLock.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php:10). The same lock explicitly is not acquired by pure decrements/sales [ProductCostLock.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php:19), while POS projection directly row-locks stock levels and creates movements [PosCoreReceiptProjection.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1030).

Failure scenario: A B3 agent assumes the advisory lock blocks POS projection and does replay-read + on-hand-read under only `ProductCostLock`. It does not serialize with POS sales; correctness then depends on stock row lock timing that the task does not specify.

### MINOR: B4 normalization is underspecified for flag persistence

`CountingReconciliationService` currently stores one scalar `flag_reason` [InventoryCountingItem.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:74) and direct auto-resolution state [CountingReconciliationService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationService.php:114). B4 says normalized agreement appends `NormalizedAgreement` to `flag_reasons` but "does not block"; A3 adds `flag_reasons` jsonb while keeping legacy `flag_reason`.

Failure scenario: One agent clears `is_flagged` because the item auto-resolved, another sets it true because a flag exists. D3 then disables finalize for "flagged-unresolved" rows inconsistently.

## 5. Migration Safety

### BLOCKER: A1's backfill rewrites the append-only stock movement table with no batching or production guard

A1 instructs `UPDATE stock_movements SET occurred_at = created_at WHERE occurred_at IS NULL`. This repo treats `stock_movements` as an append-only audit log whose historical rows should not be rewritten casually [StockLevelMigrationService.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Application/Services/StockLevelMigrationService.php:22). Existing stock movement migrations that add heavy indexes use `public $withinTransaction = false` and `CREATE INDEX CONCURRENTLY` [2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:31), [2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_25_120000_add_reverses_movement_id_to_stock_movements.php:60).

Failure scenario: On a tenant with a large POS history, the migration takes a long table lock during overnight tenant migration. POS/fiscal projection writes block, or the migration times out mid-deploy.

### MAJOR: A1 repeats a scale-widening claim that is false for the current branch

The plan says `stock_movements.quantity_before/after` are current `decimal(15,2)` and "MUST widen." The base migration did create them at scale 2 [2025_11_30_110000_create_inventory_tables.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2025_11_30_110000_create_inventory_tables.php:38), but an existing tenant migration already widened `stock_levels` and `stock_movements` quantities to `decimal(15,4)` [2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php:39). The model casts are already `decimal:4` [StockMovement.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/StockMovement.php:92).

Failure scenario: An A1 agent writes tests or migrations assuming the pre-May schema. The migration may still widen to `decimal(20,4)`, but it is not the urgent precision fix the task claims, and unnecessary `ALTER COLUMN TYPE` locks the same hot table again.

### MAJOR: A1's replay index should be concurrent in PostgreSQL tenant migrations

Existing variant migration for `stock_movements` uses `public $withinTransaction = false` and `CREATE INDEX CONCURRENTLY` for a stock movement index [2026_06_02_100006_add_variant_id_to_stock_movements.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php:12), [2026_06_02_100006_add_variant_id_to_stock_movements.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_06_02_100006_add_variant_id_to_stock_movements.php:27). A1 only says "add index" and combines it with an `UPDATE` and `ALTER TABLE`.

Failure scenario: The generated migration runs inside Laravel's default transaction and builds a non-concurrent index on `stock_movements`, blocking writes longer than necessary.

### MINOR: July 6 migration prefix guidance is underspecified against existing files

The branch already has tenant migrations `2026_07_06_100000`, `110000`, `120000`, `130000`, and `180000` [database/migrations/tenant](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/database/migrations/tenant/2026_07_06_100000_add_source_columns_to_loyalty_transactions.php:1). The plan uses `2026_07_06_100001` through `100004`, which sort between existing `100000` and `110000`.

Failure scenario: This probably runs, but an autonomous agent can misinterpret "1NNNNN prefix" and create a duplicate or out-of-order migration name without noticing the branch already has July 6 migrations from other work.

## 6. Ambiguity / Misinterpretation Risk

### MAJOR: D3 opens a backend route in a frontend task without enough API contract detail

D3 says to add `PATCH /inventory/countings/{id}/items/{itemId}/opening-cost {unit_cost: string}` in "the same task". Existing counting routes have item submit/review routes [routes.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/routes.php:227) and manual override elsewhere, but there is no stated DTO, response shape, persistence column, or how it feeds B3. Current item fillable has no opening cost field [InventoryCountingItem.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Domain/InventoryCountingItem.php:54).

Failure scenario: D3 implements a route that writes `products.cost_price`, another agent expects per-count item cost, and B3 reads neither consistently when posting an opening movement.

### MAJOR: Zone-scoped assign-as-you-count is attached to the wrong existing add path

C1 says the barcode lookup + unexpected-item add path should assign zones, but the actual batch add-products route only updates `scope_filters.product_ids` and does not create `inventory_counting_items` [InventoryCountingController.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:904). The actual count submission path goes through `CountingItemController::submitCount()` [CountingItemController.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:78).

Failure scenario: A C1 agent zone-assigns during draft batch add, but mobile/web scan during active counting still submits counts through the item endpoint and never calls `ZoneService::assignProduct`.

### MAJOR: Blocking mode does not specify zone advisory behavior in the execution tasks

The spec says zone-scoped counts never hard-block and should show soft advisory. C2's produced contract is `activeBlockFor(string $locationId): ?InventoryCounting` for a `block_sales=true` counting whose scope covers the location, and POS refuses new sale lines when block active. Actual terminal payload currently has only one `pos_stock_policy` field [TerminalResource.php](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:29) and the POS terminal type has no block/advisory field [terminalStore.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/pos/src/stores/terminalStore.ts:37).

Failure scenario: A C2 agent hard-blocks all sales for a zone count at the whole location because `activeBlockFor(location_id)` cannot express product/zone coverage or advisory-only state.

### MINOR: Web quantity precision cleanup is split across too many tasks

The web types still use `number` for count quantities and manual override [types.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/inventory-counting/types.ts:120), [types.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/inventory-counting/types.ts:231), and manual override parses floats [ManualOverrideDialog.tsx](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/inventory-counting/components/ManualOverrideDialog.tsx:27). D2 says "quantities in these types become string"; D3 also changes the same types/components.

Failure scenario: D2 changes shared types to strings while D3 simultaneously changes `ManualOverrideDialog` and `countingApi.manualOverride()` [countingApi.ts](/Users/houssamr/Projects/syneriva/apps/erp.live-counting/apps/web/src/features/inventory-counting/api/countingApi.ts:95). Parallel wave D agents get TS errors or reintroduce `number`.

## Severity Count Summary

| Severity | Count |
| --- | ---: |
| BLOCKER | 6 |
| MAJOR | 15 |
| MINOR | 4 |
| NIT | 0 |
