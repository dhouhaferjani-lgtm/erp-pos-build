# CODEX Chunk B — Owner Dashboard Demo: Frontend

**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.dashboard-demo-fe` (branch `feat/owner-dashboard-demo-fe`, based on origin/dev). Work ONLY here, and ONLY under `apps/web/`.
**Context:** Sales demo 2026-07-03 (Tunisia parapharmacy, multi-branch). The owner dashboard lives at `/reports`: `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`, hooks in `hooks/useOwnerReports.ts`, widgets in `components/`, charts via echarts wrapped by `OwnerChart.tsx`. A parallel backend chunk is adding: `hour` granularity on `/reports/sales/by-location`, and a new `GET /reports/sales/live` endpoint. Build against the shapes below; do not wait for the backend.

## Ground rules (violations = rejected review)
- **TDD** with Vitest — follow `OwnerDashboardPage.test.tsx` patterns (component tests may `vi.mock` hooks).
- TypeScript strict, no `any` (use `unknown` + guards). Money values arrive as STRINGS — never `parseFloat`/`Number()` on money; render via the existing `formatCurrency` util used by current widgets.
- All user-facing text via `t()` (react-i18next). Find the owner-dashboard namespace used by existing widgets and add keys for ALL supported languages (check `apps/web/src/i18n.ts` and existing locale files — likely en/fr/ar; mirror existing key structure).
- Design tokens from `@/lib/designTokens` (`tokens`, `textColors`, `borderColors`) — no hardcoded Tailwind colors. Chart colors via the existing `chartColors` used by `OwnerChart`.
- `apiGet` already unwraps `response.data.data` — do NOT double-unwrap.
- Do not touch `apps/api/` or files outside the owner-dashboard feature + its route/i18n wiring.
- Verify with `pnpm test <path>`, `pnpm typecheck`, `pnpm lint` (changed files) before finishing.

## Task 1 — Date presets + Today default + hour granularity
- `components/OwnerDashboardFilters.tsx` + default range in `OwnerDashboardPage.tsx:24-35`: add quick presets **Aujourd'hui / 7 jours / 30 jours** (i18n keys), default = **Today**.
- Add `hour` to the granularity options; when the selected range is a single calendar day, granularity auto-selects `hour` (user can still override to `day`).
- Tests: default state is today+hour; preset clicks update the query params passed to hooks.

## Task 2 — Hourly trend: today vs same weekday last week
- `components/SalesTrendChart.tsx` (+ `lib/rollupSalesByPeriod.ts`): when the range is a single day with `granularity=hour`, fetch a SECOND series for the same day −7 days (same weekday last week) via the existing by-location hook/endpoint, and render it as a dashed "ghost" line behind today's solid line. Legend labels i18n'd ("Aujourd'hui" / "Même jour sem. dernière" style — use t()).
- X axis = hours (08:00…20:00); hide empty leading/trailing hours only if both series are empty there.
- Tests: rollup produces hour buckets; comparison series requested with −7d range.

## Task 3 — Live sales feed widget (the "alive" element)
- New `components/LiveSalesFeed.tsx` + `useLiveSales` hook in `hooks/useOwnerReports.ts` calling `GET /reports/sales/live` with TanStack Query `refetchInterval: 15000`, `refetchIntervalInBackground: false`.
- Endpoint response shape (STRINGS for money):
```json
{
  "recent_receipts": [
    { "id": "...", "posted_at": "2026-07-03T13:32:11Z", "location_id": "...", "location_name": "Branche Lac 2", "total": "86.400", "currency": "TND", "items_count": 3, "receipt_number": "..." }
  ],
  "open_shifts_by_location": { "<location_id>": 2 },
  "generated_at": "..."
}
```
- Render last ~8 receipts: local time (HH:mm) · branch name · formatted amount · items count. New rows since the previous poll get a soft fade-in/highlight animation (CSS transition, no jarring reflow). Header shows a pulsing "live" dot + "updated just now / Xs ago" (i18n).
- Tests: renders receipts, formats money via formatCurrency, new-row highlighting logic.

## Task 4 — Branch leaderboard
- New `components/BranchLeaderboard.tsx`: ranked list (not a chart) of branches by today's sales — rank #, branch name, activity dot (green if `open_shifts_by_location[location_id] > 0` from the live hook, gray otherwise), today's total, and delta % vs same weekday last week (green ▲ / red ▼).
- Data: reuse the by-location hook for today (hour or day granularity, summed per location) + a −7d same-day query for the baseline; open-shift dots from `useLiveSales`.
- Delta math must avoid float artifacts on display: compute percentage from numeric aggregation of the API's numbers only for the delta % (percent is not currency-scaled), but never re-derive money amounts — display the API totals via formatCurrency.
- Tests: ranking order, delta sign rendering, activity dot on/off.

## Task 5 — Layout + auto-refresh polish
- Reorder `OwnerDashboardPage.tsx` grid: Row 1 `SalesSummaryCards` (unchanged), Row 2 hero: `SalesTrendChart` (2/3 width) + `BranchLeaderboard` (1/3), Row 3: `LiveSalesFeed` + `TopSkusWidget` + `RevenueByCategoryDonut`, Row 4: `PaymentMethodBreakdownPie` + `LowStockAlertsList` + `CashRegisterReconciliationTable`. Keep responsive (existing grid utilities).
- When the selected range includes today, set `refetchInterval: 60000` on the summary/by-location/category/top-sku queries; add a subtle pulse dot + "live" label on the Total Sales stat card.
- Tests: layout smoke (widgets render), refetchInterval only set when range includes today (unit-test the option-builder helper rather than timers).

## Deliverable
Commits on `feat/owner-dashboard-demo-fe` (one per task ideally), do NOT push. Report: files changed, tests added + pass output, typecheck/lint status, i18n keys added per language, any deviation from spec.
