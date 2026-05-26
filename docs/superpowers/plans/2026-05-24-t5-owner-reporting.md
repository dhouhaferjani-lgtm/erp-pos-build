# T5 Owner Reporting MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build six owner-facing KPI report endpoints and React dashboard widgets backed by existing POS, inventory, treasury, company, and product data.

**Architecture:** Add DTO-first owner reporting services under Accounting reports because `/api/v1/reports/*` already lives there. Use direct tenant-database queries scoped by allowed company/location IDs, then expose thin controller endpoints and TanStack Query hooks consumed by a new `owner-dashboard` feature. Frontend charts use the existing `echarts-for-react` dependency through a small `OwnerChart` wrapper and tokenized UI classes.

**Tech Stack:** Laravel 12, Spatie Data, Sanctum, Spatie permissions, PostgreSQL/SQLite-compatible query builder, React 19, TanStack Query 5, ECharts 6, Vitest, Testing Library, react-i18next.

---

## File Structure

- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/DateRangeData.php`: validated immutable date range helper.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesByLocationData.php`: sales rollup per period/location/company.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/TopSkuData.php`: top SKU row.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/CategoryRevenueData.php`: category revenue row.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/PaymentMethodBreakdownData.php`: payment method row.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/StockAlertData.php`: low-stock alert row.
- Create `apps/api/app/Modules/Accounting/Application/DTOs/Reports/CashReconciliationData.php`: cash reconciliation row.
- Create `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php`: resolves allowed companies/locations from request filters and company context.
- Create `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php`: sales, top SKU, category, and payment method aggregations.
- Create `apps/api/app/Modules/Accounting/Application/Services/Reports/StockAlertReportService.php`: low-stock aggregation.
- Create `apps/api/app/Modules/Accounting/Application/Services/Reports/CashRegisterReportService.php`: shift cash reconciliation aggregation.
- Create `apps/api/app/Modules/Accounting/Presentation/Requests/GetOwnerSalesReportRequest.php`: validates date range, company/location filters, limit, granularity.
- Create `apps/api/app/Modules/Accounting/Presentation/Requests/GetOwnerStockAlertsRequest.php`: validates stock alert filters.
- Create `apps/api/app/Modules/Accounting/Presentation/Requests/GetOwnerCashReconciliationRequest.php`: validates cash reconciliation filters.
- Modify `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php`: inject services and expose six owner-report actions.
- Modify `apps/api/app/Modules/Accounting/Presentation/routes.php`: add six `reports.*` routes protected by `can:dashboard.owner`.
- Create `apps/api/tests/Feature/Accounting/OwnerReportingTest.php`: endpoint, filtering, and isolation coverage.
- Modify/generated `packages/shared/types/generated.ts`: generated Spatie Data TypeScript types after backend DTO additions.
- Create `apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts`: typed API functions.
- Create `apps/web/src/features/owner-dashboard/hooks/useOwnerReports.ts`: tenant-scoped query hooks.
- Create `apps/web/src/features/owner-dashboard/components/OwnerChart.tsx`: shared chart frame and ECharts wrapper.
- Create `apps/web/src/features/owner-dashboard/components/OwnerDashboardFilters.tsx`: date, company, and location filters.
- Create six widget components under `apps/web/src/features/owner-dashboard/components/`: `SalesByLocationChart.tsx`, `TopSkusWidget.tsx`, `LowStockAlertsList.tsx`, `RevenueByCategoryDonut.tsx`, `PaymentMethodBreakdownPie.tsx`, `CashRegisterReconciliationTable.tsx`.
- Create `apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx`: composed dashboard.
- Modify `apps/web/src/features/dashboard/Dashboard.tsx`: embed the owner dashboard section with tokenized wrapper classes only where touched.
- Create `apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.test.tsx`: query-key and API contract tests.
- Create `apps/web/src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx`: widget rendering and empty state tests.
- Modify `apps/web/src/locales/en/reports.json`, `apps/web/src/locales/fr/reports.json`, and add `apps/web/src/locales/ar/reports.json` if missing: owner dashboard translation keys.
- Create `docs/superpowers/reviews/2026-05-24-t5-implementation-opus-review.md`: adversarial review output and resolution notes.

## Task 1: Backend RED Tests

**Files:**
- Create: `apps/api/tests/Feature/Accounting/OwnerReportingTest.php`

- [ ] **Step 1: Write failing endpoint tests**

```php
public function test_sales_by_location_groups_receipts_by_location_and_day(): void
{
    Sanctum::actingAs($this->owner);
    $this->seedReceipt($this->locationA->id, '2026-05-01 10:00:00', '120.000');
    $this->seedReceipt($this->locationB->id, '2026-05-01 11:00:00', '80.000');

    $response = $this->getJson('/api/v1/reports/sales/by-location?from=2026-05-01&to=2026-05-31');

    $response->assertOk()->assertJsonPath('data.0.location_id', $this->locationA->id);
}
```

- [ ] **Step 2: Add remaining failing tests**

Cover:

```php
public function test_filters_by_requested_location_ids(): void {}
public function test_rejects_location_outside_allowed_company_scope(): void {}
public function test_top_skus_returns_limit_ordered_by_revenue(): void {}
public function test_revenue_by_category_groups_uncategorized_products(): void {}
public function test_payment_method_breakdown_returns_amount_and_percentage(): void {}
public function test_stock_alerts_returns_below_minimum_with_severity(): void {}
public function test_cash_register_reconciliation_returns_shift_variance(): void {}
```

