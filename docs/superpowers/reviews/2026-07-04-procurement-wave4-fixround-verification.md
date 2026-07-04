# Procurement Wave 4 — Fix-Round Closure Verification

Date: 2026-07-04
Reviewer: inventory-costing-reviewer (closure verification, not a fresh review)
Scope: UNCOMMITTED working tree in `apps/erp.procurement-v2`. Verifying the FIX ROUND
against the two source reviews (W4T-1..6, W4I-1..5). Every claim below cites a file:line
I read. No full suite run (laptop-crash rule); test-existence and body verified by read,
GREEN evidence taken from `docs/sessions/TASK-LOG-wave4.md` FIX ROUND.

## VERDICT: CLOSED

All 10 actionable findings are fixed in code with proof tests that exercise the real
failure mode. W4T-6 was adjudicated NO-ACTION (documented). No regressions surfaced in read.

## Per-finding verification

### W4T-1 [Critical] — PPV backfill migration — CLOSED
`database/migrations/tenant/2026_07_04_120000_backfill_purchase_price_variance_accounts.php`
mirrors the 2026_03_24_200000 pattern: chunked per-company (`:28`), idempotent short-circuit
via `Account::findByPurpose(...) !== null` (`:91`), maps `system_purpose` (`:117`), per-locale
parents 65/75 vs 6000/7000 (`:43-44`), collision-bump within 658x/758x (`availableCode` `:127-142`,
starts at the preferred code's last digit and walks to 9), `is_system=true` (`:119`), parent
resolved by code (`:104-107`). Proof `PpvChartSeedTest::ppv_backfill_migration_restores_existing_tenant_accounts_and_case_a_posts`
(`:92-167`) simulates a pre-change tenant (DELETEs both PPV rows `:96-102`), runs `up()` TWICE
(`:107-108`, idempotency), asserts exactly one row each with codes 6585/7585 (`:113-122`), then
posts Case A through `createSupplierInvoiceGrIrClearingEntry` (`:157-164`) which resolves both PPV
accounts UNCONDITIONALLY (`GeneralLedgerService.php:1239-1240`) — without the backfill this throws
`ModelNotFoundException`; the successful post IS the assertion. The trailing `assertTrue(true)`
(`:166`) is stylistically weak but the failure mode is genuinely exercised (absence of throw).

### W4T-2 [Important] — COA seeder idempotency — CLOSED
`TunisiaChartOfAccountsSeeder::run` now skips-if-exists by `(company_id, code)` (`:40-48`) AND by
`(company_id, system_purpose)` (`:52-61`) before the raw insert (`:66`), eliminating the unique-
constraint violation on re-seed. Fix is broad (all accounts, not just PPV) — strictly safer.
France/Generic seeders carry the same guard (fix log W4T-2; verified TN in full). Proof
`ppv_seeders_are_idempotent_when_ppv_accounts_already_exist` (`:74-90`) calls `seedForCompany`
twice and asserts exactly 1 PPV expense + 1 income per locale (TN/FR/US data provider).

### W4T-3 [Minor] — WAC==GL invariant reads real WAC — CLOSED
`SupplierInvoicePpvGlTest::wacInventoryValueFromDatabase` (`:274-284`) now reads stored
`products.cost_price` and `SUM(stock_levels.quantity)` from the DB and `bcmul`s them, replacing the
former hardcoded `bcmul('100.0000','5.200',3)`. `assertWacEqualsInventoryGl` (`:260-265`) cross-checks
that against `netAccount(inventory)` (fed by the goods-receipt GR-IR entry Dr Inventory 520 at `:209-215`).
Satisfies the review's stated alternative ("read the product's stored cost_price"); still a Minor
(does not run `WeightedAverageCostService::recordPurchase`).

### W4T-4 [Minor] — Mixed non-rec-VAT + PPV fully asserted — CLOSED
`non_recoverable_vat_still_capitalizes_to_inventory_while_price_delta_routes_to_ppv` (`:175-187`)
now asserts every leg (grir 520, vat 95, ppvIncome 20, inventory 5.000, payable 600), 408 net-zero
(`netAccount(grir)==0.000` `:185`), and pins the WAC divergence via
`assertInventoryGlExceedsWacBy('5.000')` (`:186`, `:267-272`) — inventory GL exceeds WAC by exactly
the non-recoverable VAT. Matches the review's requested guarantees.

### W4T-5 [Minor] — Inventory-leg comments corrected — CLOSED
`GeneralLedgerService.php:1297` ("Inventory is untouched"), `:1321` ("only non-recoverable VAT"),
`:1330`/`:1340` ("Inventory non-recoverable VAT") no longer claim the leg carries "sub-minor rounding".

### W4T-6 [Minor] — cross-module Eloquent import — NO-ACTION (adjudicated)
`SupplierInvoicePostingService.php:12` still imports `Inventory\Domain\GoodsReceiptLine`; consistent
with the pre-existing pattern, out of GL focus. Adjudicated no-action per fix log.

### W4I-1 [Important] — multi-receipt 408 divergence guard restored, fail-closed — CLOSED
`SupplierInvoicePostingService.php:135-177`: the guard is now UNCONDITIONAL on
`poLine->accrual_unit_cost !== null` (no longer skipped for overridden lines). It pulls the DISTINCT
receipt accrual bases from `goods_receipt_lines` (`receiptAccrualBasesForPoLine` `:280-294`,
dedup by normalized value, `received_qty > 0`). count > 1 THROWS `INTERIM_408_ACCRUAL_BASIS_DIVERGENCE`
(`:140-149`); single basis diverging from the compat basis also throws (`:152-162`); zero receipt bases
fall back to the original B3 landed-vs-compat guard (`:163-176`). Marked interim-for-Wave-5 in the
throw messages (`:144`, `:156`) and comment (`:180-181`). Proof (two DIFFERENT-priced receipts):
`SupplierInvoiceGlTest::test_wave4_interim_override_guard_rejects_multi_receipt_divergent_accrual_bases`
(`:1020-1076`) creates two GR lines on ONE po_line at 5.200 and 5.400 (`:1047-1061`) and asserts the
DomainException + `INTERIM_408_ACCRUAL_BASIS_DIVERGENCE` (`:1072-1073`). Single-receipt override still
posts: `test_wave4_override_clears_408_at_accrual_unit_cost_before_landed_unit_cost` (`:970-1018`) —
one GR line at 5.200 matching the PO-line compat basis, posts, `net408()==0.000` (`:1016`).

### W4I-2 [Important] — negative received price rejected BOTH layers — CLOSED
FormRequest: `ReceiveGoodsRequest.php:36` regex is now `/^\d+(\.\d{1,3})?$/` — leading `-?` removed,
negatives rejected at validation. Service: `GoodsReceiptService.php:243-245` throws when
`bccomp(rawReceivedUnitPrice, '0', $priceScale) <= 0` (covers negative AND zero), before
`recordPurchase`. Converter/`receiveAll` cannot slip a negative through — both pass `[]`/null for
prices (verified `PurchaseOrderController.php:706` receiveAll(no prices); converter passes `[]` per
source review §Verified). Proofs: `ReceiveGoodsRequestTest::receive_rejects_negative_received_unit_prices`
(`:180-194`, FormRequest layer, `-5.200`) and
`GoodsReceiptPriceOverrideTest::service_rejects_non_positive_received_unit_price_overrides`
(`:268-287`, service layer, `0.000` → DomainException "greater than zero").

### W4I-3 [Minor] — freight base selection avoids double-count — CLOSED
`GoodsReceiptService.php:248-252`: when a batch freight pool exists (`$hasBatchFreightPool` `:173`),
every paid line uses `received_unit_price ?? line->unit_price` as the base and the allocator owns 100%
of freight; the old fallback to freight-baked `landed_unit_cost` for pool participants is gone. Proof
`ReceiptBatchAllocationTest::zero_price_lines_get_zero_share_but_pool_is_absorbed_by_positive_value_lines`
(`:164-180`) now uses freight-BAKED fixtures decoupled from unit_price (zero line
`unit_price 0 / landed 0.900000`, pos line `unit_price 5 / landed 5.600000` `:167-168`) and asserts
zero-line landed `0.000000` and pos-line landed `6.500000` (`:178-179`) — i.e., the pool (15) lands
entirely on the positive-value line at the unit_price base, not double-counting the baked 6.

### W4I-4 [Minor] — injected CurrencyScaleResolverInterface, PO-currency scale — CLOSED
`GoodsReceiptService.php:45` constructor-injects `CurrencyScaleResolverInterface`; `:172` sets
`$priceScale = $this->scaleResolver->getScale((string)($purchaseOrder->currency ?? 'TND'))` (explicit
currency → queue/console safe), used for both the positivity guard (`:243`) and the received-price
round (`:246`). No literal `3`. Proof
`GoodsReceiptPriceOverrideTest::received_price_override_rounds_by_purchase_order_currency_scale`
(`:239-266`): USD PO, input `5.205` → stored `5.210` (`:264`) proving HALF-UP round at USD scale 2,
NOT literal scale 3 (which would have kept 5.205).

### W4I-5 [Minor] — price-only submission returns 422 — CLOSED
`ReceiveGoodsRequest.php:27` adds `quantities => required_with:received_unit_prices`, so a price-only
payload 422s before reaching the controller's `receiveAll` fallthrough
(`PurchaseOrderController.php:704-706`). Proof
`ReceiveGoodsRequestTest::receive_rejects_received_unit_prices_without_quantities` (`:196-209`) asserts
a validation error on `quantities`.

## Regression scan (read-level)
- W4I-1 guard change does not break the real single-receipt override flow: PO-line
  `accrual_unit_cost` (`GoodsReceiptService.php:352-353`) and GR-line `accrual_unit_cost`
  (`:376`) are both the same `$landedUnitCost`, normalized to 6dp on both sides → bccomp==0 → posts.
- Legacy receipts with no GR-line accrual basis fall to the original B3 landed-vs-compat guard
  (`SupplierInvoicePostingService.php:163-176`) — unchanged behavior.
- Migration re-run safe: `ensurePpvAccount` short-circuits on existing purpose (`:91`); test runs
  `up()` twice with no duplicate/violation.
- No new float on money/quantity introduced in any read file (bcmath on strings throughout;
  `getScale` calls pass explicit currency).
- Fix-round GREEN evidence (TASK-LOG `:107-119`): Wave-4 suites 44/289, regression list 123/663,
  PHPStan `[OK]`, Pint pass — not independently re-run here (laptop-crash rule).

## One-line
Nothing to fix before merge — all 10 findings CLOSED with failure-mode-exercising proofs;
only residual style nit is the W4T-1 test's trailing `assertTrue(true)` (real assertion is
the successful post), non-blocking.
