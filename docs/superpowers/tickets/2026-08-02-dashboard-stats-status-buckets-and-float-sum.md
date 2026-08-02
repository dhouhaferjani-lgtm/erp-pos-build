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