- [ ] **Step 3: Verify RED**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Accounting/OwnerReportingTest.php
```

Expected: fail because the new routes do not exist.

## Task 2: Backend DTOs, Scope, Services, Routes

**Files:**
- Create/modify the backend files listed in File Structure.

- [ ] **Step 1: Implement minimal DTOs**

Create Spatie Data classes with `#[TypeScript]`, readonly constructor properties, numeric values as strings, and no `mixed`.

- [ ] **Step 2: Implement `OwnerReportScope`**

Resolve company IDs from `CompanyContext::requireCompanyId()`, optional `company_ids`, and child company rows. Resolve allowed location IDs from `locations.company_id IN (...)`; reject filters outside the resolved scope with `AuthorizationException`.

- [ ] **Step 3: Implement `SalesReportService`**

Use query builder on `pos_receipts`, `pos_receipt_lines`, `products`, `categories`, and `pos_receipt_payments`; always exclude `is_voided = true`, apply date ranges, apply scoped company/location IDs, and use SQLite-compatible date grouping when the test connection is SQLite.

- [ ] **Step 4: Implement stock and cash services**

Use `stock_levels` joined to `products` and `locations` for alerts. Use `pos_shifts` plus receipt/payment aggregates for cash reconciliation; report expected, counted, and variance from existing shift columns.

- [ ] **Step 5: Wire controller and routes**

Add constructor-injected services to `ReportsController`, add six actions returning `{ data: ... }`, and add routes in `Accounting/Presentation/routes.php` with `can:dashboard.owner`.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
cd apps/api
php artisan test tests/Feature/Accounting/OwnerReportingTest.php
```

Expected: all owner reporting feature tests pass.

## Task 3: Frontend RED Tests

**Files:**
- Create: `apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.test.tsx`
- Create: `apps/web/src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx`

- [ ] **Step 1: Write failing query/API tests**

```tsx
it('uses tenantScopedKey for owner report queries', () => {
  const key = ownerReportKeys.salesByLocation({ from: '2026-05-01', to: '2026-05-31' })
  expect(key[0]).toBe('owner-reports')
})
```

- [ ] **Step 2: Write failing widget render tests**

```tsx
it('renders all six owner dashboard widgets', () => {
  render(<OwnerDashboardPage />)
  expect(screen.getByText('ownerDashboard.salesByLocation')).toBeInTheDocument()
})
```

- [ ] **Step 3: Verify RED**

Run:

```bash
pnpm --filter @autoerp/web test -- owner-dashboard
```

Expected: fail because the feature files do not exist.

## Task 4: Frontend API, Hooks, Widgets, i18n

**Files:**
- Create/modify the frontend files listed in File Structure.

- [ ] **Step 1: Implement API and hooks**

Use `apiGet<T>()`, `URLSearchParams`, generated DTO-compatible TypeScript shapes, and `useQuery({ queryKey: tenantScopedKey(...), enabled })`.

- [ ] **Step 2: Implement `OwnerChart`**

Wrap `ReactECharts`, apply token classes from `designTokens.ts`, provide title/loading/empty/error states, and pass ECharts options through without new chart dependencies.

- [ ] **Step 3: Implement widgets**

Mirror POS ECharts patterns: bar/line for sales by location, donut for category revenue, pie for payment methods, tables/lists for top SKUs, stock alerts, and cash reconciliation. Use `t('reports:ownerDashboard...')` for every user-facing label.

- [ ] **Step 4: Compose page and dashboard entry**

Add `OwnerDashboardPage` and mount it in `Dashboard.tsx` below the existing KPI cards while preserving existing Dashboard behavior.

- [ ] **Step 5: Add FR/AR translations**

Add every owner dashboard key to English and French reports namespaces, and create Arabic reports namespace if needed.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
pnpm --filter @autoerp/web test -- owner-dashboard
```

Expected: owner dashboard tests pass.

## Task 5: Type Generation and Focused Regression

**Files:**
- Modify generated shared types if `php artisan typescript:transform` changes them.

- [ ] **Step 1: Generate types**

Run:

```bash
cd apps/api
php artisan typescript:transform
```

- [ ] **Step 2: Run focused backend checks**

```bash
cd apps/api
php artisan test tests/Feature/Accounting/OwnerReportingTest.php
./vendor/bin/phpstan analyse app/Modules/Accounting/Application/Services/Reports app/Modules/Accounting/Application/DTOs/Reports app/Modules/Accounting/Presentation/Controllers/ReportsController.php app/Modules/Accounting/Presentation/Requests --level=8
```

- [ ] **Step 3: Run focused frontend checks**

```bash
pnpm --filter @autoerp/web test -- owner-dashboard
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/web lint
```

## Task 6: Full Preflight, Review, PR

**Files:**
- Create: `docs/superpowers/reviews/2026-05-24-t5-implementation-opus-review.md`

- [ ] **Step 1: Run full preflight**

```bash
./scripts/preflight.sh
```

- [ ] **Step 2: Dispatch adversarial review**

Ask the reviewer to verify tenant/company/location scoping, permission filtering, POS ECharts pattern reuse, design token usage, i18n, date range handling, and migration placement.

- [ ] **Step 3: Save review and address findings**

Write the review, fixes, and resolution notes to:

```text
docs/superpowers/reviews/2026-05-24-t5-implementation-opus-review.md
```

- [ ] **Step 4: Re-run verification**

```bash
./scripts/preflight.sh
```

- [ ] **Step 5: Commit and open PR**

```bash
git add apps/api apps/web packages/shared docs/superpowers
git commit -m "Phase 0.1.8: Add owner reporting dashboard MVP"
gh pr create --base dev --title "feat(reporting): T5 owner reporting MVP" --body-file /tmp/t5-owner-reporting-pr.md
```
