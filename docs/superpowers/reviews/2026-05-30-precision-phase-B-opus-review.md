# Precision & Scale-Drift Remediation — Phase 4 (ingress validator hardening) — Opus Adversarial Review

**Branch:** `feat/precision-phase-b`
**Diff:** `git diff origin/dev...HEAD`
**Reviewer:** Opus 4.8 (adversarial)
**Date:** 2026-05-30

## Summary

The bulk of the sweep is correct and applies the LOCKED `numeric` + anchored `regex` pattern consistently, with scale-by-destination-column (money=3, qty=4, percent/tax=2, voucher=5) matching migrations in the cases I spot-checked (workshop `tax_rate` decimal(6,3)→3dp distinct from service/product decimal(5,2)→2dp; withholding `rate`/`withholding_rate` decimal(5,4)→4dp; inventory qty post-widen decimal(15,4)→4dp). The MarginService bcmath rewrite is arithmetically sound and the half-up rounding is correct for both signs.

However there are **two genuine correctness defects** (one scale mismatch that rejects valid Tunisian withholding rates, one validation/consumer mismatch that silently truncates), plus a **systemic test-design weakness** that structurally prevents this phase's tests from catching scale errors, plus several too-loose Loyalty regexes. Details below.

---

## Findings

### P1 — PaymentController `withholding_rate` regex is scale-2 but the field is a 0–1 fraction stored at decimal(5,4); rejects valid rates
**File:** `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:138`
```php
'withholding_rate' => ['nullable', 'numeric', 'min:0', 'max:1', 'regex:/^\d+(\.\d{1,2})?$/'],
```
This `withholding_rate` is a fraction in `[0,1]` (`max:1`). It flows through `WithholdingCertificateService::createFromPayment()` → `calculateWithOverride()` and lands in `withholding_certificates.withholding_rate decimal(5,4)`. A scale-2 ceiling on a 0–1 fraction means only 0.00–1.00 in 0.01 steps are accepted: a 1.5% withholding (`0.015`), 0.5% (`0.005`), 2.5% (`0.025`) — all standard Tunisian rates — are **422-rejected** before they reach the DB that has room for them.

The **sibling FormRequests for the identical 0–1 fraction use scale 4** and are correct: `CreateWithholdingRuleRequest.php:32` (`rate` → `\d{1,4}`), `UpdateWithholdingRuleRequest.php:30`, `RecordSalesWithholdingRequest.php:48` (`withholding_rate` → `\d{1,4}`). PaymentController is the outlier.

**Note the asymmetry vs `manual_rate_percentage`:** in `CreateWithholdingCertificateRequest.php:54`, `manual_rate_percentage` is a *percentage* (`max:100`) and scale-2 is correct there. PaymentController's field is a *fraction* (`max:1`), so it must be scale-4. Do not conflate the two.

**Fix:** `'regex:/^\d+(\.\d{1,4})?$/'` and update the message to "at most 4 decimal places."

---

### P1 — `ValidateCouponRequest` `items.*.quantity` loosened integer→numeric(4dp), but consumer casts `(int)` — silent truncation
**File:** `apps/api/app/Modules/Coupon/Presentation/Requests/ValidateCouponRequest.php:27`
```php
'items.*.quantity' => ['required_with:items', 'numeric', 'min:0.0001', 'regex:/^\d+(\.\d{1,4})?$/'],
```
Previously `['required_with:items','integer','min:1']`. The consumer at `apps/api/app/Modules/Coupon/Presentation/Controllers/CouponController.php:143` still does:
```php
quantity: (int) $item['quantity'],
```
A fractional quantity like `2.5` now passes ingress but is silently floored to `2` by the cast, producing a wrong qualifying-quantity for coupon evaluation. The validation no longer matches the consumer's contract.

This is a behavior regression caused by the loosening. The review prompt explicitly asked whether this change is safe — it is **not** as-is.

**Fix (pick one):**
- If coupon qualifying-quantity is genuinely fractional, change the consumer to a decimal-aware path (and confirm `CouponEvaluator`/DTO accepts a numeric-string), OR
- Keep this field `integer` (revert the loosening) since coupon-line counting is integral. The other coupon money fields (subtotal/unit_price/line_total) correctly got the money regex; only `quantity` was over-loosened.

