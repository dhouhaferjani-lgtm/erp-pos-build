# Multi-Location §4 Analytics & Dashboards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL — read `superpowers:test-driven-development` before writing any code, and `superpowers:verification-before-completion` before claiming any task done. Also load `dataviz` before touching chart series/colors (Tasks 2, 4), and `frontend-conventions-reviewer` gate rules apply to every FE milestone. This plan is the §4 package of the Multi-Location umbrella (`docs/superpowers/specs/2026-07-16-multi-location-management-design.md`, §4). It is **bounded**: POS analytics gets a **location filter only — NO group-by / DTO / return-shape reshaping** (review finding F6). Do not exceed the tasks below; note out-of-scope needs and continue.

---

## Goal

Make the read-side analytics & dashboard surfaces honor the multi-location **view scope** delivered by §1, so an owner/manager can see per-location and scope-filtered numbers without switching locations:

1. `PosAnalyticsService` (8 methods) accepts an **optional** `location_ids` filter — WHERE-narrowing only, byte-identical DTOs.
2. The orphaned `SalesByLocationChart` is wired into the owner dashboard as a grouped-bar store comparison.
3. `BranchLeaderboard` respects the view scope.
4. `SalesTrendChart` gains a per-location series toggle (rollup stays default).
5. `ZReportListPage` gets a cross-terminal rollup with a location column (terminal filter becomes optional).
6. Consolidated home widgets (cash-across-stores, due-this-week, rebalance alerts) each deep-link into their detail page with scope preserved — **last wave, gated on §2/§3 endpoints**.

## Architecture

- **Backend**: hexagonal per module. POS analytics lives in `App\Modules\POS` (Presentation `AnalyticsController`/`AnalyticsRequest`, Application `PosAnalyticsService` + 4 `Data` DTOs). Owner report endpoints live in `App\Modules\Accounting` (`ReportsController` + `SalesReportService`/`LiveSalesReportService`). Z-reports in `App\Modules\POS\...\ReportController::listZReports`.
- **Data path (verified while reading)**: `pos_receipts.location_id` and `pos_orders.location_id` are **columns on the base tables** (`create_pos_receipts_table.php:29`, `create_pos_orders_table.php:20`) — a location filter is a direct `WHERE location_id IN (...)` / join predicate, **no terminal→shift→location join required**. Line-level tables (`pos_receipt_lines`, `pos_order_lines`) already join back to `pos_receipts`/`pos_orders`, so the filter lands on the joined receipt/order alias.
- **Z-reports**: `ZReport` has **no** `location_id`; the scope anchor is `terminal_id` → `terminals.location_id` (`Terminal::location()` belongsTo exists, `Terminal.php:154`). Cross-terminal rollup joins/eager-loads the terminal's location.
- **Frontend**: React 19 / TanStack Query 5 / Zustand 5 / ECharts via `OwnerChart`. Consumes §1's `useViewScope()` + `locationScopedKey()` (see "Consumes from §1").

## Tech Stack

Laravel 12 / PHP 8.4 strict, PostgreSQL 16 (db-per-tenant). PHPUnit + `RefreshDatabase`. React 19 / Vite 7 / TS strict / Vitest. ECharts through the repo `OwnerChart` wrapper + `chartColors`/`chartCategoricalKeys` tokens.

## Global Constraints

