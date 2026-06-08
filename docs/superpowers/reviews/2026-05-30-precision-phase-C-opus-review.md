# Precision & Scale-Drift Remediation — Phase 3 (Service bcmath Sweep) — Opus Adversarial Review

**Branch:** `feat/precision-phase-c` (worktree `apps/erp.precision-phase-c`)
**Diff:** `git diff origin/dev...HEAD` — 3 commits, 44 files, +3613/-322
**Date:** 2026-05-30
**Reviewer:** Opus 4.8 (adversarial)

Scope reviewed: every production-code change + the gold-standard test assertions,
the two new shared helpers (`QuantityScale`, `RoundsVat`), and cross-cutting
risks (queue/console scale resolution, fiscal hash impact, COGS valuation).

---

## Summary

The sweep is high quality and mostly mechanical-correct. bcmath intermediates at
`scale+1` (cart/tax) and `scale+4` (WAC/landed) are applied consistently, rounded
once at the boundary, and the `RoundsVat` trait genuinely unifies the two receipt
paths (fixing a real pre-existing return-path drift). The regression tests are
real, not tautological — several deliberately go red against the old float path.

There is **one P1**: a queued loyalty listener now resolves currency scale with
no `CompanyContext` bound and will throw `UnboundCompanyContextException` in the
queue worker — caught and swallowed by the listener, so loyalty points silently
stop being awarded. There are also two genuine semantic-correctness questions
(WAC/COGS truncation; percentage-fields scaled by currency scale) that need an
owner decision, plus minor robustness/scope nits.

---

## BLOCKER

None.

---

## P1

### P1-1 — Queued loyalty listener will throw `UnboundCompanyContextException`; points silently stop being awarded
**`app/Modules/Loyalty/Domain/Services/PointEarningService.php`** (every `$this->scaleResolver->getScale()` callsite, e.g. lines ~79, ~94, ~200, ~225, ~245, ~262, ~287, ~314, ~330, ~362, ~381)
**`app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:20`** (`implements ShouldQueue`)

`PointEarningService` was pure float before this PR and resolved no scale. It now
calls `getScale()` **with no currency code**, which requires a bound
`CompanyContext`. `CompanyContext` is a process singleton bound **only** by
`CompanyContextMiddleware` (HTTP), `TenantScopedCommand`, and one console command
(`app/Providers/AppServiceProvider.php:56`; bind sites grep-confirmed). The
loyalty earning listener runs **on the queue** (`ShouldQueue`), where the worker
process has no `CompanyContext` bound. So `getScale()` throws
`UnboundCompanyContextException`.

The listener wraps `earnPoints()` in `try { … } catch (\Throwable $e) { Log::error(…) }`
(`EarnPointsOnReceiptCompleted.php:86-100`), so this does **not** crash — it is
worse: points are **silently not awarded**, surfacing only as an error log line.
This is a functional regression introduced by this PR.

**Fix (pick one):**
- Resolve scale with an explicit currency in `PointEarningService` (the receipt /
  rule carries a currency; pass it: `getScale($currency)`), OR
- Use `getScaleSafe(null, 3)` inside `PointEarningService` (the resolver exposes
  exactly this escape hatch for "intentionally outside request context (queued
  jobs, console commands)"), OR
- Bind `CompanyContext` in the listener before calling `earnPoints()` (the event
  already carries `tenantId`/`customerId`; resolve the company and
  `setCompanyId()`).

Given the final result is re-laundered to float through `PointsAmount::fromNumeric((float) …)`
anyway, `getScaleSafe(null, 3)` is the lowest-risk fix. Add a queued-listener
regression test (run the listener with no bound context and assert points ARE
awarded).

---

## P2

### P2-1 — WAC / COGS now silently TRUNCATE where they previously rounded half-up (owner policy decision)
**`app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`** (`recordPurchase`, `recordReturn`, `calculateNewWAC` — all boundary via `CurrencyScale::bcformat(bcdiv(...), scale())`)
**`app/Modules/Inventory/Application/Services/LandedCostService.php`** (`landedUnitCost`, `allocateShare`)

