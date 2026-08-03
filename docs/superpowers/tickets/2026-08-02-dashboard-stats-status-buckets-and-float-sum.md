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

## MOBILE FOLLOW-UP (erp-mobile team: read this before touching the dashboard tab)

This ERP backend change (`GET /dashboard/stats`) is a published-API shape change:
`revenue.current`, `revenue.previous`, and `revenue.change` flip from JSON numbers to decimal
STRINGS. `payments.received`/`payments.pending` are unchanged (already strings). No ERP-internal
published-API changelog convention exists in this repo for this cross-repo (backend → erp-mobile
client) kind of change — searched `docs/conventions/`, `docs/architecture/`, and repo root; only
the top-level Synerivia platform repo has `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`, and that
convention covers platform↔ERP integration, not ERP↔erp-mobile, so nothing was logged there. This
ticket section IS the handover record — do not invent a new log location.

Confirmed by reading `erp-mobile` directly (read-only; NOT modified per this task's scope):

1. **`erp-mobile/src/features/dashboard/api/dashboardApi.ts:10-16`** — `DashboardStats.revenue`
   fields (`current`, `previous`, `change`) are typed `number` and must become `string`. **Also
   rewrite the JSDoc comment directly above the interface (lines 3-8)** — it currently reads:
   > "The shape deliberately mixes types: revenue floats + integer counts are JSON numbers,
   > while payments travel as scale-3 decimal STRINGS to preserve money precision. Never do
   > arithmetic on `payments`; display via formatMoney."

   This is now stale/wrong: revenue is no longer float-typed, and the "never do arithmetic"
   guidance must be broadened to cover `revenue` too (not just `payments`).

2. **`erp-mobile/app/(app)/index.tsx:58`** —
   `const changePositive = (stats?.revenue.change ?? 0) >= 0;` currently relies on JavaScript's
   implicit string→number coercion for `>=` (works by accident today with a string operand, but
   is exactly the kind of implicit-numeric-conversion-on-money-adjacent-value pattern this
   ticket is fixing on the web side). Needs a string-safe sign check once `revenue.change` is
   typed `string` (e.g. check for a leading `-`, or route through a decimal-safe comparison
   helper — mirroring `lib/format.ts`'s own docstring: "String-safe display formatting for money
   and percentages... must never round-trip them through floats").

3. **`erp-mobile/src/lib/format.ts:20`** — `export function formatPercent(change: number): string`
   must widen its parameter to `string | number` (mirroring `formatMoney`'s existing signature at
   line 10, which already accepts `string | number`). The function body (`change > 0`,
   `String(change).replace('.', ',')`) is largely compatible already, but the type signature is
   the actual compile break once `dashboardApi.ts` changes `revenue.change` to `string`.

4. **`erp-mobile/app/(app)/index.tsx:131`** — `formatPercent(stats.revenue.change)` call site.
   This is where the TypeScript compile error will surface first once (1) lands; it resolves
   once (3) widens `formatPercent`'s signature.

5. **No change needed**: `Amount` (`erp-mobile/src/ui/atoms/Amount.tsx:10`) already types its
   `value` prop `string | number`, and `formatMoney` (`erp-mobile/src/lib/format.ts:10`) already
   accepts `string | number` — `revenue.current`/`revenue.previous` flow through the existing
   `<Amount value={stats.revenue.current} format={formatMoney} .../>` usage
   (`index.tsx:110-115`) with zero code change once the type in (1) is corrected.

Per the mobile advisory above, mobile DTOs are hand-written (no codegen), so this is a manual
mobile-side PR — not automatically picked up from this ERP change.
