# C5 — "Trésorerie" Finance Overview Page

> Chunk C5 of the treasury demo plan (`docs/superpowers/audits/2026-07-02-treasury-demo-gap-audit-and-plan.md`). Frontend, ~90% reuse. Soft-depends on C3 (`GET /reports/upcoming-payments`) — if it's merged, use it; otherwise build against `/reports/overdue-summary` + aged AR/AP due_date fields and leave a marked seam. TDD: Vitest failing test first per component.

## Goal

One unified overview page (French-market "Trésorerie" pattern: cash position + expected in + expected out) at `/finance/overview`, becoming the finance landing surface for the demo.

## Composition (top to bottom)

1. **Cash position row** — `StatCard`s: Total Cash (Σ repository balances), then per-type subtotals (tills / bank accounts / safe). Data: existing repositories hook (`usePaymentRepositories` in `features/treasury`) — balances are strings; sum with the project's decimal helpers (`@/lib/decimal`), NEVER parseFloat.
2. **`FinanceWidget`** — `features/finance/components/FinanceWidget.tsx` is built + tested (assets/liabilities/net income MTD+YTD/AR/AP via `useFinanceSummary` → `/reports/finance-summary`) but mounted NOWHERE. Mount it. Tokenize/adjust styling only if it clashes.
3. **Upcoming money IN / OUT** — two side-by-side lists (customer receipts expected / supplier+expense payments due), sorted by due date ascending, overdue rows highlighted with token danger colors, aging-bucket summary chips (current/1-30/31-60/90+). Data: C3 endpoint if available; fallback per above.
4. **Trend chart** — echarts via `features/owner-dashboard/components/OwnerChart.tsx`: revenue-vs-expenses or cash-in-vs-out over the period, whatever the available endpoint supports (`/reports/profit-loss` monthly points or sales summary rollup via `rollupSalesByPeriod.ts`). Keep honest — label the chart by what it actually shows.

## Wiring

- Route: lazy import + `<Route>` + `<RequirePermission permission="reports.view">` + `<SuspenseWrapper>` in `routes/index.tsx` (finance block ~1652-1781) — follow `conventions/02-NAVIGATION-ROUTING.md`.
- Sidebar: entry at the TOP of the `accountingAndReports` group (`components/organisms/Sidebar/Sidebar.tsx:255-281`), same permission key as the route.
- Finance hub: add a `HubCard` on `FinanceHubPage.tsx`.
- i18n: keys in the existing `finance` namespace, en/fr/ar (fr label: "Trésorerie"). Namespace already registered — do NOT touch `i18n.ts` registration.

## Constraints

- Design tokens exclusively (new feature directory = ESLint error on hardcoded colors).
- `apiGet` already unwraps `response.data.data` (rule 14); paginated `{data,meta}` endpoints need `api.get` + `response.data`.
- No new dependencies (echarts already bundled; no recharts, no calendar lib — lists, not a calendar grid).
- TanStack Query 5 patterns per `conventions/05-REACT-QUERY.md`; TS strict, no `any`.
- Tests: rendered-output assertions (mock hooks/providers with `vi.mock`); cover: cash sum correctness from string balances, overdue highlighting, empty states, permission gating.
- Commit style: `feat(finance): trésorerie overview page`.

## Verify

`pnpm typecheck && pnpm lint` + your Vitest files by path. Screenshot note for the merger if no dev server available.
