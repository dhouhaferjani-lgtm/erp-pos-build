# T2 Product Variants - Codex adversarial review round 2

**Reviewer:** Codex (gpt-5.5)
**Spec under review:** `apps/erp/docs/superpowers/specs/2026-05-28-t2-product-variants.md` (v3)
**Plan under review:** `apps/erp/docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md` (v3)
**Verdict:** REJECT
**Confidence:** high

## Summary

v3 fixes some of the R1 issues in isolated sections, but it is not implementation-ready. Several fixes are only partially applied: the correct constraint names appear in the schema tasks, but the online-DDL strategy is not carried through to the large `stock_movements` migration; the real `PricingService` signature is corrected in one section but the old nonexistent signature reappears later; the channel unique fix is present in Task 9b but the channel spec still says no migration is needed; and the FEFO atomic primitive is specified but the plan's code does not match the real batch-movement schema and does not actually implement the promised `SKIP LOCKED` behavior.

The largest blockers are new v3 regressions around operational correctness:

- `consumeBatchesAtomically` cannot write valid `inventory_batch_movements` rows as written and can consume reserved stock.
- The online-DDL/`NOT VALID` story is claimed globally but not implemented for `stock_movements`, `stock_levels`, cart, POS, or document-line migrations.
- `ProductVariantLookup` is placed under a non-existent `App\Modules\Shared` namespace and is scheduled after tasks that already use it.
- Accounting report snippets drop existing company/location/receipt scoping and would leak or aggregate cross-scope data.
- Channel stock propagation still has contradictory V1/V2 subscriber guidance; the existing listener cannot correctly scope a stock event to one variant unless it is migrated to V2 in T2.

## R1 P1 verification

### P1-1 - Product batch partial-unique migration drops the wrong constraint

**Partially resolved.** The spec now names `unique_batch_per_product`, and Task 7 drops that exact constraint. I verified the real tenant migration defines it at `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:47`.

Caveat: Task 7's `down()` recreates the old full unique before dropping the partial indexes. If variant rows with duplicate batch numbers exist, rollback can fail, despite the spec saying rollback is exercised. This is a P2 below, not a failure of the original constraint-name fix.

### P1-2 - Price-list partial-unique migration drops the wrong constraint

**Resolved for the original bug.** The spec and Task 9 now use `price_list_product_qty_unique`; the real migration defines it at `apps/api/database/migrations/tenant/2025_12_01_201028_create_price_list_items_table.php:23`.

Same rollback caveat as P1-1 applies if T2 data exists.

### P1-3 - `PricingService` signature and return type

**Not fully resolved.** Spec §5.3 and Task 17 correctly identify the real signature:

`getPrice(string $productId, ?string $partnerId = null, string $quantity = '1.00', string $currency = 'USD', ?DateTimeInterface $date = null): array`

I verified this at `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:32-38`.

But v3 still reintroduces the old nonexistent signature in spec §6.2 (`PricingService::getPrice(UUID $priceListId, UUID $productId, string $quantity, ?UUID $variantId = null): string`) and again in §9.2 (`getPrice($priceListId, $productId, ...)`). Task 17's README snippet also documents the wrong order, putting `variant price_override` after product `sale_price`, while the same task's test and implementation snippet expect override first. This is exactly the kind of conflicting instruction that caused R1 P1-3.

### P1-4 - Channel product mapping nullable unique

**Partially resolved.** Spec §4.4 and plan Task 9b correctly replace `channel_product_variant_unique` with two partial unique indexes. I verified the real migration currently has a normal unique on `(channel_id, product_id, variant_id)` at `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php:27`.

But spec §5.8 and §9.1 still say the channel layer is pre-positioned, the composite unique "already includes" `variant_id`, and "No new migrations" are needed. That directly contradicts §4.4 and Task 9b.

### P1-5 - FEFO/concurrency premise false

**Not resolved.** The spec now names the right primitive, but the plan's implementation is not correct against the real schema. See P1-1 below for the detailed blocker.

I re-verified the current `suggestBatchesForSale()` is read-only and calls `get()` without locks at `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:27-40`. Current POS `allocateBatches()` logs shortfall and proceeds at `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:1297-1321`.

### P1-6 - Recipe selling atomic availability/FEFO contract

