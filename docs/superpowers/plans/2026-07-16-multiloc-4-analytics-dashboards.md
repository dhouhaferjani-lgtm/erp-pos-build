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

**Frontend — `useViewScope()`** (Zustand-backed, company-keyed) — consume §1's shape VERBATIM (from `@/features/locations/hooks/useViewScope`, path per §1 Task 9):
```ts
// §1 PINNED — do NOT redefine or reshape.
function useViewScope(): {
  scope: 'all' | string[]                 // 'all' or an explicit subset of allowed ids
  effectiveLocationIds: string[]          // resolved allowed set: ALL scoped ids when isAll, else the subset
  isAll: boolean                          // scope === 'all'
  setScope(s: 'all' | string[]): void
}
```
**Wire rule (single, unconditional): ALWAYS send `location_ids: effectiveLocationIds`.** There is no "isAll ⇒ omit the param" branch — `effectiveLocationIds` already resolves to the full allowed set when `isAll`, so the FE sends it in every case and the backend always applies `resolve()`'s result. Do not gate the param on `isAll`.

**Frontend — `locationScopedKey(segments, scope)`** — §1 PINNED signature: `locationScopedKey(segments: readonly unknown[], scope: 'all' | readonly string[])`. Wraps `tenantScopedKey`; resource literal stays `segments[0]`, `scope` (the raw `'all' | string[]` from `useViewScope()`, passed **unchanged**) is injected as a non-leading segment so bare-literal-prefix mutation invalidation still works. Added to `audit-tanstack-keys.mjs` `APPROVED_FACTORY_CALLS` by §1.

**Backend — `LocationScopeResolver`** (`App\Modules\Company\Services\LocationScopeResolver` per §1 — this exact namespace, HTTP-only, requires bound `CompanyContext`):
```php
/** @return list<string>  Effective location ids the controller MUST filter by (never a "skip filter" sentinel). */
public function resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array;
```
- Fail-closed (`AuthorizationException`) on out-of-scope `requestedIds`.
- **Empty `requestedIds` returns the caller's FULL effective allowed set** (unrestricted ⇒ every company location id; restricted ⇒ their subset). It is NOT "no narrowing" — there is no unfiltered path.
- §4 endpoints declare **`bypassPermission: null`** (analytics/owner reads carry no processor carve-out; no new bypass permissions exist anywhere in the program).

**Contract used by §4 controllers/services (no "empty means unfiltered" anywhere):** every location-sensitive endpoint ALWAYS calls `resolve($request->user(), $requested, null)` — including no-param requests — and ALWAYS applies the returned ids as `WHERE location_id IN (...)`. The resolver output for a company with locations is always a concrete non-empty list; the service's `->when($locationIds !== [], ...)` guard is only a defensive no-op for the degenerate zero-location company, never a "show all" affordance. A restricted user hitting an endpoint with no `location_ids` therefore still sees only their allowed locations.

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
- **`$locationIds === []` ⇒ no predicate added** — a purely defensive no-op for a zero-location company. It is NOT reachable as a "show all" path: the controller always feeds the resolver's concrete effective set (empty request ⇒ full allowed set), so a real request always narrows. Do not document or test `[]` as an "all locations" affordance.

