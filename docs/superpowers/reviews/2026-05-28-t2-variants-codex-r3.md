# T2 Product Variants - Codex adversarial review round 3

**Reviewer:** Codex (gpt-5)
**Spec under review:** `apps/erp/docs/superpowers/specs/2026-05-28-t2-product-variants.md` (v4)
**Plan under review:** `apps/erp/docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md` (v4)
**Verdict:** REJECT
**Confidence:** high

## Summary

v4 fixes some R2 P1s in the spec narrative and in isolated plan snippets, but the executable plan still contains P1 blockers. The biggest issue is that the plan header claims every large-table migration uses `NOT VALID`, `CREATE INDEX CONCURRENTLY`, and `$withinTransaction = false`, while Tasks 5, 6, 8, 9, and 10 still show immediate Laravel FK creation and normal indexes. The deferred validation task exists, but it cannot validate constraints that the earlier tasks never created as `NOT VALID`.

The shared `ProductVariantLookup` path is corrected in one task body, but that task is still physically after Task 17, and the old removed Task 28a body with the wrong `App\Modules\Shared` namespace remains in the file. Accounting is also only partially fixed: `topSkus` now preserves the real receipt scoping, but `StockAlertReportService` is rewritten against a non-existent method/signature and a DTO shape that does not match the real code.

Task 16b's core `consumeBatchesAtomically` snippet now matches the real `inventory_batch_movements` schema, uses raw `FOR UPDATE SKIP LOCKED`, and dispatches after commit. The channel listener V2 migration is coherent enough in v4. Pricing signatures in the requested spec sections are fixed, but the plan still tells implementers to write a README with the old wrong pricing order.

## R2 P1 verification

### P1-1 - `consumeBatchesAtomically` vs real batch movement schema

**Resolved for the requested core checks.** The real schema at `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php` requires auto-increment `id`, `tenant_id`, `batch_id`, `movement_id`, `quantity`, and `created_at`. Task 16b now documents that shape at plan lines 1998-2004 and inserts `tenant_id`, `batch_id`, `movement_id`, `quantity`, and `created_at` at lines 2099-2106. It no longer supplies a bogus `id`.

The locking implementation is also corrected: Task 16b uses raw SQL with `FOR UPDATE OF ibs SKIP LOCKED` at lines 2065-2082, not Laravel `lockForUpdate()`. The event is registered via `DB::afterCommit` at lines 2122-2130.

Residual P2: the tests in the same task still call `consumeBatchesAtomically` without the new required `tenantId` and `movementId` parameters at lines 1942-1948 and 1963-1968, and still assert `shortfall` as integer `0` at line 1950 even though the DTO is now a decimal string. The implementation snippet is fixed, but the TDD harness is stale.

### P1-2 - Online DDL `NOT VALID` + `CONCURRENTLY`

**Not resolved.** Task 11c exists at plan lines 3377-3423, but Tasks 5, 6, 8, 9, and 10 do not uniformly use the online-DDL pattern v4 claims.

- Task 5 still creates `stock_levels.variant_id` with `foreignUuid()->constrained()` at lines 762-767, creates replacement indexes without `CONCURRENTLY` at lines 775-780, and does not declare `$withinTransaction = false`.
- Task 6 still creates `stock_movements.variant_id` and `stock_reservations.variant_id` with immediate Laravel FKs at lines 839-850 and normal Schema indexes. This includes the 5M-row `stock_movements` table.
- Task 8 still uses immediate `foreignUuid()->constrained()` for POS/document line tables at lines 993-998, with no `NOT VALID` FK split shown.
- Task 9 uses `NOT VALID` and `CONCURRENTLY` for `price_list_items`, but `catalog_cart_items` still uses immediate `foreignUuid()->constrained()` and a normal index at lines 1076-1080.
- Task 10 still creates `recipe_lines.component_variant_id` with immediate `foreignUuid()->constrained()` at lines 1223-1226.

