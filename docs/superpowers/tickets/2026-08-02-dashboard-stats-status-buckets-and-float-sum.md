# Ticket: /dashboard/stats — exact-Posted status buckets give counter-intuitive KPI moves on refund + float-on-money sum at the origin

Surfaced by the 2026-08-02 mobile-impact sweep of the treasury fix lane (`f670d37bf`). Mobile
(`erp-mobile` dashboard tab) and any web consumer of `GET /dashboard/stats` are affected the same
way; the defects are 100% backend (`apps/api/app/Modules/Dashboard/Presentation/Controllers/DashboardController.php`).

## 1 — Status buckets filter on exactly `Posted`; `Paid` is a distinct enum case

- `:41`/`:47` revenue = `sum('total')` over `status = DocumentStatus::Posted`
- `:67` overdue count over `status = Posted`
- `:106`/`:111` `paymentsPending = postedInvoiceTotal − paymentsReceived`

`DocumentStatus::Paid` is distinct from `Posted` (Document/Domain/Enums/DocumentStatus.php:11-12),
so a PAID invoice is already EXCLUDED from "revenue" and "overdue" today — arguably a pre-existing
misdefinition (revenue that disappears when the customer pays). The treasury fix lane makes it
newly VISIBLE: full/partial refunds now revert Paid→Posted, so a refund makes dashboard revenue
GO UP, overdue count go up, and payments.pending go up. Nothing crashes; the semantics are just
wrong-looking in both directions.

Fix direction (needs a product ruling on what "revenue" means here): most likely
`whereIn('status', [Posted, Paid])` for revenue/overdue and recompute pending consistently.
Decide + document the intended KPI definitions before touching the queries.

## 2 — Float-on-money at `:43`: SQL `sum('total')` returns a PHP float

`revenue.current/previous/change` travel as JSON floats while `payments.received/pending` are
correctly scale-3 decimal strings (`sumDecimalStrings`/`bcsub` at `:93`/`:111`). Precision-contract
(rule 19) violation at the origin. Fix: bc-based string sum for the revenue bucket; then flip the
consumer types (mobile `src/features/dashboard/api/dashboardApi.ts:12-16` types `number` and its
comment candidly documents the mixed shape — update it in lockstep; web equivalent if any).

## Mobile advisory (no mobile code change required)

Full sweep record: 46 API call sites enumerated; no payments/refunds/credit-notes/withholding/
instruments surface exists on mobile; `document_date` usage already correct; attachments route is
Media-module, untouched; money handling on mobile is string-end-to-end and clean. Only follow-the-
backend items: this ticket's KPI/type changes, and the structural note that mobile DTOs are
hand-written (no codegen) so any DocumentData field rename needs a manual mobile PR.

## RESOLUTION (2026-08-03)

Orchestrator rulings applied to `DashboardController.php`:

1. **Revenue/pending base = `whereIn('status', [Posted, Paid])`.** A Paid invoice is still
   revenue; a refund's Paid→Posted revert no longer moves revenue (both statuses were already
   in the base, so the total is invariant across the transition). `:38-71`.
2. **Overdue stays `Posted`-only, deliberately NOT widened to match revenue.** A Paid invoice
   past its due date is settled, not overdue — the ticket's `whereIn` suggestion applies to the
   *revenue* bucket only. Corollary asserted deliberately (not treated as a regression): a
   refund reverting Paid→Posted CAN legitimately re-enter the overdue bucket if the invoice is
   past due — that is correct business reality. `:83-92`.
3. **`paymentsPending`** now derives from the *same* Posted∪Paid base as revenue, minus
   `paymentsReceived` — consistent with ruling 1. `:125-135`.
4. **`revenue.current`/`revenue.previous`/`revenue.change` are now bc-based decimal strings**
   (precision rule 19), computed via the same `sumDecimalStrings()` helper already used for
   `payments.*` — no more SQL `sum('total')` float. `change` stays a percentage, represented as
   a 2dp string (e.g. `"25.00"`, `"-10.50"`), mirroring the existing
   `ExpenseAnalyticsService::generate()` mom-delta pattern
   (`bcround(bcmul(bcdiv(delta, previous, scale), '100', scale), 2)`).

