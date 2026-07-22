# Gate 4 FE Verdict — Wave 4 analytics/dashboards + Task 0 DTO follow-ups (`feat/multi-location` @ 0945d39e0)

> Controller-run 2026-07-22. Reviewer: frontend-conventions-reviewer (Opus). Request: `.gates/gate-4-request.md`.

Reviewed the FE portion of `git diff origin/dev...0945d39e0`. Backend PHPUnit owned by parallel reviewers — not run here.

## Verification (re-run at the review tip, trusted nothing reported)
- `pnpm typecheck` — **GREEN** (`tsc --noEmit`, no output).
- `pnpm lint` — **0 errors**, 6473 pre-existing warnings. `audit:keys` (tanstack) 0 new; `audit:design-system` **745 acknowledged / 0 new / 0 stale** (down 1 from 746 — the removed OwnerDashboardFilters raw button/checkbox baseline entry); eslint-rules RuleTester 10/10.
- `pnpm vitest run` on all 14 touched FE test files — **38 passed / 38** (13 files + the sibling `AnalyticsDashboardPage.test.tsx` run separately: 33 + 5). Hung workers killed → 0 remaining.

## 1. Task 0 closure of the Gate-3b deferred minor — VERIFIED FIXED
- `finance/types.ts`: the inline `UpcomingLocationBucket` augmentation on `UpcomingPaymentsData` is **gone**; TreasuryOverviewPage consumes the generated `UpcomingPaymentsData`, which now carries `buckets_by_location` at generated.d.ts:289.
- `InstrumentListPage.tsx:79`: the local `MaturityResponse`/`BucketTotal` interfaces are **deleted** and replaced by `type MaturityResponse = App.Modules.Treasury.Application.DTOs.MaturingInstrumentsData`; `useMaturingInstruments.ts:19-22` likewise re-exports the generated DTOs.
- `generated.d.ts` diff is transformer-shaped: new `MaturingInstrument{Bucket,Buckets,Row,s,sMeta}Data` (generated.d.ts:2045-2083), `LocationReportBucketData` unified with the superset `{location_id,location_name,total,count,total_in,total_out,net}` (generated.d.ts:177), `buckets_by_location` added to aged/upcoming DTOs, `location_id` added to `UpcomingPaymentLineData` and the maturing row. No hand-edit anomalies.

## 2. Plan tasks — VERIFIED
- **SalesByLocationChart money→chart fix**: `Number(...)` replaced by `toChartNumber` (SalesByLocationChart.tsx:31), which is `toBig(value).toNumber()` (rollupSalesByPeriod.ts:60-62) — money strings pass through Big.js at the render boundary, not `Number()`. Colors token-driven via `chartCategoricalKeys.map(k => chartColors[k])` (SalesByLocationChart.tsx:22; designTokens.ts:764). SalesByLocationChart.test.tsx:34 asserts exact decimal preservation (`1234567.899`) and per-location series — meaningful, not smoke.
- **Scope wiring**: every scope-dependent query key uses `locationScopedKey([...], scope)` — useOwnerReports.ts (9 hooks), useAnalytics.ts (8 hooks), InstrumentListPage.tsx:113,124, useMaturingInstruments.ts:20, ZReportListPage.tsx:39,47. The `location_ids[]` param is carried into every fetch (AnalyticsDashboardPage.tsx:55; OwnerDashboardPage.tsx:79/89/101; ZReportListPage.tsx:30; analyticsApi.ts:81 / reportApi.ts:108). AnalyticsDashboardPage.scope.test.tsx:14-17 asserts `location_ids` reaches every analytics query; BranchLeaderboard takes `locationIds` and threads it into both `useSalesByLocation` calls (BranchLeaderboard.tsx:33-34, fed `effectiveLocationIds` from OwnerDashboardPage.tsx:141).
- **OwnerDashboardFilters** correctly drops the local location-checkbox UI in favor of the global `useViewScope`, and migrates preset buttons to the `<Button>` atom — net design-system improvement (baseline −1).

## 3. Standing conventions
- **i18n (Gate-3b MAJOR class) — CLEAN.** Every new key exists in en, fr, AND ar: `reports:ownerDashboard.{cashAcrossStores,dueThisWeek,rebalanceAlerts}.*`, `reports:ownerDashboard.salesTrend.seriesMode.{label,rollup,per-location}`, `pos:zReports.{location,terminalColumn}`.
- No `any`; new widget/chart types explicit. Money via `formatCurrency`/`bcadd`/`formatQuantity` (no `parseFloat`/`Number()` on money). `apiGet` single-unwrap vs `api.get` raw both correct on the new endpoints.

## Findings

- **[MAJOR] DueThisWeekWidget.tsx:32 — money is formatted with a hardcoded `currency: 'TND'`** instead of the company currency. `formatCurrency(amount, { currency: 'TND' })` mislabels the maturing-instruments total for every non-TND tenant (France/Italy EUR, UK GBP are in-market). The correct source is readily available and used two files away (`InstrumentListPage.tsx` reads `currentCompany?.currency`; the sibling `CashAcrossStoresWidget.tsx:24` correctly uses `position.currency`) — the inconsistency within the same diff marks this an oversight. The amount value is correct (summed via `bcadd`), only the currency label is wrong, and the test masks it (DueThisWeekWidget.test.tsx mock supplies no currency). **Fix:** read the company currency from `useCompanyStore` and pass it to `formatCurrency`.

- **[MINOR] DueThisWeekWidget.tsx:26-29 — the "due this week" figure sums `total_in + total_out`** (inbound receivables + outbound payables) into one amount. Mixing directions into a single total may misrepresent net exposure; confirm against the plan's intended semantics (gross maturing value vs net). **Fix:** if net was intended, subtract; otherwise label as gross.

- **[MINOR] finance/types.ts:326 — `LocationReportBucket` remains a hand-declared FE interface** duplicating the now-generated `LocationReportBucketData` (narrower subset). Consistent with this file's existing pattern of hand-mirrored report DTOs and outside Task 0a's exact target, so non-blocking. **Fix (optional):** consume the generated type.

- **[MINOR] useOwnerReports.ts:127 — `useLiveSales` key now includes `scope` but `fetchLiveSales()` sends no `location_ids`.** Scope changes trigger a refetch that returns the same unfiltered global feed (harmless, likely intended for a cross-store live feed). **Fix (optional):** thread scope into the request or drop it from the key.

- **[MINOR] ZReportListPage.tsx:224-256 — the Z-report list is still a raw `<table>`** (the diff only adds two columns to a pre-existing raw table). Baselined (audit reports 0 new), so not a new violation. **Fix (optional):** migrate to `DataTable` in a follow-up.

## Blocking item
One MAJOR: DueThisWeekWidget.tsx:32 renders maturing-instrument money with a hardcoded `TND` currency, producing a wrong currency label for non-TND tenants, when the company currency is trivially available and used in sibling code. Everything else — Task 0 DTO closure, scope wiring, the money→chart fix, and full en/fr/ar i18n coverage — is verified clean, with typecheck/lint/tests all green.

VERDICT: REJECT
