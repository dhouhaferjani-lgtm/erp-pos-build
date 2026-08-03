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

See the ticket for the full rationale, the H1/M1/M2/M4/L1/L4/N1/N2 defects found across the
implementation and two gate/re-check rounds and how they were fixed, and the live-tenant
verification numbers (including the exact-match `payments.received = 63315.979` cross-check
after the N1 fix).

## Who is affected

- **Web** (`apps/web/src/features/dashboard/Dashboard.tsx`) — already updated in this change
  (same commit series). Confirmed the only production web consumer via repo-wide grep.
- **`erp-mobile`** (`app/(app)/index.tsx`, `src/features/dashboard/`) — fix **PREPARED as a local
  commit**, not yet pushed or released: repo `/Users/houssamr/Projects/syneriva/erp-mobile`,
  branch **`fix/dashboard-stats-string-shape`**, commit `2c25da7` (based on `main` @ `5a90341`).
  The owner reviews/pushes/releases this commit — it was not merged or pushed from this task. See
  the ticket's § MOBILE FOLLOW-UP for the line-by-line record of what it changes. Mobile DTOs are
  hand-written (no codegen), so nothing here propagates automatically without that commit.
- **Runtime impact on the currently-deployed (pre-fix) mobile app — CORRECTED 2026-08-03 (gate
  re-check N2):** the original version of this note said "verified non-breaking (no `TypeError`,
  no white screen)" and is **no longer accurate as a blanket statement**. That was true for the
  value domain evaluated at the time (a `revenue.change` that was always a string), but
  `revenue.change` can now also be `null` (the AMENDED M2 ruling) — a value the deployed mobile
  client has never seen. Simulating the deployed code against `null`: `String(null)` → `"null"`,
  and `(null ?? 0) >= 0` → `true`, so the deployed app renders a **green ↑ "null %"** for any
  tenant with no previous-period revenue (e.g. first-month tenants) — not a crash, but a visible,
  user-facing regression from the prior `"0 %"`. This is exactly what the prepared mobile commit
  above fixes (renders `'—'`, no arrow, on `null`). Still true and unaffected by this correction:
  no `TypeError`, no white screen, for every value the deployed app WAS already handling
  (`formatMoney`/`Amount` already accept `string | number`; the sign-check/`formatPercent`
  call sites tolerate a numeric string via JS's implicit coercion).

## Status

Backend + web shipped (ERP repo, branch `dev`, local — not yet promoted to `origin/dev`).
Mobile fix prepared as a local commit (`2c25da7` on `fix/dashboard-stats-string-shape`, erp-mobile
repo) — not pushed; owner reviews/pushes/releases.