Backend tests (red→green): `apps/api/tests/Feature/Dashboard/DashboardStatsTest.php` — 7 new
cases covering all 4 rulings, including the two refund-revert cases (revenue invariant / overdue
re-entrant) asserted in the SAME direction the ruling calls for.

**Superseded by the gate AMENDMENTS below — see that section for the shipped behavior.** Ruling
3 (`paymentsPending`) as originally implemented here was found materially wrong (H1) and ruling 4
(`revenue.change`) never emits null (M2); both were revised before promotion.

### Web consumer fixed in lockstep

`apps/web/src/features/dashboard/Dashboard.tsx` was the only web consumer of `GET
/dashboard/stats` (confirmed via repo-wide grep for `dashboard/stats` and `DashboardStats` —
the `admin/*` dashboard hooks hit unrelated endpoints `/admin/dashboard` and
`/admin/billing/dashboard`):

- `DashboardStats.revenue.{current,previous,change}` and `.payments.{received,pending}`
  retyped `number` → `string` (payments were already strings on the wire — the old `number`
  type there was itself a pre-existing, previously-undetected drift).
- `formatAmount()` no longer does `parseFloat(amount)` before calling `formatCurrency` — rule 19
  forbids `parseFloat`/`Number(...)` on money, and `formatCurrency` already accepts `string |
  number` natively (via `big.js`), so the parseFloat step was both unnecessary and a
  precision-contract violation for every render (it affected `payments.*` too, pre-existing).
- The revenue-change sign/magnitude (`stats.revenue.change >= 0`, `Math.abs(...)`) is now derived
  with `big.js` (`new Big(change).lt(0)`, `.abs().toString()`) and displayed via the existing
  `formatPercent()` helper from `lib/format.ts` instead of raw `{value}%` interpolation.
- Fixtures (`__fixtures__/dashboard.ts`) and the inline stats fixture in
  `__tests__/Dashboard.tenantScope.test.tsx` updated to the string wire shape for realism.

## AMENDMENTS (2026-08-03, post-gate fix round)

Adversarial gate review: `docs/superpowers/reviews/2026-08-03-dashboard-kpi-gate.md`
(`cf6f07827`) — verdict **APPROVE-WITH-FIXES, NOT promotable as-is**. Fixed under
orchestrator-amended rulings below; all backend commit SHAs/line numbers refer to the
fix-round commit, not the original `ebf5becfb`.

- **H1 (HIGH, blocking) — `payments.pending` was ~2× wrong.** The original ruling-3
  implementation was `all-time Posted∪Paid invoice total − THIS-MONTH-ONLY paymentsReceived`,
  which is arithmetically invalid (mismatched time windows) and, on the launch tenant, inflated
  the tile from a true `0.000` to `54,466.443`. The original test even **pinned the defect as
  correct** (`150.000` for a scenario with zero payments and a fully-Paid invoice).
  **AMENDED RULING 3: `paymentsPending = SUM(documents.balance_due)` over the Posted∪Paid
  base — no `paymentsReceived` subtraction at all.** `balance_due` is authoritative (decremented
  by treasury allocations, reopened by refund-unwind) and matches what invoice detail views
  already show. Tests rewritten: `test_payments_pending_is_sum_of_balance_due_over_posted_and_paid_base`
  (was the pinning test) + new `test_payments_pending_excludes_settled_prior_month_paid_invoice`
  (a settled Paid invoice, `balance_due = 0`, from a prior month contributes exactly 0 — pending
  is deliberately all-time, unlike revenue).
