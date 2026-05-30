# Precision & Scale-Drift Remediation — Phase 7 (Value-Object Refactor) — Adversarial Opus Review

**Branch:** `feat/precision-phase-e` (worktree `apps/erp.precision-phase-e`)
**Base:** `origin/feat/precision-phase-d`
**Date:** 2026-05-30
**Scope reviewed:** Billing `Money` VO + 8 callers + StripeWebhookController; Loyalty `PointsAmount`, `LoyaltyBalance`, and 4 service callers; 5 test files.

## Verification performed
- Read full `Money`, `PointsAmount`, `LoyaltyBalance`, `CurrencyScale`, all touched service/controller callers.
- Ran affected suites: **121 tests / 251 assertions green** (Money, PointsAmount, LoyaltyBalance bcmath + legacy VO tests + RedemptionProcessingService + EarningPrecision). Full Billing suite: 32/32 green.
- PHPStan level 8 on all 7 changed production files: **No errors.**
- Confirmed runtime truncation behavior of `bcadd($x,'0',$scale)` and `bcmul(...,$scale+4)` re-normalization with a standalone PHP harness.
- Grepped every caller of `toCents`, `Money::multiply`, `PointsAmount::multiply/percentage`, `canRedeem`, `points_required`, and every `new LoyaltyBalance`/`PointsAmount` construction across app + web + tests.

---

## Findings

### P2-1 — `Money::multiply` docblock claims "rounded" but the implementation TRUNCATES
`apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php:100-118`

The docblock states "The product is computed at higher precision then **rounded once** to the currency scale." It is not rounded — `multiply()` computes `bcmul(..., $scale+4)` then passes the result to the constructor, which canonicalizes via `CurrencyScale::bcformatStrict()` → `bcadd($x,'0',$scale)`, which **truncates** toward zero. Verified: `Money('10.00','EUR')->multiply('0.0375')` would yield `0.37`, not the `0.38` the docblock implies. Same defect in `PointsAmount::multiply` (`PointsAmount.php:134-147`, docblock "canonicalise") and `PointsAmount::percentage` (`:152-166`).

This is currently **latent**: no production code calls `Money::multiply`, `PointsAmount::multiply`, or `PointsAmount::percentage` (grep across `app/` returns only the VO definitions and unrelated `percentage` fields). It is exercised only by tests. But the contract is now documented incorrectly, and the first future caller that relies on "rounded" semantics (e.g. a proration or tier-multiplier money path) will silently under-bill by up to one minor unit.

**Fix:** Either (a) make the docblocks say "truncated to currency scale" to match behavior, or (b) implement true half-up rounding before the final normalization (the codebase already has a `bcRoundHalfUp` helper in `PointsAmount`). Given monetary semantics, (b) is the safer choice; pick one and align the test names (see P2-2).

### P2-2 — `multiply` tests assert "rounded once" in their NAME but use exact-landing inputs, so they cannot catch the truncate/round divergence
`apps/api/tests/Unit/Billing/MoneyBcmathTest.php:97-114`

`multiply_is_exact_and_rounded_once_to_scale` uses `19.99 * 3 = 59.97` (exact) and `multiply_truncates_fractional_cents_to_scale` uses `10.00 * 0.075 = 0.75` (also exact). Neither input lands between two minor units, so both pass regardless of whether the impl truncates or rounds. The test names assert mutually contradictory semantics ("rounded once" vs "truncates") and neither is actually verified. Add a case like `10.00 * 0.0375` (= 0.375) that distinguishes truncate (0.37) from round (0.38) once P2-1's contract is decided.

### P2-3 — `toCents()` can emit Stripe-invalid minor units for 3-decimal currencies
`apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php:61-68`, consumed at `apps/api/app/Modules/Billing/Infrastructure/Providers/StripePaymentProvider.php:87,202`