**TDD steps**
- [ ] **RED (unit — per-method + per-branch isolation, finding 6/7)** — write `PosAnalyticsServiceLocationTest.php`. Seed one company, two locations L1/L2, one terminal each; at L1: 2 completed receipts + 1 VOIDED receipt (+lines across 2 categories/2 products, +payments in 2 methods, +discounts by 2 reasons, +1 known customer); at L2: 1 completed receipt (+lines, +payment, +discount, +another customer); F&B: 2 closed `pos_orders` at L1 and 1 at L2 (+order lines, distinct hours/modes). Every method gets **at least one L1-only vs L2-only isolation assertion, and one assertion per internal query branch** so a forgotten predicate on any subquery fails. Actual test method list (one `test_*` per line):
  ```
  test_sales_summary_receipts_narrow_by_location          // receipts query: L1→2, L2→1
  test_sales_summary_voided_count_narrows_by_location     // voided-count subquery: L1→1, L2→0
  test_sales_summary_payments_breakdown_narrows_by_location // payments subquery: L1 methods only
  test_sales_by_category_narrows_by_location              // receipt_lines join: L2→only L2 categories
  test_sales_by_product_narrows_by_location               // getSalesByProduct(...,20,[L2]) → only L2 products
  test_sales_by_time_period_narrows_by_location           // period buckets restricted to L1
  test_cashier_performance_narrows_by_location            // per-cashier rows restricted to L1 terminal
  test_discount_analysis_narrows_by_location              // totals + by-reason + top-products subqueries all narrow
  test_customer_analytics_top_list_narrows_by_location    // top-customers list excludes L2's customer for [L1]
  test_fnb_metrics_all_subqueries_narrow_by_location      // closed-orders + line-count subquery + peak-hours + orders-by-mode
  test_return_shapes_unchanged                            // instanceof each DTO; all public props present
  ```
  Example (each branch asserted explicitly, no reliance on totals hiding a leak):
  ```php
  public function test_sales_summary_receipts_narrow_by_location(): void
  {
      $service = new PosAnalyticsService();
      $from = CarbonImmutable::parse('2026-07-01');
      $to = CarbonImmutable::parse('2026-07-31');

      self::assertSame(2, $service->getSalesSummary($this->companyId, $from, $to, [$this->l1])->receipt_count);
      self::assertSame(1, $service->getSalesSummary($this->companyId, $from, $to, [$this->l2])->receipt_count);
  }

  public function test_sales_summary_voided_count_narrows_by_location(): void
  {
      $service = new PosAnalyticsService();
      $from = CarbonImmutable::parse('2026-07-01');
      $to = CarbonImmutable::parse('2026-07-31');

      self::assertSame(1, $service->getSalesSummary($this->companyId, $from, $to, [$this->l1])->voided_count);
      self::assertSame(0, $service->getSalesSummary($this->companyId, $from, $to, [$this->l2])->voided_count);
  }
  ```
  Run `cd apps/api && php artisan test tests/Unit/POS/PosAnalyticsServiceLocationTest.php` → RED (unknown 4th arg / no narrowing).
