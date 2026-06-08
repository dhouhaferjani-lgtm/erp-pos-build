# T2 Product Variants - Codex adversarial review round 4

## Verdict

APPROVE-WITH-MINOR-EDITS

## Confidence

High.

## Summary

The r3-polish pass mechanically applied all four r3 P1 fixes and all three r3 P2 fixes in the v4 spec/plan. I did not re-audit r1 or r2 findings.

No new P1 was introduced. One new P3 polish issue remains in the plan summary: it still names the shared lookup task as "Task 28b" even though the actual implementation task is now Task 11b and the old Task 28a body is a redirect.

## r3 P1 verification

### r3 P1-1 - Online-DDL across Tasks 5, 6, 8, 9, 10

Mechanically resolved.

- Task 5 now declares `public $withinTransaction = false;` at plan lines 758-763, adds the FK with `ADD CONSTRAINT ... NOT VALID` at lines 775-779, builds both replacement uniques with `CREATE UNIQUE INDEX CONCURRENTLY` at lines 781-787, and rolls back by restoring the old unique, dropping the concurrent indexes, dropping the FK, then dropping the column at lines 793-805.
- Task 6 now applies the same online-DDL shape to both migrations: `stock_movements` declares the transaction override at lines 846-851, adds the FK as `NOT VALID` at lines 863-866, creates the index concurrently at lines 868-869, and drops index/FK before the column at lines 872-879. `stock_reservations` declares the override at lines 887-888, adds the FK as `NOT VALID` at lines 900-903, creates the index concurrently at lines 905-906, and reverses index/FK/column at lines 909-916.
- Task 8 now states all four migrations declare `public $withinTransaction = false;` at lines 1059-1065. The representative migration adds the FK as `NOT VALID` at lines 1078-1082, adds the CHECK as `NOT VALID` at lines 1084-1088, and drops CHECK/FK before the column at lines 1091-1099. The plan says to repeat the same pattern for `document_lines`, `pos_order_lines`, and `pos_receipt_line_batch_allocations`, with the allocation table omitting only the CHECK at line 1104.
- Task 9 now fixes both affected migrations. `price_list_items` declares the transaction override at lines 1151-1156, adds the FK as `NOT VALID` at lines 1168-1171, creates both replacement unique indexes concurrently at lines 1173-1178, and restores/drops in rollback at lines 1184-1195. `catalog_cart_items` declares the override at lines 1201-1203, adds the FK as `NOT VALID` at lines 1215-1218, creates the non-unique index concurrently at lines 1220-1221, and drops index/FK before the column at lines 1224-1232.
- Task 10 declares the transaction override at lines 1373-1377, adds the FK as `NOT VALID` at lines 1389-1392, creates the non-unique index concurrently at lines 1394-1395, adds the CHECK as `NOT VALID` at lines 1397-1399, and drops CHECK/index/FK before the column at lines 1402-1410.
- Task 11c now validates the deferred FKs and CHECKs after the Phase 1 schema work: the task says it validates all `NOT VALID` FKs from Tasks 5, 6, 7, 8, 9, 10 and CHECKs from Tasks 8 and 10 at line 1663, declares `public $withinTransaction = false;` at lines 1667-1669, validates FK constraints at lines 1675-1690, and validates CHECK constraints at lines 1693-1703.

### r3 P1-2 - Task 11b ordering and stale Task 28a body

Mechanically resolved.

Task 11b is physically located in Phase 1 between Task 11 and Phase 2: Task 11 starts at line 1425, Task 11b starts at line 1460, Task 11c starts at line 1658, and Phase 2 starts at line 1721. The corrected files/path use `apps/api/app/Shared/Contracts/ProductVariantLookup.php`, `apps/api/app/Shared/DTOs/ProductVariantSummary.php`, and namespace `App\Shared` at lines 1462-1468 and 1522-1524.

The old broken code body is gone. There is no remaining `namespace App\Modules\Shared` code block in the plan. The only remaining Task 28a section is a redirect stub at lines 3658-3660, and it points back to Task 11b in Phase 1.