This directly contradicts the v4 header claim at plan line 7 and the spec's operational strategy. It remains an operational P1 because the plan still permits blocking FK validation and non-concurrent index creation in the main deploy path.

### P1-3 - `ProductVariantLookup` namespace and ordering

**Not resolved.** The corrected task body uses the real path and namespace (`apps/api/app/Shared/Contracts/ProductVariantLookup.php`, `App\Shared\Contracts`) at plan lines 3181-3253. That part is fixed.

But Task 11b is not actually ordered before Task 17 in the document. Task 17 starts at line 2154 and consumes `$this->variants->findById($variantId)` at line 2270. Task 11b does not appear until line 3181, after Task 27b and after the service-layer tasks that need it.

Worse, the supposedly removed Task 28a still contains the old wrong namespace body at lines 3427-3451:

- `namespace App\Modules\Shared\Contracts;`
- `use App\Modules\Shared\DTOs\ProductVariantSummary;`
- `namespace App\Modules\Shared\DTOs;`

So v4 has both the correct contract and the old incorrect contract instructions. Implementers can still follow the wrong namespace/path, and Task 17 still appears before its dependency.

### P1-4 - Accounting report rewrites preserve scoping

**Partially resolved, still P1 for `StockAlertReportService`.**

`topSkus` is substantially fixed. The plan preserves the real signature at lines 3024-3028, keeps the `pos_receipts` join, and preserves `company_id`, `location_id`, `is_voided`, `training_flag`, and `posted_at` filters at lines 3033-3041.

`StockAlertReportService` is not implementable against the real service. The current method is `lowStockAcrossLocations(array $companyIds, array $locationIds, int $thresholdPct): array`, and it returns `StockAlertData(product_id, product_name, location_id, location_name, quantity, min_quantity, threshold_pct, severity)`. The v4 plan instead invents `lowStockAlerts(string $tenantId, array $companyIds, array $locationIds)` at line 3081 and constructs `StockAlertData` with `productId`, `variantId`, `locationId`, `sku`, `name`, `currentQuantity`, and `minimumQuantity` at lines 3107-3115.

The rewrite does preserve `companyIds` and `locationIds` at lines 3091-3092, but it drops the real `thresholdPct` parameter, the threshold-ratio behavior, the `locations` join and `location_name`, and the `severity` field. This is the same class of R2 blocker: the report rewrite does not preserve the existing service contract and would not compile against the current DTO.

### P1-5 - `PricingService` signature consistency

**Resolved in the requested spec sections, but the plan still contains the old wrong order.** Spec §5.3 uses the real signature plus trailing `$variantId` at lines 362-382. Spec §6.2 repeats the same shape at line 568. Spec §9.2 also uses the same signature and explicitly says variant override wins at line 976.

However, Task 17's README snippet still documents the wrong resolution order at plan lines 2297-2308: price-list rows first, product `sale_price` third, and variant `price_override` last. That contradicts the Task 17 test at lines 2170-2197, the implementation snippet at lines 2268-2278, and spec §9.2's "DO NOT reorder this anywhere." Because the executable plan asks implementers to write contradictory pricing docs, the R2 pricing-order part is not fully fixed.

### P1-6 - Channel listener V2 migration coherence

**Resolved for the requested listener-scope check.** Task 19 no longer says the channel listener migration is out of scope; it explicitly says `DispatchStockChangeToChannels` is migrated to V2 in T2 at lines 2515-2516. Task 26 modifies `DispatchStockChangeToChannels` to read from `StockMovementRecordedV2` at lines 2877-2879 and says the listener subscribes to V2 at lines 2912-2915.

There is still polish drift: spec §5.8 says "V3 event" and "No new migrations" at lines 450-451, while spec §9.1 and plan Task 9b correctly say `StockMovementRecordedV2` and include the channel partial-unique migration. I do not consider that a P1 for the listener migration because §9.1 and Tasks 9b/19/26 are authoritative and coherent enough to implement.

