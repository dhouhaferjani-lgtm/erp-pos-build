# T5 — Owner Reporting MVP

**Track:** T5 (P0 sprint — Wave 1, no POS touch)
**Date:** 2026-05-24 (v2 after Codex round-1 review)
**Recommended workflow:** Codex throughout. New chart surface mirroring proven POS analytics ECharts pattern. Small chunks, fast iteration. Adversarial review by Opus headless (light).
**Estimated effort:** ~5 PD
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)

---

## 1. Purpose

**Reframed in v2 per Codex P1-1:** Codex correctly noted that `Dashboard.tsx` and `ReportsPage.tsx` (cited in v1 as "production-wired with ECharts") **do not actually import ECharts**. ECharts (v6 + `echarts-for-react`) is installed in package.json (`apps/web/package.json:49-50`) AND used in POS analytics components (`apps/web/src/features/pos/organisms/Analytics/`: CashierComparisonChart, SalesByCategoryChart, SalesTimeSeriesChart, FnbMetricsPanel, DiscountBreakdownChart). But the owner-facing Dashboard/Reports surface has not yet adopted ECharts.

So T5 is **first owner-dashboard chart surface**, **mirroring the proven POS analytics ECharts pattern** (NOT extending dashboard chart components that don't exist).

What we build:
- 6 owner KPI dashboards as new chart surface in owner-facing dashboard
- Multi-location + multi-company dimensions (filter, drill-down)
- Database views for aggregation when needed for performance

Backend reporting infra IS production:
- `apps/erp/apps/api/app/Modules/Accounting/Application/Services/Reports/` has 6 production report services (AgedReceivables, AgedPayables, BalanceSheet, ProfitLoss, TrialBalance, GeneralLedger)
- `reportsApi.ts` has 3 named endpoints (`fetchAgedReceivables`, `fetchCustomerStatement`, `fetchOverdueSummary`)
- TanStack Query + `tenantScopedKey` is the proven pattern for owner dashboards

---

## 2. Architecture grounding (verified file paths)

Read before writing code:

1. `apps/erp/apps/web/src/features/dashboard/Dashboard.tsx` (lines 1–397) — KPI cards wired via TanStack Query + `tenantScopedKey`. **Verified: no ECharts import.** This is the page to extend with chart widgets.
2. `apps/erp/apps/web/src/features/reports/ReportsPage.tsx` (lines 1–298) — metrics aggregation. **Verified: no ECharts import.** Possible extension target.
3. `apps/erp/apps/web/src/features/reports/api/reportsApi.ts` — existing `fetchAgedReceivables`, `fetchCustomerStatement`, `fetchOverdueSummary` — extend pattern with new endpoints
4. `apps/erp/apps/web/package.json:49-50` — `echarts` v6.0.0 + `echarts-for-react` v3.0.6 — **installed, use these; do NOT add another chart library**
5. **ECharts patterns to MIRROR (proven, production):**
   - `apps/erp/apps/web/src/features/pos/organisms/Analytics/SalesTimeSeriesChart.tsx` — time-series pattern
   - `apps/erp/apps/web/src/features/pos/organisms/Analytics/SalesByCategoryChart.tsx` — category breakdown
   - `apps/erp/apps/web/src/features/pos/organisms/Analytics/CashierComparisonChart.tsx` — comparison chart
   - `apps/erp/apps/web/src/features/pos/organisms/Analytics/DiscountBreakdownChart.tsx` — pie chart
   - `apps/erp/apps/web/src/features/pos/organisms/Analytics/FnbMetricsPanel.tsx` — KPI panel
6. `apps/erp/apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php` (lines 61–71) — existing report endpoints
7. `apps/erp/apps/api/app/Modules/Accounting/Application/Services/Reports/` — 6 production report services
8. `apps/erp/apps/api/app/Modules/Accounting/Application/DTOs/Reports/` — existing report DTOs to mirror
9. `apps/erp/apps/api/app/Modules/Company/Domain/Location.php` — per-location filtering
10. `apps/erp/apps/api/app/Modules/Company/Domain/Company.php` — per-company aggregation (`parent_company_id` for chains)
11. `apps/erp/apps/api/app/Modules/Document/` — Document module (sales data)
12. `apps/erp/apps/api/app/Modules/POS/` — POS module (register/cash recon data)
13. `apps/erp/apps/api/app/Modules/Treasury/` — Treasury module (payment-method data)
14. `apps/erp/apps/web/src/lib/designTokens.ts` — design tokens (per CLAUDE.md rule 18: migrate hardcoded Tailwind colors when touching files)

**Patterns to mirror:**

- **TanStack Query + tenantScopedKey** — every new hook follows; never raw `useQuery` without scope wrapper
- **DB views for aggregation** when complex/cross-component — define in migration, query from repository (in `database/migrations/tenant/` per topology contract)
- **Eloquent + per-tenant scoping** in services (DB boundary handles post-T6)
- **DTO-first** — every report has DTO
- **ECharts wrapper component** — create a thin `OwnerChart` wrapper around `ReactECharts` for consistent theming (per design tokens, NOT hardcoded colors)

**Productization audit note:** current Dashboard.tsx + ReportsPage.tsx use hardcoded Tailwind colors. Per CLAUDE.md rule 18, new T5 code uses `designTokens.ts`. The existing pages stay as-is unless we touch them.

---

## 3. Domain model

### No new entities

All work on existing entities (Document, DocumentLine, PosReceipt, PosReceiptLine, Payment, StockLevel, Location, Company, Partner, Product, ProductVariant).

### Possibly new DB views (performance — only if aggregation query > 500ms)

If needed, materialize via PG views (NOT materialized views — no refresh management). Names per 3-tier rule (`claude/database-topology.md`). All in `database/migrations/tenant/` per topology contract:

- `tenant_v_sales_by_location_daily` — daily sales rolled up per location
- `tenant_v_top_skus_30d` — rolling 30-day top SKUs by revenue + quantity per tenant
- `tenant_v_stock_alerts_per_location` — current low/out-of-stock per location
- `tenant_v_revenue_by_category` — revenue rolled up by product category
- `tenant_v_payment_method_breakdown` — payment-method shares per period
- `tenant_v_cash_register_summary` — cash register sessions with variances

---

## 4. Public contracts

### Application services

```php
SalesReportService
  ::salesByLocation(DateRange $range, ?array $locationIds, ?array $companyIds): Collection<SalesByLocationDTO>
  ::topSkus(DateRange $range, int $limit, ?UUID $locationId): Collection<TopSkuDTO>
  ::revenueByCategory(DateRange $range, ?UUID $locationId): Collection<CategoryRevenueDTO>
  ::paymentMethodBreakdown(DateRange $range, ?UUID $locationId): Collection<PaymentMethodDTO>

StockAlertReportService
  ::lowStockAcrossLocations(?UUID $companyId, ?array $locationIds, int $thresholdPct = 100): Collection<StockAlertDTO>

CashRegisterReportService
  ::reconciliationSummary(DateRange $range, ?array $locationIds): Collection<CashReconDTO>
```

Note: tenant auto-resolved from Stancl context; no explicit tenant_id parameter.

### REST endpoints

- `GET /api/v1/reports/sales/by-location?from=...&to=...&location_ids[]=...&company_ids[]=...`
- `GET /api/v1/reports/sales/top-skus?from=...&to=...&limit=20&location_id=...`
- `GET /api/v1/reports/sales/revenue-by-category?from=...&to=...&location_id=...`
- `GET /api/v1/reports/sales/payment-method-breakdown?from=...&to=...&location_id=...`
- `GET /api/v1/reports/stock/alerts?company_id=...&location_ids[]=...&threshold_pct=100`
- `GET /api/v1/reports/cash-register/reconciliation?from=...&to=...&location_ids[]=...`

---

## 5. User-visible surface

### Tenant React frontend (`apps/web/src/features/owner-dashboard/`)

New top-level "Owner Dashboard" page accessible to roles with `dashboard.owner` permission. Composed of 6 widgets:

1. **SalesByLocationChart** — bar chart (mirror `SalesTimeSeriesChart.tsx` pattern), daily/weekly/monthly toggle, multi-location filter, multi-company filter
2. **TopSkusWidget** — table of top 20 SKUs by revenue + quantity, date range filter, location filter, variant-aware (after T2)
3. **LowStockAlertsList** — products below threshold per location with severity colors (red/orange/yellow)
4. **RevenueByCategoryDonut** — donut chart (mirror `SalesByCategoryChart.tsx` pattern), drill-down on click
5. **PaymentMethodBreakdownPie** — pie chart, absolute + percentage view toggle
6. **CashRegisterReconciliationTable** — daily reconciliation per location, variance flagged

### Filters (top of dashboard)

- Date range picker (presets: Today, Last 7 days, Last 30 days, This month, Last month, Custom)
- Multi-select location filter
- Multi-select company filter (auto-hidden when tenant has only 1 company)

### Super-admin dashboard

**Out of scope this spec.** The React-vs-Filament decision for super-admin is parked.

---

## 6. Generic-ness checklist

- [ ] Zero client names in code/config
- [ ] Date range picker uses standard presets, no client-specific date logic
- [ ] Location and company filters auto-adapt (3 locations → 3-checkbox list; 30 → searchable multi-select)
- [ ] Permissions: widgets respect user's location-level permissions (shop manager sees only their location)
- [ ] All widgets work for tenants with 1 location OR 30 locations
- [ ] All widgets work for tenants with 1 company OR multiple companies
- [ ] Reusable across all verticals
- [ ] **New code uses `designTokens.ts`, not hardcoded Tailwind colors** (per CLAUDE.md rule 18)
- [ ] All migrations (if views needed) in `database/migrations/tenant/` per topology contract

---

## 7. Acceptance criteria

### Per-location sales

- [ ] Owner of 4-location tenant sees daily sales bar chart for last 30 days with per-location series
- [ ] Filter by 1 location shows only that location
- [ ] Filter by 2 companies (chain owner) aggregates per company

### Top SKUs

- [ ] Top 20 SKUs by revenue OR quantity (toggle), date range, optional per-location
- [ ] Variant-aware after T2 merges

### Low-stock alerts

- [ ] Products at < 100% of min_quantity per location
- [ ] Severity colors: red (out of stock), orange (≤ 50% min), yellow (≤ 100% min)
- [ ] Variant-aware after T2

### Revenue by category

- [ ] Donut chart percentage split + absolute revenue per category
- [ ] Click drills into category SKU list

### Payment-method breakdown

- [ ] All payment methods used in period (cash, card, vouchers, etc.)
- [ ] Absolute + percentage view toggle

### Cash register reconciliation

- [ ] Per day per location: expected cash (from receipts) vs counted (from cash counting) vs variance
- [ ] Variance > tolerance highlighted

### Multi-company support

- [ ] Tenant with 2 companies (parent + child) sees consolidated dashboards
- [ ] Per-company drill-down works
- [ ] User with permissions on Company A only sees Company A data

### Tests

- [ ] Feature tests for each report endpoint (happy path + filters)
- [ ] Permission tests (limited location/company access)
- [ ] Tenant isolation test (DB boundary)
- [ ] Performance: each report endpoint < 800ms for tenant with 10k receipts/month and 30 locations

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify findings against actual code at cited paths. Read the files. Do not make assumptions. Pay special attention to: (1) tenant + company + location scoping in EVERY query — missing scope is a data leak; (2) reuse of POS Analytics ECharts patterns — verify new chart wrapper mirrors them (don't re-invent); (3) permission filtering — shop manager should never see another shop's data even with crafted URL; (4) design tokens — new charts use designTokens.ts, not hardcoded Tailwind colors."*

- [ ] **Tenant isolation:** every report endpoint scoped via Stancl tenant context (DB boundary post-T6)
- [ ] **Company filter respects permissions:** user with `view.company:A` cannot pass `company_ids[]=B` and see B's data
- [ ] **Location filter respects permissions**
- [ ] **ECharts pattern reuse:** new chart components mirror POS Analytics pattern (specifically `SalesTimeSeriesChart.tsx`, `SalesByCategoryChart.tsx`, etc.); no new dependencies
- [ ] **DB view tenant scoping:** if views created, they include implicit tenant scoping via Stancl context
- [ ] **Performance:** verify < 800ms assertion holds with seeded volume; add `Schema::index` if needed
- [ ] **Backward compat:** existing Dashboard.tsx + ReportsPage.tsx still work unchanged
- [ ] **i18n:** widget labels translated FR + AR via existing `t()` keys
- [ ] **No hardcoded colors in NEW code:** new chart wrappers consume `designTokens.ts`
- [ ] **Date range picker:** correctly handles tenant timezone
- [ ] **Migrations (if any):** all in `database/migrations/tenant/` per topology contract

---

## 9. Out of scope

- Super-admin platform dashboard (parked decision)
- Advanced cohort analytics (RFM, repeat-customer rate)
- Custom report builder
- Scheduled report email delivery
- Export to PDF/Excel for new widgets (existing accounting reports already do this)
- Mobile-app dashboards
- Real-time push (websocket) updates

---

## 10. Reading order

1. This spec
2. Migration topology contract
3. `apps/erp/CLAUDE.md` (especially rule 18 — design tokens)
4. The 14 file paths in Section 2
5. `apps/erp/apps/web/src/lib/designTokens.ts` (critical)
6. POS Analytics chart components for ECharts pattern reference

---

## 11. Workflow recommendation

**Phase 1 (Codex, ~2 PD):** Backend services + DTOs + endpoints. Mechanical extension of existing report pattern.

**Phase 2 (Codex, ~2 PD):** Frontend widgets — create `OwnerChart` wrapper (consumes designTokens) + 6 widgets mirroring POS Analytics patterns. Page composition + filter bar + permission wiring.

**Phase 3 (Codex, ~1 PD):** Tests + i18n FR/AR + performance verification.

All phases adversarial-reviewed by Opus headless (each phase < 800 LOC).

---

## 12. Coordination notes

- **Depends on:** T6 Phase 0 (migration placement if views needed)
- **Reads from:** all other modules — consumes Document, PosReceipt, Payment, StockLevel, Location, Company
- **Soft depends on:** T2 (variant-aware top-SKUs after merge)
- **No Tauri POS changes**
- **No new database tables** unless DB views needed for performance (and then in `database/migrations/tenant/`)