**Not resolved.** The spec describes deterministic lock ordering, but the plan delegates recipe ingredient consumption to the broken `consumeBatchesAtomically` primitive and does not provide an implementable multi-ingredient batch-consumption task with valid audit writes. Until P1-1 is fixed, recipe atomicity remains unresolved.

### P1-7 - `StockMovementRecorded` producers under-enumerated

**Mostly resolved.** The spec and Task 19 enumerate the six real producer sites. I verified them:

- `StockAdjustmentService::receive()` at line 80
- `StockAdjustmentService::issue()` at line 169
- `StockAdjustmentService::transfer()` outbound at line 277
- `StockAdjustmentService::transfer()` inbound at line 292
- `StockAdjustmentService::adjust()` at line 453
- `WeightedAverageCostService::dispatchStockMovementEvent()` at line 476

Residual issues: Task 19 still uses placeholder event code (`/* ... */`), uses `getAuditPayload()` even though the existing event exposes `getAuditData()`, and its `SalesOrderConfirmedV2` step remains conditional despite the spec saying it is required.

### P1-8 - POS Wave 2 scope contradictory

**Resolved in intent.** Spec §10.4 clearly splits Wave 1 server acceptance from Wave 2 POS UI acceptance, and Task 32 keeps Wave 2 out of the Wave-1 executable gate.

Residual plan polish: Task 32's markdown fence is malformed, still contains placeholder test bodies, and references "Task 33" even though no Task 33 exists.

## P1 findings

### P1-1 - `consumeBatchesAtomically` is not implementable against the real batch schema and can corrupt reserved stock

Plan Task 16b adds the right primitive name, but the code does not match the real tables:

- `inventory_batch_movements` requires `tenant_id`, `batch_id`, `movement_id`, and `quantity`; `movement_id` is a non-null FK to `stock_movements` in `2026_01_05_150002_create_inventory_batch_movements_table.php:18-25`.
- Task 16b inserts only `id`, `batch_id`, `quantity`, and `created_at` at plan lines 2063-2068. `id` is not a column, while `tenant_id` and `movement_id` are missing.
- The new method signature has no `tenantId` or `movementId`, so it cannot write valid audit rows or preserve the existing linkage from batch movement to aggregate stock movement.
- The query filters `ibs.available_quantity > 0` but selects and consumes `ibs.quantity` at plan lines 2042 and 2048, then computes `$take` from `$row->quantity` at line 2057. That can consume stock that is already reserved.
- The spec promises `FOR UPDATE SKIP LOCKED`, but the plan uses Laravel `lockForUpdate()` with a comment saying raw SQL may be needed at line 2049. That is not an implementation.
- `BatchStockConsumed` is dispatched inside the transaction at line 2086, not after commit. In a nested recipe/POS transaction, a later rollback can leave an event for a consumption that never committed.

Existing `BatchStockService::issueBatchStock()` takes `tenantId`, `batchId`, `locationId`, `quantity`, and optional `movementId`, locks the exact batch stock row, and records a valid `BatchMovement` through `recordBatchMovement()` (`BatchStockService.php:92-120`, `176-214`). The new primitive needs to preserve those invariants while adding FEFO multi-row selection.

### P1-2 - The online-DDL `NOT VALID` + validation split is claimed but not implemented across the plan

Spec §4.5 says nullable columns, `NOT VALID` FKs, concurrent indexes, and a separate `VALIDATE CONSTRAINT` migration are the T2 operational strategy. Plan line 17 claims all migrations use `$withinTransaction = false`, `CREATE INDEX CONCURRENTLY`, `ADD FK ... NOT VALID`, and a separate validate step.

The task code contradicts that:

- Task 5 adds `stock_levels.variant_id` with `foreignUuid()->constrained()` and non-concurrent indexes, then drops the old unique before creating replacements (`plan:754-772`).
- Task 6 adds `stock_movements.variant_id` with `foreignUuid()->constrained()` (`plan:831-835`). This is the 5M-row table the spec explicitly called out as needing `NOT VALID`.
- Task 8 uses immediate FKs on `document_lines`, `pos_receipt_lines`, `pos_order_lines`, and batch allocations.
- Task 9 uses `NOT VALID` for `price_list_items`, but `catalog_cart_items` still uses immediate FK creation (`plan:1068-1072`).
- Task 7 only says "Add a follow-up migration" at line 947; there is no actual follow-up migration task that runs `VALIDATE CONSTRAINT` for all deferred FKs.

