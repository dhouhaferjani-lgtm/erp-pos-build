# M-7 Opus Adversarial Review — Both-type partner net balance includes payables

Date: 2026-06-23
Reviewer: Opus (adversarial, refutation-oriented)
Commit under review: `4df5379b0` (Phase 0.1.24: Include payables in both partner net balance)
Item: M-7 — `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md`
Claim: Both-type partner net balance = `receivable - credit - payable`, consistent across model accessor, partner-index SQL sort, and frontend helper.

## Summary

The core fiscal/sign claim is correct and the change is low-risk. As merged in `4df5379b0`:
- The model accessor (`getNetBalanceAttribute`) returns `receivable - credit - payable` for `both`, `receivable - credit` for customer, `payable` for supplier — all via `bcsub(..., 3)` (no float, scale fixed at 3, matching the `decimal:3` storage). Sign convention matches the audit's stated definition (positive = partner owes the company).
- The partner-index SQL `CASE` expression mirrors the accessor exactly, and the enum literal values (`'supplier'`, `'both'`, customer-as-ELSE) match `PartnerType`.
- Tests are meaningful and red-first: the unit test (1000/100/250 → 650) and the feature sort test (Both=650 ranked below Customer=800 desc) would both have failed under the old `receivable - credit` formula. Test setUp seeds no extra partners, so the `data[0]/data[1]` ordering assertion is sound.

No events, GL postings, or migrations are touched, so event-immutability / GL-correctness / migration-safety lenses are not applicable — there is no BLOCKER-class fiscal or data-integrity risk in this commit.

The weaknesses are convention/consistency-grade, not correctness-grade: a float (`parseFloat`) is used on money in the new reusable frontend helper, the frontend recomputes net balance instead of consuming the authoritative `net_balance` field (a third computation site / drift risk), and a customer-scoped-view behavior change is undocumented.

Note: dev HEAD has moved past this commit. Later commit `8f65792b6` (Phase 0.1.30 "Centralize partner net balance presentation") extracted shared `Partner::netBalance()` / `Partner::netBalanceSqlExpression()` and reworked the frontend helper to prefer the server `net_balance` field and return a string (removing `parseFloat`). Several findings below were therefore already remediated downstream; they are still recorded against `4df5379b0` as merged, with the downstream status noted.

## BLOCKER

None.

## HIGH

None.

## MEDIUM

### M1 — Float on money in the new reusable frontend helper (Rule 19 violation)
`apps/web/src/features/partners/partnerNetBalance.ts` (as merged in `4df5379b0`):
```ts
const receivable = parseFloat(partner.receivable_balance ?? '0')
const credit = parseFloat(partner.credit_balance ?? '0')
const payable = parseFloat(partner.payable_balance ?? '0')
...
return receivable - credit - payable   // returns number
```
CLAUDE.md Rule 19 forbids `parseFloat`/`Number(...)` on money. The old inline helper already did this (pre-existing), but M-7 promoted it into a named, reusable, separately-tested helper that returns a `number`, and `PartnerListPage` then runs `Math.abs(balance)` + `formatCurrency` on it. The `precision/no-parsefloat-on-money` ESLint rule is configured as `warn` (not `error`), so CI did not block. For the small magnitudes here the float error is invisible, but this is exactly the drift the contract exists to prevent, now in a "blessed" helper file.
Downstream status: fixed by `8f65792b6`, which made the helper prefer the server `net_balance` string and return a string (no `parseFloat`).

### M2 — Frontend recomputes net balance instead of using the authoritative `net_balance` field (third computation site / drift risk)
`apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php:84` serializes `net_balance: $partner->net_balance` — i.e. the JSON payload already carries the model-accessor result. As merged, the frontend `getNetBalance` ignores that field and recomputes from `receivable_balance`/`credit_balance`/`payable_balance`. The SQL `CASE ... AS net_balance` alias is used only for sort/aggregates, not for the serialized field. The net result is **three** formula sites that must be kept in lockstep (accessor → JSON, SQL → sort, frontend → display). The audit acceptance criterion was "explicit and consistent across backend/web"; M-7 made them agree but did not collapse the redundancy, leaving a future-drift footgun. The frontend already receives the correct value and should consume it.
Downstream status: fixed by `8f65792b6` (helper now returns `partner.net_balance` when present).

### M3 — Undocumented behavior change in the customer-scoped list view for `both` partners
`PartnerListPage` derives `isCustomerView` from `partnerType === 'customer' || pathname.includes('/sales/customers')`. Old helper: `if (isCustomerView || type === 'customer' || type === 'both') return receivable - credit`. New helper: the `type === 'both'` branch is checked first, so on the `/sales/customers` screen a `both` partner now shows `receivable - credit - payable` instead of the prior `receivable - credit`. A customer-AR-oriented screen now nets supplier payable into the displayed figure. This is consistent with the audit's deliberate single global "net exposure" definition (defensible), but it is a user-visible change in an AR context that is not called out in the work-list outcome and has no test asserting the customer-view branch interaction (`isCustomerView=true` + `type='both'`).

## LOW

### L1 — Dual-review gate not genuinely satisfied at merge
Both attached reviews (`...-codex-review.md`, `...-opus-fallback-review.md`) are non-Opus; the fallback file itself states "True cross-model Opus review was not available" and leaves `opus-review: PENDING`. The coordination log entry also carries `opus-review: PENDING`. The item was marked DONE/merged without a real cross-model Opus pass — this review is that pass. No correctness impact, but the gate metadata overstates review coverage.

### L2 — Feature sort test does not assert the emitted `net_balance` values, only ordering
`test_sort_by_net_balance_offsets_both_partner_payable_balance` asserts row order (Customer before Both) but never asserts the numeric `net_balance` (e.g. `650.000`) in the response body. Ordering would also pass for some incorrect-but-monotonic SQL expressions. The unit test covers the exact value via the accessor, so coverage is adequate in aggregate, but the SQL alias's emitted value is asserted only indirectly. (Moot for the served field, since `PartnerData` uses the accessor, not the alias — see M2.)

### L3 — `decimal:3` vs `bccomp(..., 4)` scale mix
`hasOutstandingBalance()` compares `net_balance` with `bccomp($netBalance, '0', 4)` while balances and `netBalance()` operate at scale 3. Harmless here (extra precision on a scale-3 value), not introduced by M-7, noted only for awareness.

## Verdict

APPROVE-WITH-MINOR-EDITS.

The sign convention, decimal arithmetic, enum-literal alignment, and red-first tests hold up under refutation; there is no fiscal or data-integrity defect, and no events/GL/migrations are touched. The findings are convention-grade: float-on-money in the new helper (M1), a frontend that recomputes rather than trusting the served `net_balance` (M2), and an undocumented/untested customer-view behavior change for `both` partners (M3). M1 and M2 were already remediated downstream by `8f65792b6`; M3 and the LOW items remain worth a follow-up note. The `opus-review: PENDING` gate is now closed by this pass.
