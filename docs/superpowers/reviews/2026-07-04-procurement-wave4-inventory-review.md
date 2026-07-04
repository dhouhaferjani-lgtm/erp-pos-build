# Procurement Wave 4 (received price / audit / PPV) — inventory & costing adversarial review

Date: 2026-07-04
Reviewer: inventory-costing-reviewer (adversarial)
Scope: UNCOMMITTED working-tree diff in `apps/erp.procurement-v2` (Wave 3 committed at 0e380bef2; only new uncommitted work reviewed). Focus = inventory / WAC / batch-freight / 408 accrual side.
Method: read every touched file; traced the received-price → landed cost → WAC → 408 accrual → invoice-clearing path; audited the batch freight allocator, the FormRequest gate, and every caller of `receiveGoods`/`receiveAll`.

## VERDICT: NEEDS-REVISION

Two findings block a clean ship: one silent GL 408 residue reachable through the very feature Wave 4 adds (W4I-1), and one WAC-corruption validation hole (W4I-2). The remainder are minor. Batch freight allocation, PPV GL split, effective-cost/bonus math, audit stamping, and the permission gate are all correct and test-proven.

---

## Findings

### W4I-1 — [Important] Multi-receipt different-price leaves a silent, unguarded 408 residue; the divergence guard is disabled for overridden lines
`apps/api/app/Modules/Procurement/Application/SupplierInvoicePostingService.php:134-161`
408 is accrued PER RECEIPT at that receipt's own landed cost (`PostGrIrOnGoodsReceipt` → `GeneralLedgerService::createGoodsReceiptGrIrEntry`, amount = `unitCost × receivedQty`, `GeneralLedgerService.php:1076`, unitCost = the receipt's `$landedUnitCost`, `GoodsReceiptService.php:310/325`). But invoice clearing debits 408 at the SINGLE PO-line basis `$poLine->accrual_unit_cost` × the FULL aggregate invoice qty (`SupplierInvoicePostingService.php:158-160`). `accrual_unit_cost` is written only on the FIRST paid receipt (`GoodsReceiptService.php:343`, `if === null`), so for two partial receipts at different prices (p1 then p2), 408 accrued = r1·p1 + r2·p2 but clearing = (r1+r2)·p1 → residue r2·(p2−p1) that never zeroes.
Worse: the pre-Wave-4 safety net that used to THROW on exactly this divergence is now skipped whenever any receipt on the line has a `received_unit_price` (`:136-141`, `! $hasReceivedPriceOverride && …`). So the override feature both CREATES the divergence and REMOVES the assertion that blocked posting it. No test covers a second differently-priced receipt (`test_wave4_override_clears_408…` and the PPV suite are all single-receipt). The task's "NO 422 on second different-priced receipt (test proves it?)" — there is no 422 and no test; the invoice posts a residue.
Fix: until Wave 5 moves clearing to receipt-line grain, either (a) refuse a price-differing second receipt on an already-accrued PO line, or (b) block invoice posting when a PO line has >1 receipt with divergent accrual bases (sum the immutable `goods_receipt_lines.accrual_unit_cost × received_qty` instead of `poLine->accrual_unit_cost × qty`). Add a red→green multi-receipt test asserting `net408() === '0.000'`.

### W4I-2 — [Important] Negative `received_unit_price` passes validation and corrupts WAC
`apps/api/app/Modules/Document/Presentation/Requests/ReceiveGoodsRequest.php:36` (and `:28` for quantities)
Regex `/^-?\d+(\.\d{1,3})?$/` permits a leading `-`. A negative received price is not guarded anywhere downstream: `GoodsReceiptService.php:236-244` takes it as `$receivedUnitPrice`/`$baseUnitCost`, `landedUnitCostForReceipt()` (`:469-479`) produces a negative landed cost, and `wacService->recordPurchase(landedUnitCost: …)` (`:306-315`) writes a negative unit_cost into the running average — WAC corruption / negative inventory value. (Negative `quantities.*` is benign only because `bccomp(qty,'0')<=0` skips the line at `:177`, but a negative price on a positive qty is fully live.)
Fix: drop `-?` from `received_unit_prices.*` (goods-in prices are non-negative), or add a service-side positivity assertion before `recordPurchase`.

### W4I-3 — [Minor] Freight double-count latent trap in landed-cost base selection
`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:239-243`
`landed_unit_cost` bakes allocated costs (`LandedCostService::landedUnitCost` = `(lineTotal + allocatedCost + nonRecoverableTax)/qty`, `LandedCostService.php:341-347`). The receipt path strips that baked allocation only when a line earns a positive pool share (`batchFreightShare > 0` → base = `unit_price`); otherwise it falls back to `oldBasis` = the full `landed_unit_cost`. A received line that CONTRIBUTES `allocated_costs` to the pool (via its received fraction) but earns a ZERO share — zero received value, or a share truncated to 0 — keeps its baked allocation in `oldBasis` while the allocator hands that same money to other lines → the allocated amount is counted twice.
Not currently reachable because `LandedCostService` allocates strictly by line value, so a zero-value line has `allocated_costs = 0` and contributes nothing. The `zero_price_lines…` test masks the risk by manually setting `landed_unit_cost = unit_price` (decoupled from `allocated_costs = 9`), a state real allocation cannot produce. The invariant "allocated_costs ∝ received value" is undocumented and would break under any future weight/quantity-based landed allocation or imported data.
Fix: when the freight pool > 0, use `unit_price` (or override) as the base for ALL received lines and let `ReceiptBatchCostAllocator` own 100% of the freight; never fall back to `landed_unit_cost` (which already bakes freight) for a pool-participating receipt.