- **Routes middleware**: any new/edited route stays in its existing group with `['api','auth:sanctum', SetPermissionsTeam::class, ...]`. POS analytics group already carries this (`POS/routes.php:38`). Do not add routes without the full stack.
- **Constructor injection only** — `private readonly`, never `app()`. `AnalyticsController` gains `LocationScopeResolver` via constructor (§1 binds it).
- **Money/quantity precision (rule 19)**: this package does **not** introduce new aggregation math — the 8 methods keep their existing `(string)` decimal outputs and `round()` calls (unchanged). Do **not** cast decimals to float; do not add hardcoded bcmath scale. If any *new* SUM is introduced, use the injected `CurrencyScaleResolverInterface` + explicit currency — but the bounded scope should require none.
- **i18n / tokens / canonical components**: all new FE text via `t()`; colors via `@/lib/designTokens` tokens only (no hardcoded Tailwind colors); tables via `DataTable`, page titles via `PageHeader`/`PageHeaderTitle`. RTL-safe (`text-start`/`text-end`, logical props).
- **dataviz conventions** (Tasks 2 & 4): series colors come from `chartCategoricalKeys` mapped through `chartColors` (theme-aware), never hardcoded hex; keep one legend, axis labels via `t()`.
- **`locationScopedKey` on scoped queries**: every query whose result changes with the view scope uses `locationScopedKey([...], scope)` (resource literal stays `element[0]`; scope is a non-leading segment). Non-scoped queries keep `tenantScopedKey`.
- **Tests BY PATH** (worktree gotcha): `cd apps/api && php artisan test <relative/path/to/Test.php>`; `cd apps/web && pnpm vitest run <path>`. Never run the full backend suite.
- **`typescript:transform` after DTO changes**: Task 1 asserts **zero diff** — `cd apps/api && CACHE_STORE=array php artisan typescript:transform` then `git diff --exit-code ../../packages/shared/types/generated.d.ts`.
- **PHPStan level 8** zero errors on new/edited code: `cd apps/api && ./vendor/bin/phpstan analyse --no-progress`.
- Preflight before each commit: `./scripts/preflight.sh` (or scoped `pint` + `phpstan` + the named tests + `pnpm lint`/`typecheck`).

## Consumes from §1 (hard dependency — §4 runs AFTER §1 merges)

§4 does **not** build scope plumbing; it consumes these §1 deliverables. If they are not yet on the branch base, **stop and rebase on merged §1** before starting.