---

### P1 — Phase-4 ingress tests are tautological: they re-declare the rule array inline, so they cannot catch a wrong scale
**Files:** all `tests/Feature/**/IngressPrecisionTest.php`, `WithholdingPrecisionTest.php`, etc.
Every test builds the rule array as a **literal inside the test method** and runs `Validator::make()` against it, instead of resolving the real FormRequest / hitting the controller:
```php
// Treasury/IngressPrecisionTest.php:79
$rules = ['withholding_rate' => ['nullable','numeric','min:0','max:1','regex:/^\d+(\.\d{1,2})?$/']];
```
The test proves "this regex string behaves as this regex string" — it passes regardless of whether the production scale is right, because the author copies the (possibly wrong) scale into the test. This is precisely the antipattern recorded in MEMORY (`feedback_frontend_api_test_antipattern`).

Concrete proof it failed to catch the P1 above: `Treasury/IngressPrecisionTest.php:77-91` **asserts `0.155` is rejected and `0.15` accepted** for `withholding_rate` — codifying the scale-2 bug as expected behavior — while `Taxation/WithholdingPrecisionTest.php:78-93` asserts the *same field name* accepts 4 decimals against decimal(5,4). The suite is internally contradictory and green.

**Fix:** At minimum, the acceptance tests should be driven through the actual FormRequest (`(new XRequest)->rules()`) or an HTTP feature test against the route, so the asserted scale is the *production* scale, not a copy. Add at least one test per module that instantiates the real Request `rules()` and asserts the boundary value derived from the **migration column scale**. Without this, the phase's safety net is illusory for exactly the error class it targets.

---

### P2 — Loyalty regexes are scale-3 against decimal(15,2) columns (too loose; defeats the ceiling)
A too-loose regex lets a 3-dp value pass ingress and then be silently truncated/rounded by the DB to 2 dp — defeating the whole point of the phase.

| Field | File:line | Regex | Column | Correct |
|---|---|---|---|---|
| `points_cost` | `CreateRewardRequest.php:32`, `UpdateRewardRequest.php:32` | `\d{1,3}` | `loyalty_rewards.points_cost decimal(15,2)` | `\d{1,2}` |
| `reward_value` (rewards) | `CreateRewardRequest.php:33`, `UpdateRewardRequest.php:33` | `\d{1,3}` | `loyalty_rewards.reward_value decimal(15,2)` | `\d{1,2}` |
| `max_discount`, `min_order_value` | `Create/UpdateRewardRequest` | `\d{1,3}` | rewards table (money, scale 2) | `\d{1,2}` (verify col) |
| `qualification_threshold` | `CreateTierRequest.php:34`, `UpdateTierRequest.php:34` | `\d{1,3}` | `loyalty_tiers.qualification_threshold decimal(15,2)` | `\d{1,2}` |
| `max_earn_per_transaction`, `max_earn_per_day` | `Create/UpdateEarningRuleRequest` | `\d{1,3}` | `earning_rules.* decimal(15,2)` | `\d{1,2}` |
| `welcome_bonus` | `EnrollMemberRequest.php:40` | `\d{1,3}` | `loyalty_*.welcome_bonus_points decimal(15,2)` | `\d{1,2}` |
| `points` | `AdjustPointsRequest.php:22` | `-?\d{1,3}` | points stored decimal(15,2) | `-?\d{1,2}` (sign is correctly allowed for deductions) |

Note: `reward_value` in **earning_rules** is correctly scale-4 (`CreateEarningRuleRequest.php:34`) because that column is `decimal(15,4)` — the differing scale for the same field name across the two tables is intentional and correct. Only the decimal(15,2)-backed fields above are over-loose.

`conditions.min_purchase_amount` / `max_purchase_amount` (scale 3) land in a **JSONB `conditions`** column (no DB scale enforcement), so scale-3 there is a defensible house choice, not a column mismatch — leave as-is.

