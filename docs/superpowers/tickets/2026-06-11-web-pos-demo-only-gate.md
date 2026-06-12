# Web POS: gate to demo accounts only, then kill

> **Owner decision (2026-06-11):** the browser POS surface stays alive ONLY for
> the demo account — to test things quickly when needed — and is definitely
> killed at some point. It must NOT be usable by real tenants.

## Why

Device-authority (v3) is the locked fiscal model: the Tauri terminal authors
and signs receipts, owns the hash chain, closes the Z. The web POS has no
chain to sign with, and its shift lifecycle invites exactly the complexity we
do not want to handle: a user opens a shift in the browser tied to one store,
re-ties to another in the afternoon, forgets to close — server-side shifts
with no device anchor, drifting against the fiscal record. B3 already 409s
remote Z-close for v3 terminals and the refund work removed web-admin
returns; this ticket finishes the containment.

## Current exposure (surveyed 2026-06-11)

A real tenant user can still, from the browser:
- open/close shifts: `apps/web/src/features/pos/api/shiftApi.ts:87,94` →
  server `ShiftManagementService` (`pos_shifts` on PG)
- reach a full selling UI: `apps/web/src/features/pos/pages/POSPage`,
  `PosHubPage`, `ShiftDashboardPage`, `KitchenDisplayPage`,
  `TableManagementPage`, `ZReportListPage`/`ZReportDetailPage`
- `apps/web/src/pages/POS/POSShiftsDashboard.tsx` (older dashboard surface)

## Required gate (interim, until kill)

1. **Server-side enforcement (the real gate)** — middleware/policy on the web
   POS routes (shift open/close, web sale, table/kitchen flows): allow only
   the demo tenant(s); 403 `WEB_POS_DEMO_ONLY` otherwise. Decide the demo
   marker: tenant flag (e.g. `tenants.is_demo`) vs plan vs config allowlist.
   Frontend hiding alone is NOT enforcement.
2. **Web nav** — hide the POS hub for non-demo tenants (UX, not security).
3. **Keep read-only surfaces** for everyone: Z-report list/detail and shift
   HISTORY remain useful back-office views of device-synced data. Only the
   mutating flows (open/close shift, sell) are gated.
4. Do NOT touch the back-office `DEPOSIT_RECEIPT` flow (server-authored via
   virtual admin terminal) — that is a deliberate, compliant server path.

## Kill criteria (phase 2)

When the demo workflow is replaced (e.g. seeded Tauri demo terminal or
Playwright against the device build), delete the web selling surface
entirely: pages above + `shiftApi.openShift/closeShift` + dead server routes.
Before deletion, run the cross-app deprecation sweep
(`feedback_cross_app_deprecation_check`): grep apps/pos, apps/web, apps/api,
packages, seeders, console commands, CI for every route/helper being removed.

## Non-goals

- No server-authored web SALE path (NF525 server register) — explicitly not
  wanted; the complexity is the reason for this decision.

## Status 2026-06-11 (same day)

Server-side gate SHIPPED (`EnsureWebPosDemoTenant` + central `tenants.is_demo`
default false + the six routes wrapped). Web POS mutating actions now 403
`WEB_POS_DEMO_ONLY` for every real tenant ("disabled by default" achieved).
REMAINING: expose `is_demo` in the auth payload (AuthUserData) and hide the
POS hub nav for non-demo tenants ("hidden"), and decide the flagging process
for the pharmacy demo account (one UPDATE on synerivia_central once decided).