- [ ] **GREEN** — add the `$locationIds` param + `->when($locationIds !== [], ...)` predicate to all 8 methods **and every internal subquery within them** (summary's voided-count and payments subqueries; discount's by-reason/top-product subqueries; F&B's line-count subquery). Re-run → GREEN. A green run requires every branch predicate present — the DTO no-diff gate below cannot substitute for these.
- [ ] **RED (request)** — extend `AnalyticsRequest::rules()`:
  ```php
  'location_ids' => ['sometimes', 'array'],
  'location_ids.*' => ['uuid'],
  ```
  Add feature tests in `AnalyticsTest.php`: (a) `GET /api/v1/pos/analytics/summary?from=..&to=..&location_ids[]=<L1>` returns only L1's totals; (b) an out-of-scope id returns 403 (resolver fail-closed); (c) **restricted-user / no-param**: a user whose allowed set is `{L1}` calling `?from=..&to=..` with **no** `location_ids` receives L1-only totals (proves the controller always resolves and applies — never company-wide on a bare request). Run `php artisan test tests/Feature/POS/AnalyticsTest.php` → RED.
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
- [ ] **DTO byte-identical verification (F6 gate — retained, NOT a substitute for the per-branch tests above)** — `cd apps/api && CACHE_STORE=array php artisan typescript:transform && git diff --exit-code ../../packages/shared/types/generated.d.ts`. **Must exit 0 (no diff).** If it diffs, a DTO was reshaped — revert the reshape. This gate proves shape stability only; a forgotten predicate on any subquery leaves the shape identical, so the per-method/per-branch isolation assertions (finding 6/7) are the behavioral guard and both must be green.
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
  In `useAnalytics.ts`, the `AnalyticsFilters` object already flows into every query key; because `location_ids` is now part of `filters`, keys differentiate by scope automatically. Wrap each `analyticsKeys.*(filters)` in `locationScopedKey([...], scope)` passing the **raw `scope` (`'all' | string[]`) straight from `useViewScope()` unchanged** (resource literal `'pos'` stays leading) — do NOT wrap it in an object. Provide `scope` to the hooks via an added second arg or read `useViewScope()` inside each hook — pick the pattern §1 established for other migrated pages; keep it consistent.
- [ ] **RED (FE render)** — `AnalyticsDashboardPage.scope.test.tsx`: mock `useViewScope` to return `{ scope: ['L1'], effectiveLocationIds: ['L1'], isAll: false, setScope }` and assert the analytics hooks are called with `location_ids: ['L1']`; then mock `{ scope: 'all', effectiveLocationIds: ['L1','L2'], isAll: true, setScope }` and assert `location_ids: ['L1','L2']` is **still sent** (unconditional wire rule — never omitted). Run `cd apps/web && pnpm vitest run src/features/pos/pages/AnalyticsDashboardPage/__tests__/AnalyticsDashboardPage.scope.test.tsx` → RED.
- [ ] **GREEN (page)** — in `AnalyticsDashboardPage.tsx` consume `const { effectiveLocationIds } = useViewScope()`; merge into `filters` **unconditionally** (no `isAll` branch — `effectiveLocationIds` is already the full allowed set when All):
  ```ts
  const scopedFilters = useMemo<AnalyticsFilters>(
    () => ({ ...filters, location_ids: effectiveLocationIds }),
    [filters, effectiveLocationIds],
  )
  ```
  Pass `scopedFilters` to all 8 hooks (replacing `filters`). The page keeps the existing `AnalyticsDateFilter`; the view-scope picker is the global TopBar control from §1 (no inline picker added here — F5). Re-run → GREEN.
- [ ] **Commit**: `feat(pos-analytics): location_ids filter on PosAnalyticsService (F6 bounded, no DTO reshape)`

---

## Task 2 — Wire orphaned `SalesByLocationChart` into the owner dashboard

**Files**
- `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`
- `apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx` (edit — fix money→chart conversion + colors; NOT declared correct)
- Test: `apps/web/src/features/owner-dashboard/components/__tests__/SalesByLocationChart.test.tsx` (new)

**Interfaces**: `SalesByLocationChart` accepts `{ data: SalesByLocationReport[]; isLoading?; isError? }` and renders a grouped bar per `location_name` × `period` — no prop change. Data comes from the existing `useSalesByLocation` endpoint (`/reports/sales/by-location`), already scope-aware via `location_ids[]`.

**Money→chart conversion (F5 — precision rule 19 at the viz boundary):** the component currently does `Number(...gross_sales...)` at `SalesByLocationChart.tsx:30`, which is a bare float coercion on a money string. The repo's established chart-money pattern (`rollupSalesByPeriod.ts:22-26`) does all arithmetic in `Big` and converts to `number` **only at the final ECharts series-data boundary** via `.toNumber()`. `SalesByLocationChart` does no arithmetic (it reads a single pre-summed `gross_sales` per cell), so the approved conversion is a single guarded helper applied **only** where the series-data array is built:
```ts
import Big from 'big.js'
// F5: money stays a decimal string through all logic; convert to float ONLY here,
// at the ECharts series-data boundary, after any server-side arithmetic is done.
function toChartNumber(value: string | null | undefined): number {
  try { return new Big(value ?? '0').toNumber() } catch { return 0 }
}
```
Replace the `Number(...)` at line 30 with `toChartNumber(data.find(...)?.gross_sales)`. No other floats introduced; no `parseFloat`.

**TDD steps**
- [ ] **RED** — `SalesByLocationChart.test.tsx`: (a) render with two locations × two periods, assert the ECharts option has one `series` entry per location and `isEmpty` state renders when `data=[]`; (b) **large-value/decimal precision**: feed `gross_sales: '1234567.899'` for a cell and assert the series data point equals `1234567.899` (not a rounded/scientific-notation float), proving the `toChartNumber` boundary preserves the decimal string faithfully. (Follow the existing `SalesTrendChart.test.tsx` render-assert pattern.) Run `pnpm vitest run src/features/owner-dashboard/components/__tests__/SalesByLocationChart.test.tsx` → RED (no test yet; line-30 `Number(...)` still present).
- [ ] **GREEN (conversion)** — add `toChartNumber` and swap the line-30 `Number(...)` for it. Re-run → GREEN.
- [ ] **GREEN (wire)** — in `OwnerDashboardPage.tsx`:
  - Source scope from `useViewScope()` — the single global scope source (`const { effectiveLocationIds } = useViewScope()`). Do not add a second scope source or reintroduce any `filters.locationIds` object shape.
  - Add a `useSalesByLocation({ ...dateParams, granularity: filters.granularity, location_ids: effectiveLocationIds }, canViewOwnerDashboard)` result (already computed as `sales`) — pass `effectiveLocationIds` unconditionally (never omit) — and render `<SalesByLocationChart data={sales.data ?? []} isLoading={sales.isLoading} isError={sales.isError} />` in a new dashboard grid cell (a comparison row beneath the trend/leaderboard row). Wrap the query key via `locationScopedKey([...], scope)` with the raw `scope`.
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

**Interfaces**: add a `locationIds: string[]` prop (always the caller's `effectiveLocationIds`):
```ts
interface BranchLeaderboardProps { canFetch?: boolean; currency?: string; locationIds?: string[] }
```
Two scope surfaces must both be closed (finding 8) — the two `useSalesByLocation(...)` calls **and** the `useLiveSales` activity map:
1. **Sales queries**: thread `locationIds` into both `useSalesByLocation(...)` params as `location_ids`.
2. **Live-sales activity**: `useLiveSales(canFetch)` returns `open_shifts_by_location` (a `Record<locationId, number>`) that feeds `isActive` on every row (`BranchLeaderboard.tsx:39`, `:101`). It is currently unfiltered, so an out-of-scope location's open shift can still light an activity dot / inject a row. Filter that map to the effective ids **before** passing it to `buildLeaderboardEntries` (the hook itself takes no params, so scope is applied to its output):
   ```ts
   const openShifts = liveSales.data?.open_shifts_by_location ?? {}
   const scopedOpenShifts = locationIds && locationIds.length > 0
     ? Object.fromEntries(Object.entries(openShifts).filter(([id]) => locationIds.includes(id)))
     : openShifts
   // pass scopedOpenShifts (not the raw map) into buildLeaderboardEntries(...)
   ```
**Bounded**: only add the scope filter to these two surfaces. The hardcoded `today`/`granularity:'day'` is **not** "trivially adjacent" (it drives the delta-vs-last-week semantics), so leave that logic as-is per the plan's "scope filter only" instruction.

**TDD steps**
- [ ] **RED** — extend `BranchLeaderboard.test.tsx`: (a) mock `useSalesByLocation` and assert it is called with `location_ids: ['L1']` when the component receives `locationIds={['L1']}`; (b) **restricted-scope activity isolation**: mock `useLiveSales` to return `open_shifts_by_location: { L1: 1, L2: 3 }` while sales data covers only `L1`, pass `locationIds={['L1']}`, and assert the excluded `L2` open shift produces **no** row and **no** active indicator — proving excluded locations cannot affect activity indicators. Run `pnpm vitest run src/features/owner-dashboard/components/__tests__/BranchLeaderboard.test.tsx` → RED.
- [ ] **GREEN** — thread `locationIds` into the two `useSalesByLocation` params and filter the live-sales map:
  ```ts
  const scopeParam = locationIds && locationIds.length > 0 ? { location_ids: locationIds } : {}
  const todaySales = useSalesByLocation({ from: today, to: today, granularity: 'day', ...scopeParam }, canFetch)
  const comparisonSales = useSalesByLocation({ from: comparisonDay, to: comparisonDay, granularity: 'day', ...scopeParam }, canFetch)
  // scopedOpenShifts (above) replaces the raw open_shifts_by_location in the buildLeaderboardEntries call + its deps array
  ```
  In `OwnerDashboardPage.tsx`, pass `locationIds={effectiveLocationIds}` (from `useViewScope()`, unconditional — never `isAll ? [] : ...`) to `<BranchLeaderboard ... />`.
- [ ] Run test → GREEN; lint/typecheck touched files.
- [ ] **Commit**: `feat(owner-dashboard): BranchLeaderboard respects view scope (sales + live-shift activity)`

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

// Resolve scope ONCE, ALWAYS apply (finding 2/4). Qualify every terminal column as pos_terminals.*
// (the whereHas subquery is on the pos_terminals table — POS/routes.php:49, ReportController.php:59)
// so an ambiguous bare `company_id`/`location_id` can never bind to the wrong table under Postgres.
$locationIds = $this->locationScope->resolve($request->user(), (array) $request->input('location_ids', []), null);

$query = ZReport::query()
    ->whereHas('terminal', function (Builder $q) use ($locationIds): void {
        $q->where('pos_terminals.company_id', $this->companyContext->getCompanyId());
        if ($locationIds !== []) {   // degenerate zero-location guard only; resolver returns the effective set
            $q->whereIn('pos_terminals.location_id', $locationIds);
        }
    })
    ->when($request->filled('terminal_id'), fn ($q) => $q->where('terminal_id', $request->input('terminal_id')))
    ->with(['terminal.location', 'shift', 'generatedBy'])
    ->orderByDesc('generated_at')   // cross-terminal: order by time, not per-terminal z_number
    ->orderByDesc('z_number');
```
Inject `LocationScopeResolver $locationScope` into `ReportController` (constructor). Note: `verifyChainMutation`/chain verification remains terminal-scoped and is only shown when a terminal filter is selected (FE already gates it on `filters.terminal_id`). **The resolver is always called (no `$request->filled('location_ids')` gate) — a no-param request from a restricted user still returns only their locations' Z-reports.**

**Resource interface** — expose terminal + location display fields so the FE can render a location column without an N+1:
```php
'terminal_name' => $this->whenLoaded('terminal', fn () => $this->terminal->name),
'location_id'   => $this->whenLoaded('terminal', fn () => $this->terminal->location_id),
'location_name' => $this->whenLoaded('terminal', fn () => $this->terminal->location?->name),
```

**Frontend**:
- `ZReportListFilters`: `terminal_id` stays optional; add `location_ids?: string[]`. `ZReportItem`: add `terminal_name?: string`, `location_id?: string`, `location_name?: string`.
- `fetchZReports`: append `location_ids[]` and only append `terminal_id` when set.
- Page: **remove the `!filters.terminal_id` "select a terminal" gate** (`ZReportListPage.tsx:204-208`) and the `enabled: !!filters.terminal_id` guard (query enabled whenever `hasTenantScope`). Add a **Location** column (rendering `location_name`) and a **Terminal** column, shown when no terminal is selected. Terminal `<select>` becomes optional narrowing (keep the "all terminals" option, already present at line 162). Row click: when a terminal is known for the row, deep-link `/pos/z-reports/{z_number}?terminal_id={report.terminal_id}` (use the row's own `terminal_id`, not the filter — required since the list is now cross-terminal). Source `location_ids` from `useViewScope` — pass `effectiveLocationIds` **unconditionally** (never `isAll ? undefined : ...`); wrap the query key in `locationScopedKey(['pos','z-reports', filters], scope)` passing the raw `scope` (`'all' | string[]`) unchanged.

**TDD steps**
- [ ] **RED (backend — executes real SQL on PostgreSQL via `RefreshDatabase`)** — `ZReportListTest.php`: seed company, L1/L2, terminal per location, a Z-report per terminal. Assert (a) `GET /api/v1/pos/reports/z` as an **unrestricted** user (no `terminal_id`, no `location_ids`) returns **both** reports with `location_name` populated — proving the qualified `pos_terminals.company_id`/`pos_terminals.location_id` predicates actually execute against Postgres without an ambiguous-column error; (b) `?terminal_id=<T1>` returns only T1's; (c) `?location_ids[]=<L2>` returns only L2's; (d) an out-of-scope location id ⇒ 403; (e) **restricted-user / no-param**: a user whose allowed set is `{L1}` calling with no `location_ids` returns only L1's Z-report (the resolver is applied to a bare request). Run `cd apps/api && php artisan test tests/Feature/POS/ZReportListTest.php` → RED.
- [ ] **GREEN (backend)** — apply the relaxed query (qualified columns) + resource fields + resolver injection. Re-run → GREEN. PHPStan on `ReportController.php` + `ZReportResource.php` → 0.
- [ ] **RED (FE)** — `ZReportListPage.test.tsx`: mock `fetchZReports` returning two reports across L1/L2 with no terminal filter; assert both rows render, a Location column shows `L1`/`L2`, and the "select a terminal" placeholder is **absent**. Run `pnpm vitest run src/features/pos/pages/ZReportListPage/__tests__/ZReportListPage.test.tsx` → RED.
- [ ] **GREEN (FE)** — remove the gate, add columns, wire scope + `locationScopedKey`, fix row-click to use `report.terminal_id`. Re-run → GREEN. Add `t()` keys for the new column headers (`pos:zReports.location`, `pos:zReports.terminalColumn`) in the `pos` namespace. Lint/typecheck.
- [ ] **Commit**: `feat(pos): Z-report cross-terminal rollup with location column`

---

## Task 6 — Consolidated home widgets (LAST WAVE — gated on §2/§3)

> **Ordering gate:** this task consumes endpoints delivered by **§2** (rebalance) and **§3** (cash-position, maturing-instruments/échéancier). **Do not start Task 6 until §2 and §3 are merged to the branch base.** If §4 is executed before §2/§3 land, Tasks 1–5 ship and Task 6 is deferred as a follow-up (state this in the finish-branch note). Task 6 is **FE-only** — it declares dependencies on those endpoints and wires widgets; it adds no new backend aggregation.

**Files**
- `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`
- New widget components: `apps/web/src/features/owner-dashboard/components/CashAcrossStoresWidget.tsx`, `DueThisWeekWidget.tsx`, `RebalanceAlertsWidget.tsx`
- Tests (EXACT paths — no placeholders):
  - `apps/web/src/features/owner-dashboard/components/__tests__/CashAcrossStoresWidget.test.tsx`
  - `apps/web/src/features/owner-dashboard/components/__tests__/DueThisWeekWidget.test.tsx`
  - `apps/web/src/features/owner-dashboard/components/__tests__/RebalanceAlertsWidget.test.tsx`

**Pinned widget contracts (consume verbatim — do not build the endpoints; each hook is delivered by §2/§3 and each key is wrapped in `locationScopedKey([...], scope)` with the raw `scope`; every widget passes `effectiveLocationIds` from `useViewScope()` unconditionally as `location_ids`):**

1. **CashAcrossStoresWidget** ← §3 (`CashPositionController.php:136`)
   - Hook: `useCashPosition({ group_by: 'location', location_ids: effectiveLocationIds })` (§3-delivered, `@/features/treasury/hooks`).
   - Endpoint: `GET /treasury/cash-position?group_by=location&location_ids[]=...`
   - Response type: `{ data: { as_of: string; currency: string; groups: CashPositionGroup[]; grand_total: string; flows?: { window_days: number; in: string; out: string } } }` — consume **`data.groups`** (one entry per location; amounts are decimal strings) and **`data.grand_total`** (decimal string) for the footer total.
   - Aggregation grain: one row per location group; total row = `grand_total`. Numbers via `formatCurrency` (strings — never `Number()`).
   - Route target (deep-link `to`): `/treasury/cash-position` (registered by §3 — confirm the exact registered path in §3's routing task before wiring; do not invent a divergent path).
   - Query key: `locationScopedKey(['treasury', 'cash-position', { group_by: 'location' }], scope)`.

2. **DueThisWeekWidget** ← §3 (`MaturingInstrumentsController.php:102`)
   - Hook: `useMaturingInstruments({ location_ids: effectiveLocationIds })` (§3-delivered).
   - Endpoint: `GET /treasury/maturing-instruments?location_ids[]=...`
   - Response type: `{ data: MaturingInstrumentRow[]; meta: { buckets: Record<'overdue'|'d0_7'|'d8_30'|'d31_60'|'d61_90'|'d90_plus', { count: number; total_in: string; total_out: string }>; grand_total: { count: number; total_in: string; total_out: string } } }`. Each `MaturingInstrumentRow` = `{ id, reference, amount: string, currency, maturity_date: string|null, received_date: string, status, direction, kind, repository_id, partner_id, needs_details, certainty, bucket }`.
   - Aggregation grain: "due this week" = rows where `bucket` ∈ `{'overdue','d0_7'}`; the widget summary line reads `meta.buckets.overdue` + `meta.buckets.d0_7` (`count`, `total_in`, `total_out` as decimal strings). Amounts via `formatCurrency` (strings).
   - Route target (deep-link `to`): `/treasury/maturing-instruments` (échéancier page registered by §3 — confirm exact path in §3's routing task; do not invent).
   - Query key: `locationScopedKey(['treasury', 'maturing-instruments', 'due-this-week'], scope)`.

3. **RebalanceAlertsWidget** ← §2 rebalance endpoint (finding 3 — the §2-pinned contract, VERBATIM)
   - Hook: `useRebalanceSuggestions({ location_ids: effectiveLocationIds })` (§2-delivered, `@/features/inventory/hooks`).
   - Endpoint: `GET /inventory/stock-matrix/rebalance?location_ids[]=...`
   - Response type: `{ data: RebalanceRow[] }` where `RebalanceRow = { product_id: string; variant_id: string | null; name: string; sku: string; deficits: { location_id: string; available: string; min_quantity: string }[]; surpluses: { location_id: string; available: string; max_quantity: string; excess: string }[] }`. **All quantities are decimal strings** — render via `formatQuantity`, never `parseFloat`/`Number()`.
   - Aggregation grain: one alert row per product/variant that has ≥1 `deficit` AND ≥1 `surplus` ("out/below-min at A, surplus at B"); the widget shows the top-N such rows.
   - Route target (deep-link `to`): `/inventory/stock-by-location` — the rebalancing view mounts as a section on that page (§2 Task 7).
   - Query key: `locationScopedKey(['inventory', 'stock-matrix', 'rebalance'], scope)`.

**Scope + deep-link rule (drillable-rollup):** each widget reads `useViewScope()`; each "view all"/row link navigates to the detail route above carrying the current scope (§1's picker persists it globally, so links need only preserve the route — verify the target page reads the global scope on mount and do NOT pass ad-hoc location query params that would diverge from the picker).

**TDD steps (per widget — substitute the widget's exact test path from the Files list above; no `<placeholder>`):**
- [ ] **RED** — widget test: mock its pinned hook to return the pinned response shape, assert it renders the scoped rows against that shape (e.g. `RebalanceAlertsWidget` renders `formatQuantity(row.deficits[0].available)`; `CashAcrossStoresWidget` renders `data.groups` + `grand_total`), an empty state, and a deep-link whose `to` equals the pinned route target. Run the exact path, e.g. `cd apps/web && pnpm vitest run src/features/owner-dashboard/components/__tests__/CashAcrossStoresWidget.test.tsx` → RED.
- [ ] **GREEN** — implement the widget (canonical card container, tokens, `t()`, `formatCurrency`/`formatQuantity` for numbers, `DataTable` if tabular). Wire into a new consolidated grid row on `OwnerDashboardPage`. Re-run the exact path → GREEN.
- [ ] Scoped lint/typecheck: `cd apps/web && pnpm lint src/features/owner-dashboard && pnpm typecheck`; confirm each query key uses `locationScopedKey` with the raw `scope`.
- [ ] **Commit**: `feat(owner-dashboard): consolidated home widgets (cash/due/rebalance, scope-preserving)`

---

## Gates & verification

Run at the end of the package (and `frontend-conventions-reviewer` at every FE milestone per the standing rule):

- [ ] **`frontend-conventions-reviewer`** (adversarial, gates merge — never auto-merge): canonical components (`DataTable`/`PageHeader`), design tokens only, RHF/i18n, **`locationScopedKey` on every scope-consuming query**, dataviz color-system compliance on Tasks 2/4. Address findings before merge.
- [ ] **`treasury-reviewer`** — **only if Task 6 touches a treasury endpoint/contract** (cash-position/échéancier consumption). Tasks 1–5 do not touch treasury → skip unless Task 6 runs.
- [ ] **Scoped gate sweep (BY PATH — never full preflight/suite)**:
  - Backend: `cd apps/api && ./vendor/bin/pint app/Modules/POS/Application/Services/PosAnalyticsService.php app/Modules/POS/Presentation/Controllers/AnalyticsController.php app/Modules/POS/Presentation/Requests/AnalyticsRequest.php app/Modules/POS/Presentation/Controllers/ReportController.php app/Modules/POS/Presentation/Resources/ZReportResource.php` then `./vendor/bin/phpstan analyse app/Modules/POS/Application/Services/PosAnalyticsService.php app/Modules/POS/Presentation/Controllers/AnalyticsController.php app/Modules/POS/Presentation/Requests/AnalyticsRequest.php app/Modules/POS/Presentation/Controllers/ReportController.php app/Modules/POS/Presentation/Resources/ZReportResource.php --no-progress` → 0 errors.
  - Backend tests BY PATH: `php artisan test tests/Unit/POS/PosAnalyticsServiceLocationTest.php tests/Feature/POS/AnalyticsTest.php tests/Feature/POS/ZReportListTest.php`.
  - Frontend: `cd apps/web && pnpm lint src/features/pos src/features/owner-dashboard && pnpm typecheck` then the named vitest paths for every test file created/edited in Tasks 1–6.
  - **DTO no-diff** re-confirmed: `cd apps/api && CACHE_STORE=array php artisan typescript:transform && git diff --exit-code ../../packages/shared/types/generated.d.ts`.
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