**Fix:** tighten the listed fields to `\d{1,2}` (or, if the team intends to widen these columns to scale 4/3 in a later phase, document that and keep — but right now the column is the source of truth and it's 2).

---

### P2 — Workshop bundle/line `tax_rate` scale-3 vs service/product `tax_rate` scale-2 — verify intent is documented
**Files:** `StoreBundleRequest.php:31`, `UpdateBundleRequest.php`, `AddLineRequest.php:32`, `UpdateLineRequest.php`
These use `\d{1,3}` and the columns genuinely are `decimal(6,3)` (workshop_work_order_lines, workshop_service_bundles), so the regex is **correct**. But it is inconsistent with the scale-2 `tax_rate` everywhere else (services/products/POS/documents, all `decimal(5,2)`). This is not a bug, but it is a latent trap: a workshop line tax_rate of `19.999` will be accepted and stored, then will not round-trip cleanly through any downstream code that assumes 2-dp tax rates (fiscal totals, VAT detail tables `pos_receipt_vat_details.tax_rate decimal(5,2)`). Recommend a one-line comment at these callsites noting the workshop columns are intentionally 3-dp, and confirm the fiscal/VAT aggregation path tolerates a 3-dp workshop rate. No code change required to merge.

---

### NIT — Dead `-?` in `min:0` rules (debit/credit, min_quantity/max_quantity, new_quantity, etc.)
**Files:** `OpeningBalanceBatchController.php:48-49` (`debit`/`credit` `min:0` + `-?` regex), `PricingController.php` (`min_quantity`/`max_quantity` `min:0` + `-?`), `CreateJournalEntryRequest` (`min:0`).
The `-?` in the regex can never match because `min:0` rejects negatives first. This is harmless but misleading — it implies reversing/negative entries are supported when they are not. Either drop the `-?` (and `^\d`) for honesty, or — if reversing journal entries are a real requirement — the `min:0` is the actual bug (debit/credit reversing lines would need to allow negatives, and currently cannot regardless of the regex). Confirm which is intended; today the behavior is "positive only" and the `-?` is cosmetic.

### NIT — `statement_balance` correctly allows `-?` (overdraft) — good
`BankReconciliationController.php:91` `['required','numeric','regex:/^-?\d+(\.\d{1,3})?$/']` with no `min` — correct, statement balances can be negative. Same for `ZReportSync` opening/expected cash (no min). `price_adjustment`/`modifiers.*.price_adjustment` correctly allow `-?`. Sign handling is right where it matters.

### NIT — `MarginService::getMarginLevel` launders the bcmath percent through `(float)` before the boundary compare
**File:** `apps/api/app/Modules/Product/Application/Services/MarginService.php:251,276`
`calculateMargin()` returns `float`; `getMarginLevel` then re-stringifies `(float) $actualMargin` via `bcformat((string) $actualMargin, 2)` for the `bccomp` against the threshold. At MARGIN_SCALE=2 a 2-dp decimal round-trips through float losslessly, so the discount-permission boundary flip the rewrite targets is prevented **in practice**. But the `(float)` hop is an avoidable laundering of the exact value. Cleaner: have an internal string-returning `calculateMarginString()` and keep `calculateMargin(): ?float` as a thin `(float)` wrapper for back-compat. Not blocking — the comparison is safe at scale 2.

### NIT — `WithholdingCertificateService.php:51` casts `(float)` on the now-string `manualRatePercentage`
**File:** `apps/api/app/Modules/Taxation/Application/Services/WithholdingCertificateService.php:51`
```php
(float) ($data->manualRatePercentage ?? '0'),
```
The DTO field was changed `?float → ?string` (good, preserves the exact ingress decimal), but the consumer immediately casts back to `(float)` to feed `calculateWithOverride()`. This is an acceptable **bounded launder** for now (the downstream signature still wants float), and PHPStan-clean. Flagging only so it's tracked: the precision benefit of the DTO change is not realized until `calculateWithOverride` accepts a numeric-string. Fine to merge.

---

## Things I checked that are CORRECT
- Anchoring: every regex is `^...$`, accepts integer-with-no-decimals and exactly-S decimals, rejects S+1. No unescaped-dot (`\.` used), no catastrophic backtracking (linear `\d+(\.\d{1,N})?`).
- Voucher `IssueGoodwillRequest` correctly tightened `\d+` → `\d{1,5}` (scale 5) and kept `string`-only (pre-existing pattern, not a new break).
- CreditNote `amount`/`unit_price` correctly moved 4dp→3dp (money) and kept `quantity` at 4dp; `tax_rate` correctly 2dp. The `string`-only rules there are pre-existing (not newly introduced), so no number-client break.
- MarginService `bcRoundHalfUp` half-away-from-zero is correct for both signs (`+half`/`−half` then truncate); `bcpow('10',(scale+1),0)` then `bcdiv('5',…,scale+1)` builds the correct half-increment.
- `getSuggestedPrice`/`updateSalePrice` no longer leak `(float)` into arithmetic; the only `(float)` casts are at the return boundary (signature still `: float`) — acceptable.
- `AppointmentAuthoringService`: resolver injected via constructor (no `app()`), uses `getScaleSafe()` (correct — `create()` can run outside a request context / in queue, so the fallback-bearing variant is right), and null `estimated_price` is preserved (guarded by `isset`), not zero-filled.
- No `integer` column received a decimal regex (the integer fields — `qualification_period_months`, `usage_limit`, `sort_order`, `*_minutes`, `service_interval_*` — were left untouched).

---

## VERDICT: REQUEST-CHANGES

Two P1 correctness defects (PaymentController withholding_rate scale rejects valid Tunisian rates; ValidateCoupon quantity loosening silently truncated by `(int)` consumer) and a P1 systemic test weakness (inline-literal rules cannot catch scale mismatches — and demonstrably did not, given the contradictory withholding_rate assertions). The Loyalty scale-3-vs-decimal(15,2) cluster (P2) should be tightened in the same pass. Fix the two P1 code defects, re-point the acceptance tests at the real FormRequest `rules()` so they assert production scale, and tighten the Loyalty regexes; the rest of the sweep is solid and the MarginService rewrite can stand.

---

## Orchestrator reconciliation (2026-05-30)

**Codex adversarial review (fiscal ingress: POS receipt/Z-report/Document/Taxation):** CLEAN on all 4 checked dimensions — Z-report scale matches `pos_shifts decimal(16,4)`, `numeric` kept alongside regex (no number-client break), no `string`-only regression, no fiscal-payload impact (ingress only). No findings.

**Opus findings — all resolved on `feat/precision-phase-b`:**
- **P1 withholding_rate scale:** FIXED — `PaymentController` regex `\d{1,2}`→`\d{1,4}` (column is `decimal(5,4)` fraction; scale-2 wrongly rejected 1.5%/0.5% rates). HTTP 422 test added that goes red if reverted.
- **P1 coupon quantity vs `(int)` consumer:** FIXED — `ValidateCouponRequest.items.*.quantity` reverted to `integer|min:1`; F-COUPON-QTY (fractional coupon quantities) deferred with a code comment (requires making the coupon consumer chain decimal-aware — out of scope for ingress hardening).
- **P1 tautological tests:** FIXED — every Phase-4 ingress test reworked to assert against the REAL production rule source: `(new XRequest)->rules()` for FormRequest-backed fields (CompanyContext bound / userResolver set where `rules()` needs it), and real authenticated HTTP 422 tests for inline-controller validators (Payment withholding_rate+amount, Z-report opening_cash). Regression-catch verified twice (reverting a scale turns a test red). A minimal set of documented mirror-literals remain only where an endpoint POST is prohibitively heavy, each annotated "NOT bound to production" + controller:line.
- **P2 Loyalty too-loose regexes:** FIXED — `\d{1,3}`→`\d{1,2}` on confirmed `decimal(15,2)` reward/tier/earning-cap/welcome/adjust fields; earning-rules `reward_value` (decimal 15,4) and JSONB conditions correctly left as-is.
- **NITs / known gap:** dead `-?` in `min:0` rules (harmless); `getMarginLevel` (float) launder (safe at scale 2); WithholdingCertificateService `(float)` cast of the now-string DTO (bounded launder). **Follow-up coverage gap:** 4 inline controllers (DiscountController, PricingController, StampDutyRuleController, TaxConfigurationController) have correct regex rules but no dedicated ingress test — net-new coverage, not a defect; DiscountController's fiscal correctness was cleared by Codex.

Verification after fixes: 289 reworked/HTTP tests green (655 assertions) + 49 coupon regression green; pint + PHPStan L8 clean on all 26+ changed files.

**Final verdict: APPROVE** (all BLOCKER/P1 closed; P2 closed; one follow-up coverage gap logged).