- **M1 (MEDIUM, blocking) — previous-month window overflow.** `Carbon::now()->subMonth()`
  overflows on ~6 dates/year (day-of-month exceeding the previous month's length — Mar 29-31,
  May 31, Jul 31, Oct 31, Dec 31), collapsing the previous-month window onto the current month
  (both a CI flake in the new test and a real month-end KPI defect). Fixed:
  `subMonthNoOverflow()` at the controller's previous-month window. New test
  `test_revenue_previous_month_window_does_not_overflow_at_month_end_boundary` freezes the
  clock at `2026-05-31` and asserts the April invoice is captured correctly; the existing percent
  test also now freezes the clock (`Carbon::setTestNow`) instead of depending on ambient "now".
- **M2 (MEDIUM) — AMENDED RULING: `revenue.change` is `null`, not `"0.00"`, when
  `previous <= 0`** (no previous-period baseline at all, or a negative one from a data anomaly).
  The original guard (`> 0`) silently emitted `"0.00"` for both cases, which reads as "flat
  month" — indistinguishable from a genuine 0% change, and directly misleading for a brand-new
  tenant's first month (relevant to the first-tenant launch program). Web: `revenue.change` is
  now `string | null`; the KPI tile renders no arrow icon and an em-dash (`—`) on null, mirroring
  `ExpenseAnalyticsPage`'s `mom_delta_percent` tile convention. Tests: backend
  `test_revenue_change_is_null_when_previous_revenue_is_zero` +
  `test_revenue_change_is_null_when_previous_revenue_is_negative`; web
  `dashboard.test.tsx` — "renders no arrow and an em-dash when revenue.change is null".
- **L4 (in-scope per this ticket's own premise) — completed refunds now net against
  `payments.received`.** `PaymentType::Refund` rows always carry a NEGATIVE `amount` by
  convention (`PaymentRefundService::refundPayment`/`partialRefund`/the proration writer), so
  including `Refund` alongside the incoming payment types in the `paymentsReceived` sum nets them
  automatically — no sign-flipping needed. Test: `test_payments_received_nets_completed_refund_this_month`.
- **M4 (cheap perf) — all three Document-side sums now use SQL `SUM(...)::text` instead of
  `pluck()`.** `pluck()` on a `decimal:*`-cast column hydrates a full Eloquent model per row
  (gate-measured 283-587ms per call on 522 invoices); `SUM(...)::text` (mirroring the existing
  `GeneralLedgerService::availableAdvanceCredit()` pattern) avoids both the row hydration and any
  float round-trip. `paymentsReceived`'s `Payment`-side pluck was left as-is (not flagged, and
  already date/status/type-bounded) — not over-engineered per the ruling.