This is an operational blocker because the plan still allows a blocking FK validation on `stock_movements` during the main T2 deploy.

### P1-3 - `ProductVariantLookup` is in the wrong namespace/path and is scheduled after consumers already use it

Spec §6.0 and Task 28a place shared contracts under `apps/api/app/Modules/Shared/...` with namespace `App\Modules\Shared\Contracts`. The real codebase uses `apps/api/app/Shared/Contracts/...` with namespace `App\Shared\Contracts` for cross-module contracts. I verified existing shared contracts and bindings in `apps/api/app/Shared/Contracts/*` and `apps/api/app/Providers/AppServiceProvider.php`.

Task 17 uses `$this->variants->findById($variantId)` and says it uses `ProductVariantLookup` at plan line 2224. But Task 28a, which creates that contract, does not run until much later at lines 3056-3124. That means the pricing task either cannot compile, or implementers will import the Catalog Eloquent model directly and then try to refactor later.

The migration path does not break existing entity-level mocks because `ProductVariant` does not exist in this branch yet; `rg` found no `App\Modules\Catalog\Domain\Entities\ProductVariant` imports. The problem is that v3 creates the new abstraction in the wrong place and too late.

### P1-4 - Accounting report rewrites drop existing tenant/company/location scoping and return the wrong shape

Task 27b's `topSkus()` rewrite does not preserve the real method's scoping. The current service joins `pos_receipts`, filters `companyIds`, `locationIds`, void/training flags, and `posted_at`, then maps rows into `TopSkuData` (`SalesReportService.php:68-100`). The proposed query reads directly from `pos_receipt_lines`, filters only `l.created_at`, and returns a raw collection (`plan:2973-2988`).

That is not just incomplete sample code: it would remove the existing report boundaries and can blend data across companies/locations inside a tenant. The test call at plan line 2956 is still a placeholder (`/* tenant + date range */`), so it will not catch the scoping regression.

`StockAlertReportService` has the same problem. The current service filters by `stock_levels.company_id` and `stock_levels.location_id` (`StockAlertReportService.php:27-41`). The proposed snippet drops those filters and uses `where('s.quantity', '<', 's.min_quantity')` (`plan:2995-2999`), which compares against a literal string instead of a column. It should preserve the existing filters and use `whereColumn()` or the current threshold expression.

### P1-5 - Pricing fix is internally contradictory and still references the wrong signature/order

The correct `PricingService` signature is present in spec §5.3 and plan Task 17, but v3 still contains these wrong instructions:

- Spec §6.2 says `PricingService::getPrice(UUID $priceListId, UUID $productId, string $quantity, ?UUID $variantId = null): string` (`spec:565-566`).
- Spec §9.2 repeats `$priceListId` as the first parameter and says `price_override` is a fallback after product `sale_price` (`spec:969-970`), contradicting §5.3's "override wins" rule.
- Task 17's README snippet documents price-list rows first, product sale price third, and variant override fourth (`plan:2257-2261`), contradicting its own test `test_variant_override_wins_over_price_list()`.
- T11 still documents parity as a five-argument call to `PricingService::getPrice($productId, $partnerId, $quantity, $currency, $date)` in `2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md:112-120`; v3 says T11 already passes `variantId` through, which is not true in that spec.

This leaves implementers with three different pricing contracts in one pair of v3 artifacts.

### P1-6 - Channel stock sync remains contradictory and can still push the wrong variant mappings

Spec §5.8 says subscribers can stay on V1 until a follow-up, while §9.1 says `DispatchStockChangeToChannels` subscribes to `StockMovementRecordedV2` in T2. Plan Task 19 says migrating the channel listener to V2 is out of T2 at lines 2469-2470, but Task 26 later says to modify the listener to read `StockMovementRecordedV2` at lines 2831-2863.

This matters because the real listener cannot infer event variant scope from V1. It filters only by `product_id` and dispatches every published mapping for that product (`DispatchStockChangeToChannels.php:30-53`), using `$mapping->variant_id` rather than an event `variantId`. For a stock movement on variant A, that can push stock jobs for variant B and the product-level mapping.

Task 26 needs to be the authoritative T2 path: subscribe to V2, filter mapping rows by `(product_id, variant_id)` with `whereNull` for product-level movements, and include `variant_id` in the idempotency/cache key. The current v3 text does not make that coherent.