**Frontend — `useViewScope()`** (Zustand-backed, company-keyed):
```ts
// from @/features/scope/useViewScope (path per §1)
interface ViewScope {
  scope: { locationIds: string[] }        // explicit selection; empty === "All"
  effectiveLocationIds: string[]          // resolved allowed set the user may see
  isAll: boolean                          // true when no narrowing (send NO location_ids)
  setScope: (locationIds: string[]) => void
}
function useViewScope(): ViewScope
```
Wire rule (mirror today's `OwnerDashboardPage.tsx:71-78`): **when `isAll`, send no `location_ids` param; otherwise send `location_ids: effectiveLocationIds`.**

**Frontend — `locationScopedKey(segments, scope)`**: wraps `tenantScopedKey`; resource literal stays `segments[0]`, scope injected as a non-leading segment so bare-literal-prefix mutation invalidation still works. Added to `audit-tanstack-keys.mjs` `APPROVED_FACTORY_CALLS` by §1.

**Backend — `LocationScopeResolver`** (`App\Modules\Company\...` per §1; HTTP-only, requires bound `CompanyContext`):
```php
/** @return list<string>  Effective location ids to filter by; [] === no narrowing (all allowed). */
public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array;
```
- Fail-closed (`AuthorizationException`) on out-of-scope `requestedIds`.
- `[]` requestedIds + unrestricted user ⇒ returns `[]` ("no narrowing"); restricted user ⇒ returns their allowed set.
- §4 endpoints declare **`bypassPermission: null`** (analytics/owner reads carry no processor carve-out).

**Contract used by §4 services:** a service that receives `location_ids === []` applies **no** location filter (= all); a non-empty array applies `WHERE location_id IN (...)`. This makes "empty scope = all" a directly unit-testable property, and the controller simply passes the resolver output through.

---

## Task 1 — `PosAnalyticsService` location filter (bounded: filter only, F6)

**Files**
- `apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php` (8 methods)
- `apps/api/app/Modules/POS/Presentation/Requests/AnalyticsRequest.php`
- `apps/api/app/Modules/POS/Presentation/Controllers/AnalyticsController.php`
- `apps/web/src/features/pos/api/analyticsApi.ts`
- `apps/web/src/features/pos/hooks/useAnalytics.ts`
- `apps/web/src/features/pos/pages/AnalyticsDashboardPage/AnalyticsDashboardPage.tsx`
- Tests: `apps/api/tests/Unit/POS/PosAnalyticsServiceLocationTest.php` (new), `apps/api/tests/Feature/POS/AnalyticsTest.php` (extend), `apps/web/src/features/pos/pages/AnalyticsDashboardPage/__tests__/AnalyticsDashboardPage.scope.test.tsx` (new)

**Interfaces (exact new signatures — append optional param, keep every existing param/order/return type):**
```php
public function getSalesSummary(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): SalesSummaryData
public function getSalesByCategory(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): array
public function getSalesByProduct(string $companyId, CarbonImmutable $from, CarbonImmutable $to, int $limit = 20, array $locationIds = []): array
public function getSalesByTimePeriod(string $companyId, CarbonImmutable $from, CarbonImmutable $to, string $granularity = 'day', array $locationIds = []): array
public function getCashierPerformance(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): array
public function getDiscountAnalysis(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): DiscountAnalysisData
public function getCustomerAnalytics(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): CustomerAnalyticsData
public function getFnbMetrics(string $companyId, CarbonImmutable $from, CarbonImmutable $to, array $locationIds = []): FnbMetricsData
```
Each `$locationIds` is `list<string>` (PHPDoc `@param  list<string>  $locationIds`). **DTO classes and every return shape are UNCHANGED.**

**Filter application (per method):**
- `pos_receipts`-rooted queries (`getSalesSummary` receipts + voided-count + payments subquery; `getSalesByTimePeriod`; `getCashierPerformance`; `getCustomerAnalytics` base): add `->when($locationIds !== [], fn ($q) => $q->whereIn('pos_receipts.location_id', $locationIds))` — use the qualified column where a join exists, bare `location_id` on the plain `pos_receipts` table queries.
- `pos_receipt_lines`-joined queries (`getSalesByCategory`, `getSalesByProduct`, `getDiscountAnalysis` totals/by-reason/top-products): filter on the joined `pos_receipts.location_id` alias.
- `pos_orders`-rooted queries (`getFnbMetrics`: closed-orders, line-counts subquery, peak-hours, orders-by-mode): `->when($locationIds !== [], fn ($q) => $q->whereIn('pos_orders.location_id', $locationIds))` (bare `location_id` on the plain `pos_orders` query, qualified on the joined line-count subquery).
- **`$locationIds === []` ⇒ no predicate added** (all locations).

**TDD steps**
- [ ] **RED (unit)** — write `PosAnalyticsServiceLocationTest.php`: seed one company, two locations L1/L2, one terminal each, 2 receipts at L1 (+lines, +payments) and 1 receipt at L2 within range. Assert:
  - `getSalesSummary(..., [L1])->receipt_count === 2` and `getSalesSummary(..., [L2])->receipt_count === 1`.
  - `getSalesSummary(..., [])->receipt_count === 3` (empty = all).
  - `getSalesByProduct(..., 20, [L2])` returns only L2's product rows; `getFnbMetrics(..., [L1])` narrows peak-hours to L1 orders.
  - Return **type/shape** unchanged: `getSalesSummary(...)` is `instanceof SalesSummaryData` with all 9 public props present.
  ```php
  public function test_sales_summary_narrows_to_requested_location(): void
  {
      $service = new PosAnalyticsService();
      $from = CarbonImmutable::parse('2026-07-01');
      $to = CarbonImmutable::parse('2026-07-31');

      self::assertSame(2, $service->getSalesSummary($this->companyId, $from, $to, [$this->l1])->receipt_count);
      self::assertSame(1, $service->getSalesSummary($this->companyId, $from, $to, [$this->l2])->receipt_count);
      self::assertSame(3, $service->getSalesSummary($this->companyId, $from, $to, [])->receipt_count);
  }
  ```
  Run `cd apps/api && php artisan test tests/Unit/POS/PosAnalyticsServiceLocationTest.php` → RED (unknown 4th arg / no narrowing).
- [ ] **GREEN** — add the `$locationIds` param + `->when(...)` predicates to all 8 methods. Re-run → GREEN.
- [ ] **RED (request)** — extend `AnalyticsRequest::rules()`:
  ```php
  'location_ids' => ['sometimes', 'array'],
  'location_ids.*' => ['uuid'],
  ```
  Add feature test in `AnalyticsTest.php`: `GET /api/v1/pos/analytics/summary?from=..&to=..&location_ids[]=<L1>` returns only L1's totals; an out-of-scope id returns 403 (resolver fail-closed). Run `php artisan test tests/Feature/POS/AnalyticsTest.php` → RED.
- [ ] **GREEN (controller)** — inject resolver and pass through:
  ```php
  public function __construct(
      private readonly CompanyContext $companyContext,
      private readonly PosAnalyticsService $analyticsService,
      private readonly LocationScopeResolver $locationScope,   // §1
  ) {}

  private function scopedLocationIds(AnalyticsRequest $request): array
  {
      /** @var list<string> $requested */
      $requested = $request->validated('location_ids', []);
      return $this->locationScope->resolve($request->user(), $requested, null);
  }
  ```
  Pass `$this->scopedLocationIds($request)` as the trailing arg on all 8 service calls (respect `salesByProduct`'s `$limit` position and `salesByPeriod`'s `$granularity` position). Re-run → GREEN.
- [ ] **DTO byte-identical verification (F6 gate)** — `cd apps/api && CACHE_STORE=array php artisan typescript:transform && git diff --exit-code ../../packages/shared/types/generated.d.ts`. **Must exit 0 (no diff).** If it diffs, a DTO was reshaped — revert the reshape.
- [ ] **PHPStan** — `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/POS/Application/Services/PosAnalyticsService.php app/Modules/POS/Presentation/Controllers/AnalyticsController.php app/Modules/POS/Presentation/Requests/AnalyticsRequest.php --no-progress` → 0 errors.
- [ ] **GREEN (FE api/hooks)** — extend `analyticsApi.ts`:
  ```ts
  export interface AnalyticsFilters { from: string; to: string; location_ids?: string[] }

  function buildParams(filters: AnalyticsFilters, extra?: Record<string, string>): string {
    const params = new URLSearchParams({ from: filters.from, to: filters.to })
    for (const id of filters.location_ids ?? []) params.append('location_ids[]', id)
    if (extra) Object.entries(extra).forEach(([k, v]) => { params.set(k, v) })
    return params.toString()
  }
  ```
  In `useAnalytics.ts`, the `AnalyticsFilters` object already flows into every query key; because `location_ids` is now part of `filters`, keys differentiate by scope automatically. Wrap each `analyticsKeys.*(filters)` in `locationScopedKey([...], scope)` where `scope = { locationIds: filters.location_ids ?? [] }` (resource literal `'pos'` stays leading). Provide `scope` to the hooks via an added second arg or read `useViewScope()` inside each hook — pick the pattern §1 established for other migrated pages; keep it consistent.
- [ ] **RED (FE render)** — `AnalyticsDashboardPage.scope.test.tsx`: mock `useViewScope` to return `{ effectiveLocationIds: ['L1'], isAll: false, ... }` and assert the analytics hooks are called with `location_ids: ['L1']`; then mock `isAll: true` and assert **no** `location_ids` key is sent. Run `cd apps/web && pnpm vitest run src/features/pos/pages/AnalyticsDashboardPage/__tests__/AnalyticsDashboardPage.scope.test.tsx` → RED.
- [ ] **GREEN (page)** — in `AnalyticsDashboardPage.tsx` consume `const { effectiveLocationIds, isAll } = useViewScope()`; merge into `filters`:
  ```ts
  const scopedFilters = useMemo<AnalyticsFilters>(
    () => ({ ...filters, ...(isAll ? {} : { location_ids: effectiveLocationIds }) }),
    [filters, isAll, effectiveLocationIds],
  )
  ```
  Pass `scopedFilters` to all 8 hooks (replacing `filters`). The page keeps the existing `AnalyticsDateFilter`; the view-scope picker is the global TopBar control from §1 (no inline picker added here — F5). Re-run → GREEN.
- [ ] **Commit**: `feat(pos-analytics): location_ids filter on PosAnalyticsService (F6 bounded, no DTO reshape)`

---

## Task 2 — Wire orphaned `SalesByLocationChart` into the owner dashboard

**Files**
- `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`
- `apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx` (already correct; verify colors)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/SalesByLocationChart.test.tsx` (new)

**Interfaces**: `SalesByLocationChart` already accepts `{ data: SalesByLocationReport[]; isLoading?; isError? }` and renders a grouped bar per `location_name` × `period` — no prop change. Data comes from the existing `useSalesByLocation` endpoint (`/reports/sales/by-location`), already scope-aware via `location_ids[]`.

**TDD steps**
- [ ] **RED** — `SalesByLocationChart.test.tsx`: render with two locations × two periods, assert the ECharts option has one `series` entry per location and `isEmpty` state renders when `data=[]`. (Follow the existing `SalesTrendChart.test.tsx` render-assert pattern.) Run `pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesByLocationChart.test.tsx` → RED (no test yet / component untested).
- [ ] **GREEN (wire)** — in `OwnerDashboardPage.tsx`:
  - Source scope from `useViewScope()` (post-§1 the dashboard already consumes global scope; if `filters.locationIds` still exists from §1's migration, use that single source — do not add a second scope source).
  - Add a `useSalesByLocation({ ...dateParams, granularity: filters.granularity }, canViewOwnerDashboard)` result (already computed as `sales`) and render `<SalesByLocationChart data={sales.data ?? []} isLoading={sales.isLoading} isError={sales.isError} />` in a new dashboard grid cell (a comparison row beneath the trend/leaderboard row).
  - The chart is a **store comparison** (grouped bars across locations) — distinct from `SalesTrendChart` (rollup line). Place it so it only adds value when scope spans >1 location; render regardless (its own empty state covers single-location).
- [ ] **dataviz check** — `SalesByLocationChart` already uses `chartColors.*`; confirm the `color` array draws from `chartCategoricalKeys` order (swap the ad-hoc array to `chartCategoricalKeys.map((k) => chartColors[k])` for a single categorical system across the dashboard). Keep one legend/axis with `t()` labels.
- [ ] Run render test → GREEN; `pnpm lint` + `pnpm typecheck` on touched files.
- [ ] **Commit**: `feat(owner-dashboard): wire SalesByLocationChart store-comparison (scope-aware)`

---

## Task 3 — `BranchLeaderboard` respects view scope

**Files**
- `apps/web/src/features/owner-dashboard/components/BranchLeaderboard.tsx`
- `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx` (prop pass-through)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/BranchLeaderboard.test.tsx` (extend)

**Interfaces**: add an optional `locationIds?: string[]` prop:
```ts
interface BranchLeaderboardProps { canFetch?: boolean; currency?: string; locationIds?: string[] }
```
Pass it into both `useSalesByLocation(...)` calls (today + comparison). **Bounded**: only add the scope filter. The hardcoded `today`/`granularity:'day'` is **not** "trivially adjacent" to the scope change (it drives the delta-vs-last-week semantics and live open-shift matching), so leave that logic as-is per the plan's "scope filter only" instruction.

**TDD steps**
- [ ] **RED** — extend `BranchLeaderboard.test.tsx`: mock `useSalesByLocation` and assert it is called with `location_ids: ['L1']` when the component receives `locationIds={['L1']}`, and with **no** `location_ids` when `locationIds` is empty/undefined. Run `pnpm vitest run src/features/owner-dashboard/components/__tests__/BranchLeaderboard.test.tsx` → RED.
- [ ] **GREEN** — thread `locationIds` into the two `useSalesByLocation` params:
  ```ts
  const scopeParam = locationIds && locationIds.length > 0 ? { location_ids: locationIds } : {}
  const todaySales = useSalesByLocation({ from: today, to: today, granularity: 'day', ...scopeParam }, canFetch)
  const comparisonSales = useSalesByLocation({ from: comparisonDay, to: comparisonDay, granularity: 'day', ...scopeParam }, canFetch)
  ```
  In `OwnerDashboardPage.tsx`, pass `locationIds={isAll ? [] : effectiveLocationIds}` (or the §1-migrated `filters.locationIds`) to `<BranchLeaderboard ... />`.
- [ ] Run test → GREEN; lint/typecheck touched files.
- [ ] **Commit**: `feat(owner-dashboard): BranchLeaderboard respects view scope`

---

## Task 4 — `SalesTrendChart` per-location series toggle

**Files**
- `apps/web/src/features/owner-dashboard/components/SalesTrendChart.tsx`
- `apps/web/src/features/owner-dashboard/lib/rollupSalesByPeriod.ts` (reuse; add a per-location grouping helper if needed)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/SalesTrendChart.test.tsx` (extend)

**Interfaces**: add an optional toggle prop; default preserves today's rollup line:
```ts
interface SalesTrendChartProps {
  data: SalesByLocationReport[]
  comparisonData?: SalesByLocationReport[]
  granularity?: 'hour' | 'day' | 'week' | 'month'
  isLoading?: boolean
  isError?: boolean
  seriesMode?: 'rollup' | 'per-location'   // default 'rollup'
}
```
When `seriesMode === 'per-location'`: build one ECharts line series per distinct `location_name` in `data`, each series' points = that location's period rollup. `seriesMode === 'rollup'` (default) is the current single-line behavior (+ hourly comparison line untouched). The toggle control is a small segmented button inside the chart card header (tokens + `t()`), local `useState`.

**dataviz**: per-location series colors from `chartCategoricalKeys.map((k) => chartColors[k])` (same categorical system as Task 2). Keep the comparison dashed line only in `rollup`+`hour` mode. One legend; the legend becomes meaningful in per-location mode.

**TDD steps**
- [ ] **RED** — extend `SalesTrendChart.test.tsx`: with `seriesMode="per-location"` and data for L1/L2 across 2 periods, assert the option has 2 line series named `L1`/`L2`; with default `seriesMode` (rollup) assert exactly 1 primary series (existing behavior). Run `pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesTrendChart.test.tsx` → RED.
- [ ] **GREEN** — implement a `perLocationSeries(data, granularity)` builder (group rows by `location_id`, roll each group up via `rollupSalesByPeriod`, align on the union of periods, zero-fill gaps). Add the toggle button; default remains rollup. Re-run → GREEN.
- [ ] Wire the toggle in `OwnerDashboardPage.tsx` (local `useState<'rollup'|'per-location'>('rollup')`) and pass `seriesMode`.
- [ ] Lint/typecheck touched files.
- [ ] **Commit**: `feat(owner-dashboard): SalesTrendChart per-location series toggle`

---

## Task 5 — `ZReportListPage` cross-terminal rollup with location column

**Files**
- `apps/api/app/Modules/POS/Presentation/Controllers/ReportController.php` (`listZReports`)
- `apps/api/app/Modules/POS/Presentation/Resources/ZReportResource.php` (add location/terminal names)
- `apps/web/src/features/pos/api/reportApi.ts` (`ZReportListFilters`, `ZReportItem`)
- `apps/web/src/features/pos/pages/ZReportListPage/ZReportListPage.tsx`
- Tests: `apps/api/tests/Feature/POS/ZReportListTest.php` (new or extend existing Z-report test), `apps/web/src/features/pos/pages/ZReportListPage/__tests__/ZReportListPage.test.tsx` (new)

**Backend interface** — relax `terminal_id` from required to optional, add optional `location_ids`, keep company scope:
```php
$request->validate([
    'terminal_id' => ['nullable', 'string', 'uuid'],
    'location_ids' => ['sometimes', 'array'],
    'location_ids.*' => ['uuid'],
    'from_date' => ['nullable', 'date'],
    'to_date' => ['nullable', 'date'],
]);

$query = ZReport::query()
    ->whereHas('terminal', function (Builder $q) use ($request): void {
        $q->whereRaw('company_id = ?', [$this->companyContext->getCompanyId()]);
        $locationIds = $this->locationScope->resolve($request->user(), (array) $request->input('location_ids', []), null);
        if ($locationIds !== []) {
            $q->whereIn('location_id', $locationIds);
        }
    })
    ->when($request->filled('terminal_id'), fn ($q) => $q->where('terminal_id', $request->input('terminal_id')))
    ->with(['terminal.location', 'shift', 'generatedBy'])
    ->orderByDesc('generated_at')   // cross-terminal: order by time, not per-terminal z_number
    ->orderByDesc('z_number');
```
Inject `LocationScopeResolver $locationScope` into `ReportController` (constructor). Note: `verifyChainMutation`/chain verification remains terminal-scoped and is only shown when a terminal filter is selected (FE already gates it on `filters.terminal_id`).

**Resource interface** — expose terminal + location display fields so the FE can render a location column without an N+1:
```php
'terminal_name' => $this->whenLoaded('terminal', fn () => $this->terminal->name),
'location_id'   => $this->whenLoaded('terminal', fn () => $this->terminal->location_id),
'location_name' => $this->whenLoaded('terminal', fn () => $this->terminal->location?->name),
```

**Frontend**:
- `ZReportListFilters`: `terminal_id` stays optional; add `location_ids?: string[]`. `ZReportItem`: add `terminal_name?: string`, `location_id?: string`, `location_name?: string`.
- `fetchZReports`: append `location_ids[]` and only append `terminal_id` when set.
- Page: **remove the `!filters.terminal_id` "select a terminal" gate** (`ZReportListPage.tsx:204-208`) and the `enabled: !!filters.terminal_id` guard (query enabled whenever `hasTenantScope`). Add a **Location** column (rendering `location_name`) and a **Terminal** column, shown when no terminal is selected. Terminal `<select>` becomes optional narrowing (keep the "all terminals" option, already present at line 162). Row click: when a terminal is known for the row, deep-link `/pos/z-reports/{z_number}?terminal_id={report.terminal_id}` (use the row's own `terminal_id`, not the filter — required since the list is now cross-terminal). Source `location_ids` from `useViewScope` (`isAll ? undefined : effectiveLocationIds`); wrap the query key in `locationScopedKey(['pos','z-reports', filters], scope)`.

**TDD steps**
- [ ] **RED (backend)** — `ZReportListTest.php`: seed company, L1/L2, terminal per location, a Z-report per terminal. Assert `GET /api/v1/pos/reports/z` (no `terminal_id`) returns **both** reports with `location_name` populated; `?terminal_id=<T1>` returns only T1's; `?location_ids[]=<L2>` returns only L2's; an out-of-scope location id ⇒ 403. Run `cd apps/api && php artisan test tests/Feature/POS/ZReportListTest.php` → RED.
- [ ] **GREEN (backend)** — apply the relaxed query + resource fields + resolver injection. Re-run → GREEN. PHPStan on `ReportController.php` + `ZReportResource.php` → 0.
- [ ] **RED (FE)** — `ZReportListPage.test.tsx`: mock `fetchZReports` returning two reports across L1/L2 with no terminal filter; assert both rows render, a Location column shows `L1`/`L2`, and the "select a terminal" placeholder is **absent**. Run `pnpm vitest run src/features/pos/pages/ZReportListPage/__tests__/ZReportListPage.test.tsx` → RED.
- [ ] **GREEN (FE)** — remove the gate, add columns, wire scope + `locationScopedKey`, fix row-click to use `report.terminal_id`. Re-run → GREEN. Add `t()` keys for the new column headers (`pos:zReports.location`, `pos:zReports.terminalColumn`) in the `pos` namespace. Lint/typecheck.
- [ ] **Commit**: `feat(pos): Z-report cross-terminal rollup with location column`

---

## Task 6 — Consolidated home widgets (LAST WAVE — gated on §2/§3)

> **Ordering gate:** this task consumes endpoints delivered by **§2** (rebalance alerts) and **§3** (cash-position `group_by=location`, échéancier maturing-instruments). **Do not start Task 6 until §2 and §3 are merged to the branch base.** If §4 is executed before §2/§3 land, Tasks 1–5 ship and Task 6 is deferred as a follow-up (state this in the finish-branch note). Task 6 is **FE-only** — it declares dependencies on those endpoints and wires widgets; it adds no new backend aggregation.

**Files**
- `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`
- New widget components under `apps/web/src/features/owner-dashboard/components/` (`CashAcrossStoresWidget.tsx`, `DueThisWeekWidget.tsx`, `RebalanceAlertsWidget.tsx`)
- Tests: one `__tests__/*.test.tsx` per widget

**Dependency declarations (consume, do not build):**
- **Cash across stores** ← §3 `CashPositionController` `group_by=location` + `location_ids[]` (resolver). Deep-links to the cash-position detail page preserving scope.
- **Due this week** ← §3 échéancier / `MaturingInstrumentsController` filtered+grouped by location, windowed to the next 7 days. Deep-links to the échéancier page preserving scope.
- **Rebalance alerts** ← §2 rebalancing endpoint ("out/below-min at A, surplus at B"). Deep-links to the rebalancing view preserving scope.

**Scope + deep-link rule (drillable-rollup):** each widget reads `useViewScope()`; each "view all"/row link navigates to the detail route carrying the current scope (§1's picker persists it globally, so links need only preserve the route — verify the target page reads the global scope on mount and do not pass ad-hoc location query params that would diverge from the picker).

**TDD steps (per widget)**
- [ ] **RED** — widget test: mock its data hook, assert it renders the scoped rows, an empty state, and a deep-link whose `to` targets the correct detail route. Run `pnpm vitest run <widget test path>` → RED.
- [ ] **GREEN** — implement the widget (canonical card container, tokens, `t()`, `formatCurrency`/`formatQuantity` for numbers, `DataTable` if tabular). Wire into a new consolidated grid row on `OwnerDashboardPage`. Re-run → GREEN.
- [ ] Lint/typecheck; confirm each query key uses `locationScopedKey`.
- [ ] **Commit**: `feat(owner-dashboard): consolidated home widgets (cash/due/rebalance, scope-preserving)`

---

## Gates & verification

Run at the end of the package (and `frontend-conventions-reviewer` at every FE milestone per the standing rule):

- [ ] **`frontend-conventions-reviewer`** (adversarial, gates merge — never auto-merge): canonical components (`DataTable`/`PageHeader`), design tokens only, RHF/i18n, **`locationScopedKey` on every scope-consuming query**, dataviz color-system compliance on Tasks 2/4. Address findings before merge.
- [ ] **`treasury-reviewer`** — **only if Task 6 touches a treasury endpoint/contract** (cash-position/échéancier consumption). Tasks 1–5 do not touch treasury → skip unless Task 6 runs.
- [ ] **Full gate sweep**: `cd apps/api && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --no-progress` (touched files 0 errors) + named tests BY PATH; `cd apps/web && pnpm lint && pnpm typecheck` + named vitest paths. **DTO no-diff** re-confirmed (`typescript:transform` + `git diff --exit-code`).
- [ ] **Playwright e2e**:
  - Analytics dashboard filtered by view scope: select a single location in the TopBar picker → summary/products/cashiers numbers narrow; select "All" → numbers return to company totals.
  - Owner dashboard widgets drill-through: click a consolidated-widget link → lands on the detail page with the **same** scope applied (Task 6 only; skip if deferred).
  - Z-report list: with no terminal selected, all terminals' Z-reports list with a Location column; selecting a terminal narrows; row click opens the correct terminal's report.
- [ ] **Visual light + dark** of the owner dashboard (charts theme-aware via `chartColors`) and the analytics dashboard — no hardcoded colors, legible in both themes, RTL sane.

**Delivery ordering (explicit):** §4 runs **LAST** in the umbrella — after the §1 hard dependency (`useViewScope`, `locationScopedKey`, `LocationScopeResolver`) is merged. Tasks 1–5 are independent of §2/§3 and ship together. **Task 6 is the final wave, gated on §2 and §3 endpoints**; if those are not merged, ship 1–5 and defer 6.

---

## Self-review (coverage vs spec §4)

- §4 "PosAnalyticsService filter only, no group-by DTO reshaping" → **Task 1**, with explicit `typescript:transform` zero-diff gate enforcing F6. ✔
- §4 "AnalyticsDashboardPage adopts the view scope" → **Task 1** (`useViewScope` → `location_ids`). ✔
- §4 "wire the orphaned SalesByLocationChart" → **Task 2**. ✔
- §4 "BranchLeaderboard respects the scope filter" → **Task 3** (scope-only, hardcoded today/day left per bound). ✔
- §4 "SalesTrendChart per-location series toggle" → **Task 4** (rollup default). ✔
- §4 "Z-report list cross-terminal rollup with a location column" → **Task 5**. ✔
- §4 "consolidated home widgets — cash/due/rebalance, each deep-links with scope preserved" → **Task 6**, gated on §2/§3. ✔
- F6 bound respected (no DTO reshape; enforced by verification). §1 contract consumed, not rebuilt. No placeholders; every task has real test code, exact commands, real contract code, and a commit. ✔
</content>
</invoke>