- **L1 — five e2e route mocks still served the old numeric revenue shape** (three in
  `e2e/fixtures.ts`/`auth.spec.ts`/`company.spec.ts` as named by the gate, plus a **sixth** found
  while fixing them, `company.spec.ts` `**/api/v1/dashboard/**` wildcard route around line 400 —
  same stale shape, missed by the gate's own citation). All six updated to the string wire shape.

Backend tests: 13 total in `DashboardStatsTest.php` (the original 7 minus the rewritten H1 test,
plus 2 H1 replacements, 1 M1 boundary case, 2 M2 null cases, 1 L4 case = 13). Live probe on
demo-pharmacy-tn cross-checked directly against the tenant Postgres DB (`SUM(balance_due) WHERE
type='invoice' AND status IN ('posted','paid')` — exact match to the API response) — implementation
verified correct; the live figure differs from the gate's own snapshot value because this is a
shared, concurrently-mutating dev tenant (see verification report for the exact numbers and cross-check).

## MOBILE FOLLOW-UP (erp-mobile team: read this before touching the dashboard tab)

This ERP backend change (`GET /dashboard/stats`) is a published-API shape change:
`revenue.current` and `revenue.previous` flip from JSON numbers to decimal STRINGS, and
`revenue.change` flips from a JSON number to a **`string | null`** (AMENDED M2 ruling above —
null when there is no previous-period baseline, e.g. a tenant's first month; do not treat a
missing/null `change` as a bug). `payments.received`/`payments.pending` are unchanged (already
strings).

**Corrected from the original version of this section:** this repo DOES have an established
cross-repo API-contract convention — `docs/superpowers/coordination/` (e.g. the 25+ prior
handover/contract docs there, such as `2026-06-26-supplier-invoice-api-contract.md`). The
original claim that "no convention exists" was wrong (2026-08-03 gate, D.3/L5). This change is
now logged there too:
`docs/superpowers/coordination/2026-08-03-dashboard-stats-api-shape-change.md` — that doc is the
canonical short-form contract record; this ticket section remains the detailed line-by-line
punch list.

Confirmed by reading `erp-mobile` directly (read-only; NOT modified per this task's scope — the
task instructions explicitly forbid touching the mobile repo). The original version of this
section also claimed, in item 5, that "no [mobile] change needed" beyond items 1-4 — that blanket
claim was **wrong** (2026-08-03 gate, M3/D.2): it omitted an entire test file that pins the OLD
`number` contract and will not fail on the shape change (nothing will flag it for the mobile
team without this list). Corrected list follows:

1. **`erp-mobile/src/features/dashboard/api/dashboardApi.ts:11-15`** — `DashboardStats.revenue`
   fields (`current`, `previous`) are typed `number` and must become `string`; `change` must
   become `string | null` (not just `string` — see the AMENDED M2 ruling above). **Also rewrite
   the JSDoc comment directly above the interface, which spans `:3-10`** (corrected from the
   original citation of "lines 3-8" — 2026-08-03 gate, D.2) — it currently reads:
   > "The shape deliberately mixes types: revenue floats + integer counts are JSON numbers,
   > while payments travel as scale-3 decimal STRINGS to preserve money precision. Never do
   > arithmetic on `payments`; display via formatMoney."

   This is now stale/wrong: revenue is no longer float-typed, and the "never do arithmetic"
   guidance must be broadened to cover `revenue` too (not just `payments`).

1a. **`erp-mobile/src/features/dashboard/__tests__/dashboardApi.test.ts`** (omitted from the
    original version of this section — added per 2026-08-03 gate M3):
    - **`:6`** — the file's own doc comment repeats the same stale "JSON numbers (revenue,
      counts) and scale-3 money strings (payments)" contract as (1); rewrite alongside it.
    - **`:20`** — the `payload` fixture, `revenue: { current: 422.366, previous: 397.314, change:
      6.3 }`, is now an invalid wire shape; must become strings (and exercise the `change: null`
      case too, since that is now a real, expected wire value, not an edge case to ignore).
    - **`:52-53`** — `expect(typeof stats.revenue.current).toBe('number')` and
      `expect(typeof stats.revenue.change).toBe('number')` are the **contract assertions** for
      the old shape and must be inverted to `'string'` (and a null-branch assertion added for
      `change`).