`CurrencyScale::bcformat()` is `bcadd($str,'0',$scale)` = **truncation toward
zero** (`CurrencyScale.php`). The old code used PHP `round()` (half away from
zero). The new WAC unit cost can therefore be 1 millième LOWER (e.g. exact
`0.51/1.1 = 0.463636…` → `0.463` now vs `0.464` before). This is **deliberate and
tested** — `tests/Feature/Inventory/WacBcmathTest.php` pins `0.463` and documents
that the old float path produced `0.464`.

The concern is downstream impact, not correctness of the math: WAC `unit_cost`
flows into COGS journal entries (`GeneralLedgerService::createCOGSEntry`,
line 798, consumes per-line `unit_cost`). So COGS / inventory valuation is now
systematically biased downward by up to ~½ a sub-currency-unit per unit cost
(sub-cent / sub-millième). Over many movements this is a tiny but **one-directional**
bias (truncation never rounds up), unlike half-up which is statistically neutral.

This is a policy call, not a bug. For monetary valuation feeding the GL, half-up
(banker's or arithmetic) rounding at the boundary is the more defensible
accounting convention; consistent truncation introduces a small systematic
understatement of COGS / overstatement of margin. **Recommendation:** confirm with
the accountant/owner whether silent truncation of WAC/COGS is acceptable. If not,
add a half-up boundary helper (e.g. `CurrencyScale::bcround()` using the
`QuantityScale::HALF_UP` algorithm already written in this PR) and use it for the
WAC/landed-unit-cost boundary specifically. Whatever is chosen, the convention
should be identical for WAC, landed cost, and tax so the three never disagree.

### P2-2 — Percentage fields scaled by *currency* scale in `CompanyController::updateReservationSettings`
**`app/Modules/Company/Presentation/Controllers/CompanyController.php:362,366`** (`managerOverrideThresholdPercent`)

`$scale = $this->scaleResolver->getScale($companyCurrency)` is then applied to a
**percentage** field via `CurrencyScale::bcformatStrict($percent, $scale)`. For a
0-decimal currency company (JPY/XOF/etc., scale 0), a threshold like `10.5%` would
be truncated to `10`. Percentages are not denominated in the company currency and
should not inherit its decimal scale. The old code used a fixed
`number_format(..., 2)`, which was correct for the percent field.

**Fix:** scale `managerOverrideThresholdPercent` to a fixed precision appropriate
for percentages (e.g. 2) rather than the currency scale. The monetary threshold
fields (`managerOverrideThresholdAmount`, daily caps, goodwill thresholds)
correctly use the currency scale — only the `*Percent` field is mis-scaled.

### P2-3 — `bcformatStrict` will THROW where the old `number_format((float)…)` coerced
**`app/Modules/Company/Presentation/Controllers/CompanyController.php`** (all `bcformatStrict((string) $validated[...], $scale)` callsites)

`number_format((float) $x, …)` previously coerced any input to a float (never
threw). `bcformatStrict` throws `InvalidArgumentException` on a non-numeric
string. This is only safe if `UpdateReservationSettingsRequest`/the controller's
inline validation guarantees these fields are `numeric`. I did not see the request
rules in the diff. **Action:** verify each of these fields has a `numeric`
validation rule; if any is merely `nullable|string`, a non-numeric submission now
500s instead of silently coercing. (If validation guarantees numeric, this is a
non-issue — but confirm, don't assume.)

### P2-4 — Largest-remainder allocation assumes 0-based sequential `$index`; latent fragility
**`app/Modules/Inventory/Application/Services/LandedCostService.php`** (`allocateShare`: `if ($index >= $lastIndex)`; callers compute `$lastIndex = $lines->count() - 1` and pass the `foreach` key as `$index`)

The "final line absorbs the remainder" logic depends on the `foreach ($lines as
$index => $line)` key being a 0-based contiguous integer matching
`$lines->count() - 1`. Today `$purchaseOrder->lines` lazy-loads a fresh
`HasMany->orderBy('line_number')->get()` collection, which is list-keyed (0..n-1),
so it works. But it is fragile: if a future caller passes an eager-loaded,
`keyBy()`-ed, or `filter()`-ed collection, the last line never matches
`$index >= $lastIndex` and the remainder is silently dropped (allocation sum no
longer equals input — the exact invariant this design exists to guarantee).

**Fix:** make it key-independent — iterate `$lines->values()` (force re-index), or
track the last element via `$lines->last()` identity / a boolean "is last
iteration" flag, instead of comparing integer keys.

### P2-5 — Zero-`line_total` final line absorbs the entire remainder (untested edge)
**`app/Modules/Inventory/Application/Services/LandedCostService.php`** (`allocateShare` final-line branch + `landedUnitCost`)

If the last PO line has `line_total = 0` it still absorbs the running remainder
(every earlier line allocates 0 because proportion against a positive subtotal
rounds the zero-value line to 0; the remainder accumulates and lands entirely on
the last line). Its `landed_unit_cost = (0 + allocatedCost + tax) / qty` would
then be inflated by costs that arguably belong to value-bearing lines. The 30-line
test (`LandedCostBcmathTest`) uses only positive line totals and does not cover
this. Low real-world likelihood (a zero-value purchase line is unusual), but worth
a guard or at least a documented test asserting the chosen behavior.

---

## NIT

### NIT-1 — `RoundsVat` still launders through float (`round((float) $raw, …)`)
**`app/Modules/POS/Application/Concerns/RoundsVat.php`**: `CurrencyScale::bcformat((string) round((float) $raw, $scale), $scale)`. This is **intentional** — it
reproduces the creation path's old `roundVat` byte-for-byte (so go-forward
creation receipt hashes are unchanged) and matches PostgreSQL `round()`
half-away-from-zero, which `bcformat`'s truncation cannot do. Confirmed the trait
is identical to the removed `ReceiptCreationService::roundVat` (same `$scale + 4`,
same float round); the return path's previously-different full-float
implementation is correctly replaced. Flagging only because Phase 11.1 intends to
forbid `(float)` on decimal values — this callsite will need a bcmath half-away
helper then (the `QuantityScale::HALF_UP` algorithm in this PR is the natural
basis). No change needed now.

### NIT-2 — `PointEarningService` bcmath is partly cosmetic (PointsAmount is float-backed)
Every result returns `PointsAmount::fromNumeric((float) CurrencyScale::bcformat(…))`,
re-laundering the bcmath result to float at the boundary. The genuine improvement
is the `bccomp` threshold comparisons (no float compare). The arithmetic precision
gain is mostly notional until `PointsAmount` itself is bcmath/string-backed
(a separate, downstream refactor — out of Phase 3 scope). The
`number_format((float)$x, scale+4, …)` scientific-notation guards are appropriate.

### NIT-3 — `InvoiceService` subscription path + `recalculateTotals` left on float (out of scope)
`createManualInvoice` was correctly converted to bcmath at `interScale = scale+1`,
which matches the `decimal:3` invoice columns (verified `Invoice`/`InvoiceItem`
casts) — internally consistent, no under-rounding vs the column. But
`createSubscriptionInvoice` (`InvoiceService.php` ~line 44-50: `$price * ($taxRate/100)`)
and `Invoice::recalculateTotals` (~line 214-217: `$subtotal + $taxAmount`) remain
full float. The task scoped this to "InvoiceService(manual)", so this is a correct
scope boundary, not a defect — noting it so the subscription/recalc paths are
tracked for a later phase.

### NIT-4 — `PayrollExportController` hardcodes scale 3 / 6, ignores the resolver
**`app/Modules/Workshop/Technician/Presentation/Controllers/PayrollExportController.php`**: the multiply-first conversion `bcdiv(bcmul($costRate, $minutesStr, 6), '60', 6)`
then `bcformat(…, 3)` is mathematically correct and the multiply-first ordering is
a real fix. But it hardcodes scale 3 even though `$profile->currency` is emitted in
the CSV. Inconsistent with the resolver-based approach used everywhere else.
Harmless (3 is the global max scale), but for consistency consider resolving from
`$profile->currency`.

### NIT-5 — `DocumentLine` `non_recoverable_tax` cast is correct
Verified `non_recoverable_tax` column is `decimal(15,3)`
(`2026_01_02_160000_add_non_recoverable_tax_to_document_lines.php`), so the new
`'non_recoverable_tax' => 'decimal:3'` cast + `@property numeric-string|null`
match the column exactly. Adding the cast normalizes reads to a 3-dp string;
previously-stored values were already 3-dp, so no serialization behavior change.
No action.

---

## Verification of the specific concerns raised

1. **WAC/Landed truncation vs round:** CONFIRMED real and tested (P2-1). Truncation
   is deliberate; needs owner sign-off because it biases COGS one-directionally.
   Largest-remainder allocation is correct and sum-exact (30-line test pins it);
   no double-counting (every non-final line's allocation is subtracted from
   `remaining` before the final line absorbs it). See P2-4/P2-5 for the index and
   zero-line edges.
2. **EUR scale 3→2 in TaxCalculationService:** No fiscal-hash risk. The POS fiscal
   chain hashes device-supplied `canonical_bytes`
   (`HashChainIntegrityProvider`/`OutboxIngestor`), not server-recomputed document
   tax strings. `TaxCalculationService` feeds invoice/quote/order **accounting/display**
   totals only. EUR now stores `'20.00'` vs `'20.000'` — numerically identical,
   no hash/server-vs-device divergence. TND stays scale 3. The modified
   `TaxCalculationTest`/`...ServiceTest`/`DocumentTotalsCalculatorTest` changes are
   legitimate currency-correctness updates (EUR 2-dp), not masked regressions.
3. **PointEarningService `number_format((float)$x, …)`:** The float-launder is the
   scientific-notation guard before bcmath — acceptable. The larger issue is the
   queue context (P1-1), not the format call.
4. **`RoundsVat` trait:** CONFIRMED reproduces creation's `roundVat` exactly; both
   services use it (`use RoundsVat`, each defines `private function scale(): int`
   matching the trait's `abstract private` — valid in PHP 8.4, confirmed runtime).
   Creation path behavior unchanged (hashes stable); return path correctly fixed
   to match. `ReturnVatSymmetryTest` pins 100 random pairs + half-away boundary
   cases — real test.
5. **Resolver injection / queue throw:** All production callsites use constructor
   injection (no `app()` in production code; `app()` appears only in tests). The
   one queue/console exposure that throws is PointEarningService (P1-1).
   VendorRefundService correctly uses `getScale($currency)` (context-free).
   AgedReceivables / UninvoicedDeliveryNote / BatchWriteOff / RefundService use
   bare `getScale()` but are reached only via HTTP controllers (grep-confirmed:
   BatchWriteOff only from `BatchController`; `DailyExpiryCheck` job does NOT call
   it; Aged/Uninvoiced only from `ReportsController`s) — context always bound.
6. **Intermediate precision:** Consistent. Cart/tax use `scale+1`, WAC/landed use
   `scale+4`, rounded once at the boundary. No repeated mid-loop rounding.
   (Minor asymmetry noted: `TaxCalculationService::calculateSubtotal` rounds each
   line to the boundary to stay coherent with `DocumentTotalsCalculator`, while the
   per-rate tax accumulator stays at `scale+1` until a single final round — this is
   intentional and documented in-code; not a defect.)
7. **DocumentLine cast:** Correct — see NIT-5.
8. **Gold-standard tests:** Real, not tautological. `WacBcmathTest` and
   `LandedCostBcmathTest` deliberately assert the truncated values (`0.463`,
   `0.272`, `33.333`) that go red against the old float path; `ReturnVatSymmetryTest`
   reflection-invokes both services over 100 pairs; `LandedCostBcmathTest` pins the
   sum-exact invariant across 30 lines. The modified existing tax tests are
   legitimate EUR-scale-2 corrections.

---

## VERDICT: REQUEST-CHANGES

One P1 (queued loyalty listener silently stops awarding points — P1-1) must be
fixed before merge. Additionally, P2-1 (WAC/COGS truncation policy) and P2-2
(percentage scaled by currency scale) need resolution, and P2-3 (bcformatStrict
throw vs validation) verified. The remaining items are robustness nits. The core
bcmath sweep is sound and the test discipline is strong; once P1-1 is fixed and
the two P2 policy items are addressed/confirmed, this is mergeable.

---

## Orchestrator reconciliation (2026-05-30)

**Opus findings — resolved on `feat/precision-phase-c`:**
- **P1-1 (queued loyalty listener throws → points silently dropped):** FIXED. `PointEarningService` now resolves the scale via the event's currency (added `currency` to `EarnPointsOnReceiptCompleted`'s payload) and falls back to `getScaleSafe($currency, 3)` so a queue/console invocation never throws. Regression tests run the earning path with an UNBOUND CompanyContext and assert points are awarded.
- **P2-2 (percentage scaled by currency scale):** FIXED. `manager_override_threshold_percent` now formats at a fixed scale 2 (was currency scale — would truncate `10.5`→`10` for a 0-decimal currency); the 5 monetary reservation fields keep the currency scale. JPY-company test added.
- **P2-3 (bcformatStrict on non-numeric → 500):** Confirmed the request validates these fields `numeric` (percent also `max:100`); added 422 (not 500) regression tests.
- **P2-4 (largest-remainder index fragility):** FIXED. All three allocation paths iterate `->values()`; keyed-collection test reconciles sum===input.
- **P2-5 (zero-base final line absorbs remainder):** FIXED. Residue now lands on the last NON-ZERO-base line; zero-base trailing line test added.
- **P2-1 (WAC/COGS truncation vs half-up) — KEPT, FLAGGED FOR OWNER.** The plan (tasks 3.8/3.9) explicitly prescribes `CurrencyScale::bcformat(..., scale())` at the boundary, which TRUNCATES. This introduces a ≤1-millième downward bias on WAC unit cost (and thus COGS). This is deterministic, tested, and consistent with the project's bcformat write-boundary convention — but it is an accounting-policy choice. **Owner/accountant sign-off requested**: if half-up is preferred for COGS, switch the WAC/landed boundary to a bcmath half-up round (one-line change). Not changed unilaterally because the plan is explicit.

**Codex adversarial review (fiscal files):**
- Q1 RoundsVat reproduces old creation VAT EXACTLY — CLEAN. Q2 TaxCalc does not feed a fiscal canonical payload/hash (device-authored `canonical_bytes`) — CLEAN. Q3 stock-decrement touches only stock tables, not the hash — CLEAN. Q4 WAC/landed feed only COGS GL / valuation, not a fiscal document hash — CLEAN.
- **Q5 (RoundsVat `round((float)$raw, $scale)` is a float launder): REJECTED as a change.** This line is the EXACT pre-existing creation-path VAT rounding; the trait extraction preserved it byte-for-byte specifically so go-forward receipt hashes stay stable and the server matches the POS device's rounding (device-authority). Switching to pure-bcmath half-up here would change VAT results for edge inputs and risk server↔device hash divergence — the opposite of safe. Deferred to Phase 11.1 as a COORDINATED server+device rounding migration, not a unilateral server change. Documented; Opus independently flagged it as intentional PG-parity.

Verification after fixes: affected Loyalty/Company/Inventory tests green; pint + PHPStan L8 clean on all changed files; full group-C suite checkpoint pending.

**Final verdict: APPROVE** (P1 closed; P2-2/3/4/5 closed; P2-1 kept-per-plan + owner flag; Codex Q5 rejected with fiscal rationale).
