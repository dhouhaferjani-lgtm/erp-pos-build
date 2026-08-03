# API shape change: `GET /dashboard/stats` — revenue fields become decimal strings

> Created 2026-08-03. Cross-repo contract record for the ERP backend → `erp-mobile` client
> boundary. Full defect analysis, rulings, and the line-by-line mobile punch list live in
> `docs/superpowers/tickets/2026-08-02-dashboard-stats-status-buckets-and-float-sum.md` (§
> RESOLUTION, § AMENDMENTS, § MOBILE FOLLOW-UP) — this doc is the short-form "what changed on the
> wire" contract for anyone integrating against the endpoint; that ticket is the detailed
> handover for the mobile team specifically.

## What changed

`GET /dashboard/stats` (`apps/api/app/Modules/Dashboard/Presentation/Controllers/DashboardController.php`)
`data.revenue` shape:

| Field | Before | After |
|---|---|---|
| `revenue.current` | JSON number (float, from SQL `sum()`) | decimal string, scale 3 (e.g. `"125183.110"`) |
| `revenue.previous` | JSON number (float) | decimal string, scale 3 |
| `revenue.change` | JSON number (float, e.g. `25.0`) | decimal string, 2dp (e.g. `"25.00"`, `"-10.50"`), **or `null`** when there is no meaningful previous-period baseline (previous revenue `<= 0` — e.g. a tenant's first month, or a negative previous from a data anomaly) |

`data.payments.{received,pending}` are **unchanged** — already decimal strings before this
change.

Also, unrelated to the wire shape but behaviorally relevant to anyone building a KPI dashboard
against this endpoint: the underlying bucket definitions changed —
- Revenue/pending now include `DocumentStatus::Paid` invoices, not just `Posted` (a paid invoice
  is still revenue).
- `payments.pending` is now `SUM(documents.balance_due)` over the same Posted∪Paid base — not
  derived from `payments.received` at all.
- Overdue-invoice count stays `Posted`-only (a paid invoice past due is settled, not overdue).

See the ticket for the full rationale, the H1/M1/M2/L4 defects found in the first implementation
pass and how they were fixed, and the live-tenant verification numbers.

## Who is affected

- **Web** (`apps/web/src/features/dashboard/Dashboard.tsx`) — already updated in this change
  (same commit series). Confirmed the only production web consumer via repo-wide grep.
- **`erp-mobile`** (`app/(app)/index.tsx`, `src/features/dashboard/`) — **NOT updated by this
  change** (out of scope for the ERP-repo task that produced it). See the ticket's § MOBILE
  FOLLOW-UP for the exact files/lines that need a manual mobile-side PR. Mobile DTOs are
  hand-written (no codegen), so this does not propagate automatically.
- **Runtime impact on the currently-deployed mobile app**: verified non-breaking (no
  `TypeError`, no white screen) — mobile's `formatMoney`/`Amount` already accept `string |
  number`, and the `>=`/`formatPercent(number)` call sites work today via JS's implicit
  string-to-number coercion. The type-correctness fix is not an emergency mobile release; it
  should land soon so a future mobile change doesn't reintroduce float arithmetic against a value
  that now travels as a string (or crash on a `null` `change` it doesn't expect).

## Status

Backend + web shipped (ERP repo, branch `dev`, local — not yet promoted to `origin/dev`).
Mobile-side PR not started; tracked as a manual follow-up in the ticket referenced above.