The new scale-aware `toCents()` is correct for the EUR/USD billing path (×100, unchanged) and is a genuine improvement over the old hardcoded ×100 for non-2dp currencies. However, Stripe's three-decimal-currency API requires the smallest-unit integer to be a **multiple of 10** (the last digit must be 0). A `Money('12.345','TND')->toCents()` returns `12345`, which Stripe would reject when used as the `amount` param in `StripePaymentProvider::charge`/`refund`. The constructor permits 3 significant decimals for TND, so the VO can hold such a value.

This is **low risk in practice**: SaaS billing currency is Stripe-driven and defaults to `'EUR'` everywhere (`InvoiceService:62`, `TenantSubscription:125`, `AdminBillingController:263`, `StripeWebhookController:303/386/441`), and there is no evidence of a TND/3-dp Stripe charge path today. The inbound `fromCents` direction (used in `handleChargeRefunded`) is fully correct for all scales. Flagging because the phase explicitly asked whether a non-EUR/USD Stripe path exists: it does not today, but `toCents` now silently makes 3-dp currencies *look* supported. **Fix (optional/defer):** if 3-dp Stripe charging is ever in scope, round the minor-unit result to the nearest 10 (or assert scale ≤ 2 when the provider is Stripe). Otherwise, a one-line doc note on `toCents` that Stripe 3-dp currencies need ×10 alignment is sufficient.

### NIT-1 — Constructor truncation of EUR values carrying 3 decimals is silent
`Money.php:32-41`. Callers pass `(string) $this->total` where `total` is cast `decimal:3` (e.g. `"12.340"`). For an EUR Money this truncates to scale 2 (`"12.34"`). Harmless today because EUR billing amounts are 2-dp by construction and the dropped digit is a trailing zero — but it is a silent narrowing, not a guarded one. Acceptable; noting for completeness. The `it_truncates_excess_precision_at_currency_scale` test documents the behavior correctly.

### NIT-2 — `canRedeem` return-shape change (`points_required` float → numeric-string) has zero consumers
`RedemptionProcessingService.php:129-...`. The `@return` array shape changed `points_required` from `float` to `numeric-string`. Grep confirms **no controller, route, frontend, or other service consumes `canRedeem` or reads `points_required`** — it is effectively dead/internal. No REALIGNMENT-LOG entry needed. The legacy unit tests (`RedemptionProcessingServiceTest.php:287/307/333/361/389`) assert with loose `assertEquals(0.0, "0.000")` / `assertEquals(100.0, "100.000")`, which pass under PHP `==`. Fine, but if `canRedeem` ever gets wired to an API response, tighten those to `assertSame` and add a realignment note then.

---

## Cleared concerns (checked, no defect)