## P2 findings

### P2-1 - `SalesOrderConfirmedV2` is safe for V1 consumers, but the plan still leaves it conditional and incomplete

The real V1 event serializes line arrays at `SalesOrderConfirmed.php:24-35`, and `SalesOrderService` dispatches it with reservation arrays at `SalesOrderService.php:248-263`. The only subscriber I found, `Compliance\Listeners\DomainEventSubscriber::handleSalesOrderConfirmed`, does not parse individual line entries; it stores `lines_count` only (`DomainEventSubscriber.php:404-420`). So adding a V2 with `variant_id` while continuing V1 should not break the current consumer.

However, Task 19's file list does not include `SalesOrderConfirmedV2`, and Step 5 still says "If lines are serialized ... spawn" (`plan:2457`) even though the spec and actual code already verify that they are serialized. Make it required and add the class to the file list.

### P2-2 - POS "shortfall now fails the sale" behavior needs explicit fiscal/product sign-off, not just an impl-PR note

Task 16b correctly recognizes the behavior change: batch-tracked POS sales with insufficient batch stock now fail instead of completing with a warning (`plan:2093-2096`). The spec also says the current behavior is fixed.

That is not explicit enough for fiscal sign-off. This changes cashier-facing sale completion semantics and can affect NF525/audit expectations around rejected receipts. It should be called out in the spec header/coordination notes with a named fiscal/product approval gate, not just documented in the implementation PR.

### P2-3 - Task 7/9 rollback paths can fail after valid T2 data exists

Task 7's down migration recreates `unique_batch_per_product` before dropping the partial indexes (`plan:930-938`). If T2 has valid rows with the same batch number across variants, the old full unique cannot be recreated.

The same class of issue applies to `price_list_items`: once variant-specific and product-level rows coexist at the same quantity, restoring the old unique can fail. If rollback is expected to work after data writes, the down path must either be documented as destructive/manual or include a data preflight/refusal.

### P2-4 - Plan still violates the repository commit convention

The v3 header claims commit examples are corrected to `Phase <major.minor.patch>: ...` (`plan:18`), but line 35 still instructs `feat(t2):`, and many task commits still use `feat(t2):` / `refactor(t2):` (for example `plan:798`, `plan:850`, `plan:956`, `plan:1080`, `plan:2268`, `plan:2466`). This was an R1 P2 and is still present.

### P2-5 - Placeholder and malformed acceptance plan remains

Task 32 still uses placeholder method bodies for every acceptance test (`plan:3293-3316`), despite line 19 claiming every placeholder was replaced. The Wave-2 coordination log markdown fence opened at `plan:3323` is not closed before Step 3, and the self-review references "Task 33" at `plan:3430` even though the plan has no Task 33.

### P2-6 - Event V2 snippet does not mirror the real event API

Task 19's `StockMovementRecordedV2` snippet uses `getAuditPayload()` (`plan:2420-2422`), but the existing `StockMovementRecorded` event uses `getAuditData()` (`StockMovementRecorded.php:55-70`). If implementers copy the snippet, downstream audit code that expects the existing method naming pattern will not compose cleanly.

### P2-7 - Channel service test calls the real method with the wrong argument order and missing required overrides

Task 26 test calls `publishProduct($product->id, $channel->id, $variant->id)` (`plan:2845`). The real signature is `publishProduct(string $channelId, string $productId, ?string $variantId, array $overrides, ?string $companyId = null)` at `ChannelService.php:55`. The test will not compile and also swaps product/channel order.

### P2-8 - `certification_expiry_notifications.variant_id` is mentioned but not specified or planned

Spec §7.3 says T2 adds `variant_id` to `certification_expiry_notifications` "per §4.2 supplement", but §4.2's table does not include that table and the plan has no migration or task for it. Either add the schema/task or remove the claim.

### P2-9 - Decimal quantities are downgraded to integer shortfalls in batch consumption

`BatchConsumptionResultDTO::$shortfall` and `InsufficientBatchStockException::$shortfall` are typed as `int` (`plan:1991-2018`), and the plan uses `ceil((float) $remaining)` (`plan:2080`). The rest of inventory and batch stock uses numeric strings/decimal scales. This loses precision for fractional products and recipes.

## P3 findings

### P3-1 - Section numbering is duplicated in the spec