2. **`erp-mobile/app/(app)/index.tsx:58`** —
   `const changePositive = (stats?.revenue.change ?? 0) >= 0;` currently relies on JavaScript's
   implicit string→number coercion for `>=` (works by accident today with a string operand, but
   is exactly the kind of implicit-numeric-conversion-on-money-adjacent-value pattern this
   ticket is fixing on the web side). Needs a string-safe sign check once `revenue.change` is
   typed `string | null` (e.g. check for a leading `-`, or route through a decimal-safe
   comparison helper — mirroring `lib/format.ts`'s own docstring: "String-safe display formatting
   for money and percentages... must never round-trip them through floats") **and** a null branch
   — mirror the web fix's convention (no arrow icon + em-dash on null) rather than treating
   `?? 0` (i.e. "flat/positive") as a safe default for "no baseline", which is the exact
   misleading-first-month UX the M2 ruling exists to fix.

3. **`erp-mobile/src/lib/format.ts:20`** — `export function formatPercent(change: number): string`
   must widen its parameter to `string | number` (mirroring `formatMoney`'s existing signature at
   line 10, which already accepts `string | number`). The function body (`change > 0`,
   `String(change).replace('.', ',')`) is largely compatible already, but the type signature is
   the actual compile break once `dashboardApi.ts` changes `revenue.change` to `string | null`.
   The call site (item 4) is expected to guard the `null` case itself (mirror the web's em-dash
   convention) rather than pushing `null` into `formatPercent`.

3a. **`erp-mobile/src/lib/__tests__/format.test.ts:8`** (omitted from the original version of
    this section — added per 2026-08-03 gate M3): `it('formats a float (dashboard revenue)
    without arithmetic', () => expect(formatMoney(422.366)).toBe('422,366 TND'))` is stale in the
    same way as the `dashboardApi.test.ts` fixture — `formatMoney` will no longer receive a float
    for dashboard revenue; update the test description/fixture to a string input (`formatMoney`
    itself needs no code change here — only the test's premise is stale).

4. **`erp-mobile/app/(app)/index.tsx:131`** — `formatPercent(stats.revenue.change)` call site.
   This is where the TypeScript compile error will surface first once (1) lands (both because the
   type is now `string`, and because it is now `string | null` — `formatPercent` does not accept
   `null`). Resolves once (3) widens `formatPercent`'s signature AND this call site adds a
   null-guard (mirroring item 2's convention) before calling it.

5. **Partially unchanged, NOT a blanket "no change needed"** (the original version of this
   section overstated this — 2026-08-03 gate M3/D.2): `Amount`
   (`erp-mobile/src/ui/atoms/Amount.tsx:10`) already types its `value` prop `string | number`,
   and `formatMoney` (`erp-mobile/src/lib/format.ts:10`) already accepts `string | number` — so
   `revenue.current`/`revenue.previous` flow through the existing `<Amount
   value={stats.revenue.current} format={formatMoney} .../>` usage (`index.tsx:110-115`) with
   zero code change once the type in (1) is corrected. But this does NOT extend to `change` (items
   2-4) or the two test files (1a, 3a) — those all need real edits.

## Follow-up tickets (identified 2026-08-03 gate; explicitly NOT implemented this round — tracked here only)

Two carry-over defects the gate surfaced while reviewing the Posted∪Paid widening. Both pre-date
this ticket's commits and are out of scope for this fix round; recorded here per the
orchestrator's instruction so they are not lost.

### L3 — multi-currency mixing in dashboard sums + hardcoded scale 3

`sumDecimalStrings()`/`sumColumnAsString()` hardcode scale `3` and `DashboardController`
constructor-injects only `CompanyContext` — no `CurrencyScaleResolverInterface` (contrary to
precision rule 19; contrast `ExpenseAnalyticsService`, which injects the resolver and derives
`$scale = $this->scaleResolver->getScale($company->currency)`). More importantly, every sum in
this controller (revenue current/previous, `paymentsPending`, `paymentsReceived`) adds amounts
**across currencies** with no `WHERE currency = ...` filter — the live demo-pharmacy-tn tenant
holds a mix of EUR and TND invoices, all added into one scale-3 number that the web then renders
formatted as the company's single currency. The bc-exactness of the arithmetic gives false
assurance about a figure that is semantically mixed-currency. Needs a product ruling (filter to
company base currency? multi-currency KPI breakdown? FX-convert?) before touching the queries.

### Revenue is gross of credit notes (carry-over; gate section A.2 — NOT the gate's separate "L2" bucket-guard-tests item, which is a different, also-not-implemented-this-round recommendation)

Only `type = invoice` rows enter the revenue/pending sums, so a posted **credit note never
reduces revenue** even though the domain already models the direction —
`DocumentType::receivableDirection()` returns `-1` for `CreditNote`
(`DocumentType.php:79-86`) — the dashboard simply never consults it. Confirmed live on
demo-pharmacy-tn: 10 posted credit notes worth several hundred TND are correctly absent from
`revenue.current` today (no double-counting bug), but they are also never subtracted, so
"revenue" is systematically overstated relative to net receivables whenever credit notes exist.
Needs a product ruling: should the dashboard revenue tile be net-of-credit-notes, and if so,
against which base (all-time, or the same current/previous-month windows as invoice revenue)?

Per the mobile advisory above, mobile DTOs are hand-written (no codegen), so this is a manual
mobile-side PR — not automatically picked up from this ERP change.
