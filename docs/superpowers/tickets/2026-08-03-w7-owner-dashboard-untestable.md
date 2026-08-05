# The owner dashboard (`/reports`) cannot be asserted against — testability defect on a launch-critical screen

**Raised by:** W-7 of the pre-launch full-E2E money campaign, fix round 1
(orchestrator ruling: "the refetchInterval-never-settles testability defect on a
launch-critical screen gets its own ticket").
**Severity:** P2 (no wrong number is shown — the screen simply cannot be
verified automatically, which is why the money it renders is currently
unverified).
**Surface:** `apps/web/src/features/owner-dashboard/*`, route `/reports`.

## What happened

W-7 set out to assert `MTP-MLC-02/03/06`'s UI half on `/reports` — the owner
dashboard, the screen a shop owner actually looks at. It could not, for two
compounding reasons, and the cases were re-pointed at
`/inventory/stock-by-location` (recorded as a substitution in
`docs/sessions/MONEY-CAMPAIGN-RESULTS.md` § W-7; the orchestrator ACCEPTED it).

**1. The tiles never settle.** `useOwnerReports.ts:34-36`
(`buildOwnerReportRefreshOptions`) attaches `refetchInterval: 60000` to every
query whose date range includes today. Each refetch puts the tiles back into
their "Loading report…" placeholder, so any wait-for-quiescence gate — the
`settleAfterNav` pattern every other money-campaign spec uses, `networkidle`,
or a "no loading text" poll — times out on a page that is behaving exactly as
designed. Live evidence: a 30 s no-loading gate expired with five
`Loading report…` paragraphs still in the DOM snapshot.

**2. The default range hides the only data there is.** The dashboard opens on
TODAY. The tenant's POS receipts are 1-2 days old, so every money tile
truthfully reads "No data for this period" — there is nothing to assert without
first driving the range control, which returns us to (1).

## Why this is worth fixing rather than working around

- `/reports` is the **F-2 surface**: `sales/by-location`, `top-SKUs`,
  `revenue-by-category` and `payment-method-breakdown` are exactly the endpoints
  whose money is emitted float-cast, scale-2 and zero-trimmed
  (`docs/superpowers/tickets/2026-08-03-w7-cross-cutting-findings.md`, F-2). The
  one screen where that defect is visible to an owner is the one screen the
  campaign cannot assert against.
- **W-7 leaves the owner dashboard with NO UI-layer coverage** (recorded as a
  §Z-adjacent debt). Its money is verified at the API layer only.

## What a fix looks like (suggestions, not a design)

Any one of these would unblock automated assertion:

1. A **deterministic test seam** — e.g. suspend `refetchInterval` when a
   `?refresh=off` / `data-testid` hook is present, or expose a stable
   `data-loaded="true"` attribute per tile that survives background refetches.
2. **Keep the previous data visible during a background refetch**
   (`placeholderData: keepPreviousData`) so a refetch stops blanking the tiles
   into placeholders — better UX independently of testing.
3. Make the **date range URL-addressable** (`/reports?from=…&to=…`) so a test —
   or a user sharing a link — can open a specific window without driving the
   control.

(2) and (3) are user-visible improvements in their own right; (1) is the minimum.

## Acceptance

`MTP-MLC-02/03/06`'s UI half can be re-pointed back at `/reports` and assert,
against a chosen date range, that each money tile matches the same endpoint read
in the same run — the pattern `MTP-GL-27` already uses successfully on
`/finance/overview`.