### W4I-4 — [Minor] Hardcoded currency scale `3` in the receipt price path (precision-contract drift)
`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:237` — `CurrencyScale::bcround((string) $receivedUnitPrices[…], 3)`; mirrored by the FormRequest `{1,3}` ceiling.
`GoodsReceiptService` does not inject `CurrencyScaleResolverInterface`; the received price is rounded to a literal 3 dp rather than `getScale($purchaseOrder->currency)`. Correct for TND, over-/mis-precise for any non-3-dp currency, and contrary to rule 19 (money at-rest scale must come from the injected resolver). (COST_SCALE/QUANTITY_SCALE/WORKING_SCALE consts are fine; the literal `3` is the drift.)
Fix: constructor-inject the resolver and round the received price at `getScale($poCurrency)`.

### W4I-5 — [Minor] `received_unit_prices` silently dropped when quantities/free omitted
`apps/api/app/Modules/Document/Presentation/Controllers/PurchaseOrderController.php:693-707`
If a price editor submits `received_unit_prices` with no `quantities` and no `free_quantities`, the `else` branch calls `receiveAll()`, which ignores prices entirely (`GoodsReceiptService.php:440` passes `[]`), receiving at PO price. The intended override is silently lost.
Fix: if `received_unit_prices` is present, route through `receiveGoods` (or 422 requiring explicit quantities).

---

## Verified correct (no defect)

- Accrual snapshot immutability: PO-line `accrual_unit_cost` written once under a `=== null` guard (`GoodsReceiptService.php:343`); GR-line `accrual_unit_cost` is create-only (`:367`). No rewrite path exists — LandedCostService reallocation does NOT touch either. "Interim PO-line compat write only on first paid receipt" holds.
- Bypass surfaces clean: the only callers of `receiveGoods`/`receiveAll` with user input are `PurchaseOrderController::receive` (now `ReceiveGoodsRequest`-gated; `received_unit_prices` is `prohibited` without `goods-receipt.edit-price` — proven by `receive_prohibits_received_unit_prices_without_price_edit_permission`) and `PurchaseOrderToGoodsReceiptConverter::convert`, which passes `[]`/`null` for prices and reason (`:140,143`) and reads only `received_quantities`/`actor_user_id` from options. A permissionless user cannot inject prices via `receiveAll` or the converter.
- Batch freight allocation (`ReceiptBatchCostAllocator`): pool = Σ `allocated_costs × (receivedQty/orderedQty)`; reallocated by received value; Σ shares == pool EXACTLY (`ProportionalMoneyAllocator` absorber = last positive base takes the running remainder); free-only lines excluded (qty≤0 `continue`, freight on paid value only); zero-price → zero received value → zero share; partial-receipt proportionality exact. `partial_receipt_freight_pool_is_reallocated_by_received_value` asserts Σ(landed−base)·qty == 30 exactly.
- WAC integrity: paid movement carries LANDED cost (base + freight), not raw received price (`:310,325`); free movement carries `'0'` (`:271,286`); paid recorded LAST (free block `:266-302` precedes paid block `:304-348`) so `last_purchase_cost` = paid delivered price (proven `= '5.200000'`); bonus blend `effective_unit_cost = (paidQty·landed)/(paid+free)` = `4.727273` half-up, exact per Task 9.
- landed_unit_cost derivation: `(receivedQty·baseUnitCost + batchFreightShare)/receivedQty` at WORKING_SCALE, ONE `bcround` to COST_SCALE 6 (`:475-478`). No mid-stream truncation to currency scale.
- PPV GL split: invoice-vs-accrual price delta → 6585 (unfavorable) / 7585 (favorable); non-recoverable VAT + sub-minor rounding stay on Inventory; `SupplierInvoicePpvGlTest` proves cases A/B/C + non-recoverable VAT split AND that Inventory GL net == WAC value (520). `PpvChartSeedTest` seeds all three charts (TN/FR/US) with the system purposes and correct account types.
- Audit columns: `price_override_old_basis` = the REPLACED basis (`$oldBasis` from `landed_unit_cost ?? unit_price`), not the new price; `_by`/`_at`/`_reason` stamped only when `hasReceivedPriceOverride` (`:376-379`); actor threaded from both the controller (`$user->id`) and the converter (`$actorUserId`).
- No new float on money/quantity in any Wave-4 code (allocator, service additions, allocator helper all bcmath on strings; the only `(float)` is the pre-existing display-only percentage in `getReceiptStatus`).
- `price_entry_mode='total'` PO line: override entered per-unit stays coherent — `total_price_entry_mode_still_accepts_per_unit_received_price_override` proves accrual = 5.200000.
- Permission seeding: `goods-receipt.edit-price` granted to admin (all) + manager (`RolesAndPermissionsSeeder.php:428`), withheld from cashier/operator — proven by `price_edit_permission_is_seeded_to_admin_and_manager_only`.
- No weakened/deleted existing assertions: `SupplierInvoiceGlTest` price-variance tests were re-pointed from the old Inventory-plug expectation to PPV legs AND additionally assert `assertNull(legOn(inventory))` — strictly stronger. Wave-3 test additions (resume-dedup, per-company GRN) strengthen coverage.

## One-line: fix W4I-1 (guard or receipt-grain-clear the multi-receipt 408 residue) and W4I-2 (reject negative received prices) before merge; W4I-3/4/5 are minor and can follow.
