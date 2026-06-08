# Adversarial Review — WAC/COGS 6-dp at-rest precision + HALF-UP at posting boundary

**Branch:** `feat/precision-wac-half-up`
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.precision-phase-c`
**Reviewer:** Opus 4.8 (adversarial, accounting-correctness focus)
**Date:** 2026-05-30

## Scope reviewed

`CurrencyScale::bcround()`, `WeightedAverageCostService`, `LandedCostService`,
`GeneralLedgerService::createCOGSEntry`, `PostCOGSOnInvoice`, `MarginService`,
model casts (Product / StockMovement / DocumentLine), the scale-6 widening
migration, and all new/changed tests. Verified by reading the real code,
running the changed + adjacent test suites (84 + 27 tests green), PHPStan L8
on all changed files (clean), and standalone bcmath edge-case probes.

---

## Findings

### Point 1 — GL COGS entry balances (debit == credit)
**PASS — no defect.** `createCOGSEntry` accumulates `quantity × unit_cost` at
`working = scale + 6`, rounds the **total** exactly once via
`CurrencyScale::bcround($totalCOGSPrecise, $scale)`, and writes the **same**
`$totalCOGS` string to both the debit (COGS) and credit (Inventory) legs
(GeneralLedgerService.php:824-829, 864, 876). Balance holds by construction —
half-up cannot desync the legs because there is only one rounded value.
`test_cogs_rounds_6dp_wac_half_up_to_currency_scale_and_balances` and
`test_cogs_entry_balances` prove `debit == credit == 3.245` / `300.000`.
COGS is posted from exactly one path (only `PostCOGSOnInvoice` calls
`createCOGSEntry`) — no second/contra COGS posting that could round differently.

### Point 2 — `CurrencyScale::bcround` correctness
**PASS — no defect.** Verified independently in PHP 8.4 across every required
edge: `0.4995@3→0.500`, `0.4639@3→0.464`, `-0.4639@3→-0.464`, `0.5@0→1`,
`-0.5@0→-1`, `2.5@0→3`, `0.0005@3→0.001`, `1.005@2→1.01`, `-1.005@2→-1.01`,
`2.675@2→2.68` (the classic IEEE-754 trap where `round((float)2.675,2)=2.67`).
Half-away-from-zero for positives AND negatives, correct at scale 0, no float
intermediary, no off-by-one. Truncating input beyond `scale+1` before adding the
half is safe for half-up (only the digit at `scale+1` determines the boundary).
`bcpow('10', scale+1)` is never raised to power 0 (would need scale = -1), so no
divide-by-one/zero pathology. Note: PHP 8.4 ships a global `bcround()`, but this
is a **static method** `CurrencyScale::bcround()` — no symbol collision. Unit
tests (`CurrencyScaleTest::bcroundProvider`) pin all the load-bearing cases and
genuinely fail under truncation/float.

### Point 3 — No-drift claim (full-precision read path)
**PASS — no defect.** `recordPurchase` reads `$product->cost_price` (now
decimal:6) at `working` precision, blends, and persists `$newAvgCost` at
`COST_SCALE = 6` with **no** currency-scale truncation
(WeightedAverageCostService.php:147-149, 176). The next recompute reads that
6-dp value back. `PostCOGSOnInvoice::extractPhysicalProductLines` passes the
full 6-dp `cost_price` straight into `createCOGSEntry`. No read path routes
through a scale-2/3 cast or `bcformat(...,scale())`. The drift test
`test_perpetual_recompute_does_not_drift_downward_on_non_terminating_cost`
pins the WAC at exactly `0.333333` across 50 recomputes — this fails under the
old scale-3 truncation (it would erode toward `0.333000`).

### Point 4 — Migration safety + 6-dp leak to consumers
**Migration: PASS.** All 8 columns were `(12,3)` before this change (confirmed in
`2026_03_11_200000_widen_monetary_columns_to_scale_3.php`). `up()` widens 3→6 and
12→19 precision — non-destructive on PG (pads trailing zeros, no data loss,
no narrowing); SQLite no-op. `down()` reverts to `(12,3)` which correctly matches
the immediately-prior state. `journal_lines.debit/credit` stay `(15,3)` and the
COGS total is rounded to scale 3 before landing there — fits exactly.

**6-dp API leak: P2 (low impact).** `cost_price` / `last_purchase_cost` now cast
decimal:6, so two API surfaces serialize 6 dp where they previously emitted 3 dp:
- `ProductData::fromModel` → `cost_price: (string) $product->cost_price`
  (ProductData.php:56) — the typed payload behind ProductController + the source
  of the generated TS type `cost_price: string | null`.
- `PricingController` JSON response `'cost_price' => $product->cost_price`
  (PricingController.php:520).

Impact is **largely absorbed** by the frontend: `ProductForm.tsx:512`,
`PriceInputWithMargin.tsx:144` use `parseFloat(...).toFixed(decimals)` and
`ProductDetailPage.tsx:258` uses `formatAmount(...)`, all of which re-round to
currency decimals. No correctness break, no test asserts these response scales.
It is an unintended API-contract widening (a raw `cost_price` consumer now sees
`"12.345600"`). Recommend either keeping the API/DTO at currency scale via
`CurrencyScale::bcformat($product->cost_price, $scale)` at the serialization
boundary, or consciously documenting the 6-dp contract. `pos_receipt_lines.unit_cost`
is correctly left at `(15,4)` (DB rounds the 6-dp cost to 4 dp on write — no leak).

### Point 5 — `landed_unit_cost` vs `allocated_costs`
**PASS — no defect.** `allocated_costs` is reconciled at the currency `$scale`
via largest-remainder (`allocateShare` → absorber takes the running remainder),
and the shares still sum exactly to the input total at `$scale`
(LandedCostService.php:118, 191, 376-377). Storing that currency-scale value in
a widened 6-dp column is lossless. Only `landed_unit_cost` carries the internal
6-dp (LandedCostService.php:419). Reconciliation math is unchanged.

### Point 6 — Fiscal isolation
**PASS — no defect.** Nothing in the receipt hash chain changed. The fiscal
hash is computed over the device-supplied 27-key canonical SALE_RECEIPT payload
(`canonical_bytes`); `unit_cost` is NOT in that payload. The
`ReceiptCreationService` `unit_cost` is a server-side margin-analytics snapshot
written to `pos_receipt_lines.unit_cost` (the non-fiscal path) and that column is
`(15,4)`, untouched. No cost value feeds any canonical/hashed field. Grep of
Fiscal/POS hash/canonical code shows zero `unit_cost` references.

### Point 7 — `workingScale = max(scale+4, COST_SCALE+1)` for 0-dp currencies
**PASS — no defect.** JPY (scale 0) → `max(4, 7) = 7`; COGS working = `0 + 6 = 6`;
`bcround` half at scale 0 = `0.5`. No division-by-zero, no scale-0 degeneracy.

### Point 8 — Test strength
**PASS.** The no-drift, half-up, and balance tests are load-bearing, not
tautological: the 50-recompute drift test, the `0.463636` at-rest assertion, the
`0.4639 → 0.464` half-up COGS test, and the `2.675 → 2.68` IEEE-trap test all
fail under the old truncate/float behavior. `FactoryCanonicalScaleTest` (asserts
factory raw scale 3/2) is unaffected because it tests factory output, not the DB
read cast.

---

## NITs (non-blocking)
- **N1 — `getAllocationBreakdown` fallback literals** (LandedCostService.php:438-439):
  `allocated_costs ?? '0.000'` / `non_recoverable_tax ?? '0.000'` use 3-dp
  literals while populated values now come back at 6 dp from the cast — a cosmetic
  mismatch in the display breakdown. Harmless.
- **N2 — `ReturnNoteService::getOriginalCost`** (ReturnNoteService.php:210, 215)
  casts the now-6-dp `landed_unit_cost`/`cost_price` to `(float)` before feeding
  `recordReturn(float $originalCost)`. Float round-trips 6-dp values < ~1e9
  losslessly, and this is the pre-existing `recordPurchase/recordReturn(float ...)`
  API surface (already PHPStan-baselined). Not a new precision defect, but the
  float boundary on cost is worth eventually closing if these signatures move to
  numeric-string.
- **N3 — `ProductForm` save-back** (ProductForm.tsx:512): the cost edit field
  shows `toFixed(decimals)`; if a manual cost override is submitted it would write
  a 3-dp value over the 6-dp WAC. This is a manual-override path (not the
  WAC-managed path) so it is acceptable, but worth noting for the productization
  cost-override story.

---

## Verification performed
- `phpunit CurrencyScaleTest WacBcmathTest StockMovementGLIntegrationTest` → 84/84 OK
- `phpunit FactoryCanonicalScaleTest RecipeCostTest ReturnNoteServiceTest WeightedAverageCostServiceTest` → 27/27 OK
- `phpstan analyse` on all 6 changed app files → No errors (L8)
- Standalone bcmath probes of `bcround` across ~22 edge cases → all correct
- Migration prior-state and journal-column scale confirmed from migration history

---

## VERDICT

**APPROVE — with one P2 (non-blocking).**

The accounting core is correct: the COGS journal balances by construction (single
half-up rounded total on both legs), `bcround` is mathematically sound across all
edges including negatives and scale 0, the WAC no-drift claim holds because the
full 6-dp cost is read back without re-truncation, the migration is a clean
non-destructive widen, and fiscal hash isolation is intact. No BLOCKER, no P1.

The only substantive issue is **P2 (Point 4)**: `cost_price` now leaks 6 dp through
`ProductData` and `PricingController` JSON responses. Impact is low because the
frontend re-rounds via `toFixed`/`formatAmount`, but it is an unintended
API-contract change — recommend clamping the cost at the serialization boundary
with `CurrencyScale::bcformat($value, $scale)` (or explicitly accepting the 6-dp
contract). Safe to merge; address P2 as a fast follow.

---

## Orchestrator reconciliation (2026-05-30)
Opus verdict **APPROVE** — accounting core correct (GL balance by single-rounded total; `bcround` half-away-from-zero verified incl. `2.675→2.68` IEEE trap, scale-0, negatives; genuine no-drift reading full 6-dp cost; non-destructive widen; fiscal isolation intact; JPY scale-0 safe). No BLOCKER/P1.

**P2 (`cost_price` now serializes at 6 dp via ProductData/PricingController) — ACCEPTED, not changed.** Rationale: the field IS the precise internal WAC; presentation rounding is done at the frontend formatter (`toFixed`/`formatAmount`) per the NC-01 "round only at presentation" principle. TS type is already `string` (no type drift), no test fails, and the frontend re-rounds — so exposing the accurate cost is correct, not a defect. Clamping in the DTO would require a fragile currency-resolver-in-DTO. Logged as an optional cosmetic fast-follow (clamp at the Resource boundary if a 2-dp cost API contract is later desired).

NITs (allocation-breakdown 3-dp fallback literals; ReturnNoteService `(float)` cost boundary — pre-existing baselined, lossless at 6dp; ProductForm manual cost-edit could save 3dp over a 6dp WAC) — noted, non-blocking.

**Final verdict: APPROVE.**
