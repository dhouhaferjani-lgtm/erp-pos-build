# Procurement Wave 4 — Treasury/GL adversarial review (PPV split)

Scope: uncommitted working-tree diff in `apps/erp.procurement-v2`, Wave 4 Tasks 8 + 11
(spec Gap 2 §2.4.2). Focus: GL side of `createSupplierInvoiceGrIrClearingEntry`,
new `SystemAccountPurpose` cases, and the three COA seeds. All line refs verified by read.

## VERDICT: NEEDS-REVISION

The GL math, sign conventions, zero-leg omission, and the debits==credits guard are
correct and the literal Cases A/B/C are asserted leg-by-leg. The blocker is operational,
not arithmetic: the two new system-purpose accounts are resolved UNCONDITIONALLY on every
supplier-invoice posting but are only added to freshly-seeded companies — no backfill for
already-provisioned tenants. Plus test-quality gaps on the WAC==GL invariant.

## Findings

### W4T-1 [Critical] — No backfill migration for the new PPV system accounts; posting breaks for every pre-existing tenant
`GeneralLedgerService.php:1240-1241` resolves `PurchasePriceVarianceExpense` and
`PurchasePriceVarianceIncome` via `Account::findByPurposeOrFail` UNCONDITIONALLY — before
any branch, so even a perfectly matching invoice (Case A, zero price delta) hits both
lookups. The accounts are only created by the fresh-seed path (`TunisiaChartOfAccountsSeeder.php:43`
uses a raw `DB::table('accounts')->insert()`, called once at provisioning via
`ChartOfAccountsService::seedForCompany` :32-38). There is NO tenant migration adding 6585/7585
to existing tenants (`grep 6585|7585|PurchasePriceVariance database/migrations` → none).
Any tenant provisioned before this change will throw `ModelNotFoundException` on the FIRST
supplier-invoice posting → 500, GR-IR clearing aborts.
Why it matters: this is exactly the failure class the project already fixed once — see
`database/migrations/tenant/2026_03_24_200000_backfill_tunisian_payment_repositories_and_gl_purposes.php:17-26`
("not seeded during registration, causing 500 errors"). The established convention is a
chunked, idempotent per-company backfill migration for new system-purpose accounts; this
wave skips it. Mitigated only for demo tenants that get a fresh reseed.
Fix: add a `tenant/2026_07_04_..._backfill_ppv_accounts.php` migration mirroring the 2026-03-24
pattern — per company, resolve locale, insert 6585/7585 with `is_system=true` under parent
65/6000 ONLY when absent (short-circuit when present so it is idempotent and safe to re-run).