1. **Removed epsilon tolerances (`Money::equals` 0.001, `LoyaltyBalance` integrity 0.01):** No production hard-fail path. `LoyaltyBalance` is **never constructed in production code** (grep for `new LoyaltyBalance`/`fromEnrollment`/`zero` in `app/` returns nothing) — it is a tested-only VO. The live enrollment balance math in `RedemptionProcessingService` and `PointEarningService` operates directly on `enrollment->current_balance` columns via bcmath, bypassing the VO, so the exact integrity assertion cannot fire on real DB drift. `Money::equals` exact comparison is sound for canonical same-scale strings.
2. **`(float)…` → `(string)…` caller correctness:** Every converted source field (`Invoice.total/amount_due`, `Payment.amount/net_amount`, `Plan.price_*`, `TenantSubscription.price`, `Reward.points_cost`) is a non-nullable `decimal:N` cast column, so `(string)` yields a clean numeric string. No `(string) null = ''` hazard. `Plan`/`TenantSubscription` getters guard null *before* the cast.
3. **`fromCents` round-trip:** Correct for EUR (×100), TND (×1000), JPY (×1); `from_cents_round_trips*` tests confirm. `handleChargeRefunded` refund comparison is now float-free and currency-correct.
4. **Event re-floating (`RewardRedeemedV2.pointsCost`, `PointsEarnedV2.amount`):** Forced by the immutable `public readonly float` event signatures (Agent Rule #8). The canonical numeric-string is used for all balance/ledger writes; the float is display/notification-only. Acceptable, matches existing pattern, annotated in-code.
5. **`PointsAmount`/`LoyaltyBalance` scale = 3:** Correctly matches `loyalty_transactions.amount` and `loyalty_enrollments.*` `decimal(15,3)` storage and the services' `FALLBACK_SCALE=3`. Points are a count, not currency; over-precision (3dp where 2 might do for EUR-pegged points) is harmless and the scale is internally consistent across both VOs. Acceptable.
6. **`PointEarningService:281` `number_format((float)$rawAmount,...)`:** Pre-existing float-ingress scientific-notation guard, NOT introduced by this diff; out of scope.
7. **Tautology check:** New bcmath tests assert exact numeric-string identity (`assertSame('0.30', …)`, `'12.34'`, `'0.010'`) that the old float impl provably could not produce (it stored IEEE-754 floats). `EarningPrecisionTest` gate-1 was correctly migrated from `assertSame(0.01, float)` to `assertSame('0.010', string)`. Non-tautological. (Caveat: the `multiply` tests are weak — see P2-2.)

---

## VERDICT

**APPROVE WITH MINOR EDITS.** No BLOCKER, no P1. The core refactor is correct: bcmath end-to-end, scale-aware Stripe cents (a real fix over the old hardcoded ×100), clean `(string)` caller migration on non-nullable decimal columns, exact comparisons safe given canonical same-scale strings, and the removed epsilons have no production hard-fail path. Tests pass (121 + 32), PHPStan L8 clean.

Required before merge:
- **P2-1 / P2-2:** Decide truncate vs round for `Money::multiply` / `PointsAmount::multiply` / `percentage`, fix the misleading docblocks/test names accordingly, and add a divergent-input test that actually exercises the boundary. (Latent — no production caller yet — but the contract is currently mislabeled.)

Recommended (may defer):
- **P2-3:** One-line note (or guard) that `toCents()` for 3-decimal currencies is not Stripe-charge-safe without ×10 alignment; harmless today since billing is EUR/USD-only.
- **NIT-2:** Add a REALIGNMENT-LOG note only if/when `canRedeem` is exposed via API.

---

## Orchestrator reconciliation (2026-05-30)

**Codex adversarial review (Money VO, money-core):** all 6 dimensions CLEAN — toCents/fromCents minor-unit correctness (billing EUR-only, no 3-dp Stripe path), no truncation money loss, round-trip exact, removed-epsilon safe, no remaining `(float)` on charged amounts, no constructor precision loss.

**Opus findings — addressed:**
- **P2-1 (multiply docblock said "rounded" but truncates):** FIXED — `Money::multiply` docblock corrected to state it TRUNCATES at the boundary (consistent with the precision contract's bcformat write boundary), with a note that half-up must be done explicitly by a future proration caller. No production callers of `multiply` exist today.
- **P2-2 (multiply tests couldn't catch truncate-vs-round):** FIXED — added `multiply_truncates_sub_cent_product_toward_zero` (`10.00 × 0.0375 → '0.37'`, not half-up `'0.38'`) pinning the truncation semantics so a future switch to half-up is a deliberate, test-visible change.
- **P2-3 (toCents 3-dp Stripe minor-unit):** DEFERRED — no risk today (Codex + Opus both confirm billing is EUR/USD-only; no TND Stripe charge path). Documented; if a 3-dp-currency Stripe path is ever added, toCents must emit a multiple of 10 per Stripe's API.
- **canRedeem float→string return / event re-floating:** no consumers / immutable-event display boundary (Rule 8) — no realignment needed.
- **Cleared:** removed epsilons cause no production hard-fail (LoyaltyBalance is tested-only; live math uses columns directly); `(float)→(string)` callers are all non-null decimal props; scale-3 points correct.

**Final verdict: APPROVE** (no BLOCKER/P1; P2-1/P2-2 fixed; P2-3 deferred with rationale; Codex clean).