The spec has two `5.10` / `5.11` sequences around Accounting, Workshop, and frontend DTOs. This is minor but makes references in reviews and implementation comments harder to follow.

### P3-2 - Task numbering/name mismatch

The plan header says "NEW Task 28b" for `ProductVariantLookup` (`plan:14`), but the actual task is `Task 28a` (`plan:3056`). Use one task number.

### P3-3 - `suggestBatchesForSale` SQL uses `LIMIT :qty`

Spec §7.2.1's advisory SQL uses `LIMIT :qty` (`spec:730-743`). Quantity is a decimal requested amount, not a row count. The implementation should either omit a limit or use a bounded row limit derived from a sane operational cap.

### P3-4 - Architecture grounding still leaves `ComponentType` open

Spec §3 still says T2 needs either a new `ComponentType::ProductVariant` case or an explicit `component_variant_id` column, while §8.2 correctly locks the column decision. This was also noted by Opus r3 as a minor.

## Things I verified and agree with

- `product_batches` really uses `unique_batch_per_product` at `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:47`.
- `price_list_items` really uses `price_list_product_qty_unique` at `apps/api/database/migrations/tenant/2025_12_01_201028_create_price_list_items_table.php:23`.
- `channel_product_mappings` really has `variant_id` and a nullable composite unique named `channel_product_variant_unique` at `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php:17,27`.
- `PricingService::getPrice` real signature and array return shape are as R1 reported.
- Current FEFO suggestion is read-only and does not lock rows.
- Current POS batch allocation logs shortfall and continues.
- `inventory_batch_movements` requires `tenant_id` and `movement_id`; Task 16b's insert does not satisfy the schema.
- The six `StockMovementRecorded` producer sites in v3 match the current codebase.
- `SalesOrderConfirmed` serializes line arrays, and current compliance subscriber only counts lines rather than parsing line shape.
- Existing shared contracts live under `apps/api/app/Shared/Contracts` with namespace `App\Shared\Contracts`, not under `App\Modules\Shared`.
- Current Accounting report services scope by company/location and receipt state; Task 27b snippets do not preserve that.

## Files read

- `docs/superpowers/reviews/2026-05-28-t2-variants-codex-r1.md`
- `docs/superpowers/reviews/2026-05-28-t2-variants-opus-r3.md`
- `docs/superpowers/specs/2026-05-28-t2-product-variants.md`
- `docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md`
- `docs/superpowers/specs/2026-05-24-t11-impl-b-pricing-resolver-doc-policy.md`
- `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php`
- `apps/api/database/migrations/tenant/2025_12_01_201028_create_price_list_items_table.php`
- `apps/api/database/migrations/tenant/2026_05_24_120002_create_channel_product_mappings_table.php`
- `apps/api/database/migrations/tenant/2026_01_05_150001_create_inventory_batch_stock_table.php`
- `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php`
- `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php`
- `apps/api/app/Modules/BatchExpiry/Application/Services/BatchStockService.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchStock.php`
- `apps/api/app/Modules/BatchExpiry/Domain/Entities/BatchMovement.php`
- `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php`
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
- `apps/api/app/Modules/Inventory/Domain/Events/StockMovementRecorded.php`
- `apps/api/app/Shared/Domain/Events/DomainEvent.php`
- `apps/api/app/Modules/Document/Domain/Events/SalesOrderConfirmed.php`
- `apps/api/app/Modules/Document/Domain/Services/SalesOrderService.php`
- `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php`
- `apps/api/app/Modules/Accounting/Application/DTOs/Reports/TopSkuData.php`
- `apps/api/app/Modules/Accounting/Application/DTOs/Reports/StockAlertData.php`
- `apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php`
- `apps/api/app/Modules/Channel/Application/Services/ChannelService.php`
- `apps/api/app/Modules/Channel/Application/Listeners/DispatchStockChangeToChannels.php`
- `apps/api/app/Modules/Channel/Application/Jobs/DispatchStockChangeToChannelJob.php`

## Things missed

- I did not run the backend or frontend test suites; this was a source/spec/plan review only.
- I did not fully audit every frontend variant-picker task, because the round-2 prompt emphasized server-side correctness and v3 fix verification.
- I did not inspect every loyalty implementation path beyond confirming the v3 semantic direction and the plan surface.
- I did not verify generated TypeScript output or route/controller request DTOs.