### W4T-2 [Important] — COA seeder is not idempotent for existing tenants (raw insert under a unique constraint)
`TunisiaChartOfAccountsSeeder::run` (:39-58) issues raw `DB::table('accounts')->insert()`
with a freshly generated UUID per row. `accounts` has UNIQUE `(company_id, code)` and
`(company_id, system_purpose)` (`2025_12_06_002513_add_company_id_and_system_purpose_to_accounts.php:55-86`).
Re-running the seeder on a seeded company throws a unique violation on the first row — it is
strictly fresh-seed-only. So the task's stated requirement "new accounts appear on re-seed
without duplicating" is NOT satisfiable via the seeder; it depends entirely on W4T-1's missing
backfill migration. (This is a pre-existing seeder trait, surfaced by this change's new rows.)
Fix: same as W4T-1 — the backfill migration is the supported "add to existing tenants" path;
document that the seeder is provisioning-only.

### W4T-3 [Minor] — "WAC==GL invariant" test asserts a hardcoded mirror, not the real WAC
`SupplierInvoicePpvGlTest::assertWacEqualsInventoryGl` (:217-222) computes
`bcmul('100.0000','5.200',3)` and compares to the Inventory GL net. It never creates a product,
never runs `WeightedAverageCostService`, never reads `product.cost_price × on-hand`. So the
"invariant" only verifies that the Inventory GL leg is untouched by the price delta (leg absent
in A/B/C) — a real and useful check, but weaker than the spec's stated `cost_price × on-hand == Σ Inventory GL`.
Fix: post through the real receipt→WAC path (or read the product's stored `cost_price`) so the
GL side is cross-checked against an independently-derived WAC, not a literal that restates the input.

### W4T-4 [Minor] — Mixed non-recoverable-VAT + price-delta case is under-asserted
`non_recoverable_vat_still_capitalizes_to_inventory...` (:136-144) asserts only PPV-Income,
Inventory (5.000) and Payable. It does NOT assert 408 nets to 0, does NOT assert the 408/VAT
legs, and does NOT assert WAC==GL for the mixed case (where GL Inventory = 525.000 but paid-goods
WAC = 520.000 — i.e., non-rec VAT deliberately breaks WAC==GL; that divergence should be pinned
so a future change can't silently move it). The math is correct (I traced inventoryPlug ≡
nonRecoverableVatR exactly), but the strongest mixed-case guarantees are unasserted.
Fix: add `assertLeg` on 408 (Dr 520.000) + `netAccount` 408 == 0 to the mixed case, and an
explicit comment/assert that WAC != Inventory GL by exactly the non-rec VAT.

### W4T-5 [Minor] — Comment claims Inventory absorbs "sub-minor rounding"; it no longer can
`GeneralLedgerService.php:1322`/1331/1341 comments say the Inventory leg carries
"non-recoverable VAT and sub-minor rounding". By construction `inventoryPlug = plug − priceDelta
= nonRecoverableVatR` exactly (the balance invariant at :1218-1224 forces `totalR ==` the sum of
the already-rounded parts, so no residue survives). Harmless, but the comment overstates what the
leg holds and could mislead a future maintainer into thinking rounding lands here.
Fix: drop "sub-minor rounding" from the Inventory-leg comments; it is exactly `nonRecoverableVat`.

### W4T-6 [Minor] — Cross-module direct Eloquent import (rule 6), consistent with existing pattern
`SupplierInvoicePostingService.php:12` (Procurement) now imports
`App\Modules\Inventory\Domain\GoodsReceiptLine` to run the override-existence probe (:139-142).
This is a direct cross-module Eloquent import (rule 6 wants Shared/Contracts / events / public
service). It mirrors the pre-existing `SupplierCreditNotePostingService.php:13-15`
(StockLevel/StockMovement/MovementType), so it is not a new architectural regression — noting for
consistency. Not GL-side; out of primary focus.
Fix (optional/deferred): route receipt-line reads through an Inventory public service/contract.

## Verified OK (no finding)
- Sign conventions both directions: priceDelta>0 (billed>accrued, unfavorable) → Dr PPV-Expense;
  priceDelta<0 → Cr PPV-Income with `bcmul(priceDelta,'-1')`. Traced balanced both ways
  (`GeneralLedgerService.php:1299-1320`).
- Zero-leg omission: priceDelta==0 → no PPV leg; inventoryPlug==0 → no Inventory leg. Case A
  asserts both null (`SupplierInvoicePpvGlTest.php:101-103`).
- debits==credits guard unchanged and runs after all legs, every branch
  (`GeneralLedgerService.php:1359-1372`).
- Literal Cases A/B/C asserted leg-by-leg with exact amounts (grir 520.000, VAT 98.800/95.000/102.600,
  PPV 20.000, payable 618.800/595.000/642.600) and 408 nets 0 (`SupplierInvoicePpvGlTest.php:96-134`).
- accruedHt basis still Σ invoiced_qty × accrual basis (`SupplierInvoicePostingService.php:160-161`);
  billedHt = invoice subtotal (:228). Single-basis interim window preserved; does not pre-break Wave 5.
- SystemAccountPurpose arms exhaustive: PurchasePriceVarianceIncome→Revenue,
  Expense→Expense; label() arms added (`SystemAccountPurpose.php:107-108, 163, 168`).
- COA codes 6585/7585 unique per seeder (no collision with 6580/6588/7580/7592), parents 65/75
  (TN/FR) and 6000/7000 (Generic) exist, `is_system=true`, resolvable via `findByPurposeOrFail`;
  seed test covers all three locales (`PpvChartSeedTest.php:36-60`).
- Hash-chain path unchanged: entry created Draft, source_type 'supplier_invoice', posted via
  `postSystemGeneratedEntryAndDispatchPostedEvent` with explicit `$currency`
  (`GeneralLedgerService.php:1246-1255, 1374`). No new entry type bypasses postEntry.
- Scale resolution uses explicit `$currency` from the invoice (`:1197-1198`), no no-arg getScale().

## One-line: add the tenant backfill migration for 6585/7585 (W4T-1) before merge — without it, supplier-invoice posting 500s on every non-reseeded tenant; the GL math itself is correct.