### r3 P1-3 - `StockAlertReportService`

Mechanically resolved.

Task 27b now uses the real method name/signature `lowStockAcrossLocations(array $companyIds, array $locationIds, int $thresholdPct): array` at lines 3545-3551 and again in the snippet at lines 3553-3559. The threshold ratio math is preserved at line 3565, the locations join is preserved at lines 3567-3570, company/location filters and threshold filtering are preserved at lines 3571-3574, and the selected fields include `product_id`, `product_name`, `variant_id`, `variant_name_suffix`, `location_id`, `location_name`, `quantity`, and `min_quantity` at lines 3575-3582.

The `StockAlertData` construction keeps the real existing fields plus the two new trailing variant fields: `product_id`, `product_name`, `location_id`, `location_name`, `quantity`, `min_quantity`, `threshold_pct`, `severity`, `variant_id`, and `variant_name_suffix` at lines 3586-3596. The DTO note explicitly says the new fields are trailing optional fields and the existing fields stay unchanged at line 3601. The plan also explicitly preserves the `severity()` helper, threshold-ratio math, and locations join at line 3603.

### r3 P1-4 - Task 17 README pricing order

Mechanically resolved.

Task 17's README snippet now matches spec Section 9.2. It labels the list canonical and locked at lines 2758-2764, then orders pricing as:

1. Variant `price_override` at line 2766.
2. Partner price list, variant-specific at line 2767.
3. Partner price list, variant-agnostic at line 2768.
4. Default price list, variant-specific at line 2769.
5. Default price list, variant-agnostic at line 2770.
6. Product `sale_price` at line 2771.

The snippet also states that older text making `price_override` a fallback is superseded at line 2773.

## r3 P2 verification

### r3 P2-1 - Task 16b tests

Mechanically resolved.

The top Task 16b tests now create `tenantId` and `movementId` at lines 2379-2380, insert a parent `stock_movements` row at lines 2386-2390, and call `consumeBatchesAtomically` with named `tenantId` and `movementId` args at lines 2392-2398. The shortfall assertion is the decimal string `'0'` at line 2402.

The shortfall test repeats the same named-arg fix: it creates `tenantId` and `movementId` at lines 2409-2410, inserts the movement row at lines 2416-2419, and passes `tenantId` plus `movementId` to `consumeBatchesAtomically` at lines 2422-2428.

### r3 P2-2 - Spec Section 5.8

Mechanically resolved.

The stale "No new migrations" statement is gone from spec Section 5.8. The section now states the channel layer is only partially pre-positioned and that the broken NULL unique is fixed by the Section 4.4 partial-unique replacement at spec lines 448-451. The corresponding plan migration exists as Task 9b, with `public $withinTransaction = false;` at plan lines 1298-1300, concurrent partial unique indexes at lines 1306-1311, and the old constraint drop at line 1313.

### r3 P2-3 - Task 19 Step 5

Mechanically resolved.

`SalesOrderConfirmedV2` is now unconditional and listed in the Task 19 file list at plan line 2861. Step 4 also unconditionally says `SalesOrderConfirmed` dual-dispatches `SalesOrderConfirmedV2` because the payload is serialized at lines 2961-2966. Step 5 removes the conditional "If lines are serialized" decision and says the payload shape has already been verified, so the V2 event is required at line 2970.

## NEW issues

### P3 - Plan summary still says "NEW Task 28b" for the shared lookup contract

New polish artifact. The actual task is correctly moved to Task 11b at line 1460, and the old Task 28a body is correctly replaced by a redirect at lines 3658-3660. However, the top summary still says "NEW Task 28b" at line 22. This is harmless for implementation because the executable task body is correct, but it should be renamed to "Task 11b" to avoid reviewer churn.

## Files read

- `docs/superpowers/reviews/2026-05-28-t2-variants-codex-r3.md`
- `docs/superpowers/specs/2026-05-28-t2-product-variants.md`
- `docs/superpowers/plans/2026-05-28-t2-product-variants-impl-plan.md`