## P1 findings

### P1-1 - Online-DDL fix is still not applied to the executable migration tasks

See R2 P1-2 verification above. This remains the largest blocker. The plan's summary says the issue is fixed, but the code snippets in Tasks 5, 6, 8, 9, and 10 still use immediate FK/index operations.

### P1-2 - `ProductVariantLookup` is still ordered after consumers and the wrong namespace remains

See R2 P1-3 verification above. The correct contract body exists, but it appears after Task 17 and the old wrong `App\Modules\Shared` task body remains. The plan is internally contradictory and can still lead to an implementation in the non-existent namespace.

### P1-3 - `StockAlertReportService` rewrite does not match the real service/DTO contract

See R2 P1-4 verification above. The v4 rewrite preserves company/location filters but is not compatible with the current `lowStockAcrossLocations` method or `StockAlertData` constructor, and it drops threshold/severity/location-name behavior.

### P1-4 - Pricing order remains contradictory in Task 17 documentation

Spec §5.3/§6.2/§9.2 now agree on the real method signature, but Task 17 still instructs implementers to write a README that puts variant override last. That contradicts the locked "variant override wins" rule and reintroduces the R2 pricing-order ambiguity.

## P2 findings

### P2-1 - Task 16b tests are stale against the corrected method signature

The implementation snippet requires `tenantId` and `movementId`, but the tests still omit both and assert `shortfall` as an integer. This is not a schema/concurrency blocker because the implementation code is correct, but it will break the TDD task as written.

### P2-2 - Spec §5.8 still contradicts the channel migration story

Spec §5.8 says "No new migrations" for channel sync, but §4.4, §9.1, and plan Task 9b all correctly require replacing `channel_product_mappings`' nullable unique with partial uniques. Fix the stale §5.8 sentence to avoid another reviewer re-opening the old channel finding.

### P2-3 - `SalesOrderConfirmedV2` is still conditionally phrased in Task 19

Task 19 Step 4 says `SalesOrderConfirmedV2` is required, but Step 5 still asks implementers to verify whether the payload is serialized and skip the V bump if only IDs are referenced at lines 2503-2504. The spec already verified the payload is serialized and says the V2 event is required. This is not one of the six R2 P1s, but it remains contradictory.

## Things I verified and agree with

- `inventory_batch_movements` really requires `tenant_id`, `batch_id`, `movement_id`, `quantity`, and `created_at`; v4 Task 16b's core insert now matches that schema.
- Task 16b uses raw `FOR UPDATE OF ibs SKIP LOCKED` and `DB::afterCommit`.
- The corrected `ProductVariantLookup` task body uses `App\Shared\Contracts` and `App\Shared\DTOs`.
- `topSkus` now preserves `companyIds`, `locationIds`, voided/training filters, and `posted_at` scoping.
- Spec §5.3, §6.2, and §9.2 now use the same real `PricingService::getPrice` signature.
- The channel listener V2 migration is in scope in Task 19 and implemented by Task 26.

## Files read

- `docs/superpowers/reviews/2026-05-28-t2-variants-codex-r2.md`
- `docs/superpowers/reviews/2026-05-28-t2-variants-opus-r4.md`
- `docs/superpowers/specs/2026-05-28-t2-product-variants.md`
- `docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md`
- `apps/api/database/migrations/tenant/2026_01_05_150002_create_inventory_batch_movements_table.php`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php`
- `apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php`
- `apps/api/app/Modules/Accounting/Application/DTOs/Reports/TopSkuData.php`
- `apps/api/app/Modules/Accounting/Application/DTOs/Reports/StockAlertData.php`
- `apps/api/app/Modules/Pricing/Domain/Services/PricingService.php`

## Things missed

- I did not do a full re-audit of all variant tasks or frontend/POS tasks; this round focused on the six R2 P1 fixes plus a brief pass for v4-introduced drift.
- I did not run backend or frontend tests; this was a source/spec/plan review only.
