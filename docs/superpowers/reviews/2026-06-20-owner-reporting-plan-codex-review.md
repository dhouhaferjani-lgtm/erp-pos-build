# Adversarial Review — Owner Reporting Dashboard Plan
**Date:** 2026-06-20
**Reviewer:** Codex
**Plan:** docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md
**Verdict:** See final verdict

## Executive Summary
The plan is directionally aligned with the approved spec, but it is not executable as written. The backend TDD path will fail before reaching the intended missing classes because the raw fixture inserts omit required company/location/POS columns and because the service test calls a request-scoped currency resolver with no `CompanyContext`. The frontend plan also contains type-level breaks (`OwnerChart` props, missing `formatQuantity` export) and a permission-nav mismatch that would expose the Reports nav item to users who do not have `dashboard.owner`. Revise before implementation; otherwise a fresh engineer will spend the first pass debugging plan scaffolding instead of implementing the dashboard.

## Findings

### BLOCKER
1. **Task 2 raw seed cannot insert valid companies, locations, receipts, or receipt lines.**  
   Evidence: The plan inserts companies with only `id`, `name`, and timestamps, and locations with only `id`, `company_id`, `name`, and timestamps (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:234` and `:235`). Real `companies` require `tenant_id`, `country_code`, `currency`, `locale`, and `timezone` with no defaults (`apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:25`, `:26`, `:34`, `:59`, `:60`, `:61`). Real `locations` require `type` (`apps/api/database/migrations/tenant/2025_11_30_105000_create_locations_table.php:25`, `:26`, `:29`, `:31`). The plan's `pos_receipts` insert omits required `tenant_id`, `terminal_id`, `receipt_number`, `receipt_year`, `vat_breakdown_hash`, `payment_methods_hash`, `cashier_id`, and `cashier_name` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:204`), while the migration requires them (`apps/api/database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:25`, `:32`, `:37`, `:39`, `:44`, `:45`, `:51`, `:54`). The line insert omits required `line_number`, `product_code`, `tax_rate`, `tax_amount`, and `discount_amount`, and supplies a random `product_id` that has no matching product row (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:219`); the table requires those line fields and FKs (`apps/api/database/migrations/tenant/2026_01_08_190638_create_pos_receipt_lines_table.php:25`, `:30`, `:33`, `:39`, `:50`, `:51`, `:57`).  
   Concrete fix: Replace Task 2's raw DB inserts with the existing factory-style fixture used by `OwnerReportingTest` (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:410`, `:432`, `:454`) or create a shared test helper that creates `Tenant`, `Company`, `Location`, `Terminal`, `User`, valid `Receipt`, valid `ReceiptLine`, and payment rows. Include all required POS columns or avoid raw insert entirely.

2. **Task 2 calls bare `getScale()` outside a request/company context, so the service test will throw before testing aggregates.**  
   Evidence: The planned service calls `$this->scaleResolver->getScale()` before checking empty scopes (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:318`). The resolver throws when no currency code and no `CompanyContext` are bound (`apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:43`, `:45`, `:46`, `:47`, `:50`), and the precision contract explicitly warns that bare no-arg `getScale()` throws in queued/console/transition contexts (`CLAUDE.md:72`). The Task 2 service test resolves the service directly with `$this->app->make(...)` and never sends the `X-Company-Id` request header or binds `CompanyContext` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:244`), whereas the existing endpoint tests pass company headers (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:140`, `:405`).  
   Concrete fix: Either pass an explicit currency to `getScale($currency)` after enforcing same-currency scope, or bind `CompanyContext` in direct service tests before resolving the service. For non-request service use, prefer `getScaleSafe($currency, 3)` only when the fallback is a deliberate product decision.

3. **Task 3 endpoint test scaffolding references nonexistent paths/helpers, so the red step fails for the wrong reason.**  
   Evidence: The plan creates `apps/api/tests/Feature/Modules/Accounting/Reports/SalesSummaryEndpointTest.php` under namespace `Tests\Feature\Modules\Accounting\Reports` and imports `Tests\Feature\Modules\Accounting\Reports\Concerns\SeedsOwnerReportingTenant` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:453`, `:466`, `:468`). The actual existing owner endpoint test is `apps/api/tests/Feature/Accounting/OwnerReportingTest.php` under namespace `Tests\Feature\Accounting` (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:5`, `:30`). It has no `actingAsOwnerWithSales` or `actingAsUserWithoutPermission` helpers; it uses explicit `Sanctum::actingAs`, permission setup, memberships, and `companyHeaders()` (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:94`, `:95`, `:96`, `:138`, `:150`, `:405`).  
   Concrete fix: Put the new endpoint tests in `Tests\Feature\Accounting` or add a real shared concern first. Write the red test with the existing setup pattern: tenant/company/location/terminal factories, `Permission::findOrCreate('dashboard.owner', 'sanctum')`, `Sanctum::actingAs($owner)`, and `X-Company-Id` headers.

4. **Task 7's `SalesTrendChart` code does not match the real `OwnerChart` interface and will not typecheck.**  
   Evidence: The plan renders `<OwnerChart ...> <ReactECharts ... /> </OwnerChart>` without passing an `option` prop (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:930`, `:936`, `:937`). The real `OwnerChartProps` requires `option: EChartsOption` and does not accept `children` (`apps/web/src/features/owner-dashboard/components/OwnerChart.tsx:6`, `:8`, `:15`, `:28`). Existing chart components build an `option` object and pass it to `<OwnerChart option={option} ... />` (`apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx:20`, `:35`, `:37`).  
   Concrete fix: Rewrite Task 7 to mirror `SalesByLocationChart`: build an `EChartsOption`, pass it as `option={option}`, and remove the direct `ReactECharts` child import.

### HIGH
1. **The plan still converts money strings to `Number()` for the trend rollup.**  
   Evidence: `SalesByLocationData::gross_sales` is a string DTO field (`apps/api/app/Modules/Accounting/Application/DTOs/Reports/SalesByLocationData.php:19`). The plan's new `rollupSalesByPeriod` adds `Number(row.gross_sales)` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:905`), and the current chart already has the same float boundary (`apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx:30`). The precision contract says the frontend must never use `parseFloat`/`Number(...)` on money/quantity (`CLAUDE.md:74`).  
   Concrete fix: Either return chart coordinates from a backend endpoint with an explicit non-money chart contract, or use Big.js in the chart adapter and only convert to a JS number after reducing to display-scale chart points with an explicit comment and test. If the contract remains strict, avoid `Number()` entirely and feed ECharts string values only after verifying it accepts them.

2. **Sidebar `permission: 'dashboard.owner'` will not actually gate the nav item.**  
   Evidence: The plan says to add a Sidebar Reports entry "gated `dashboard.owner`" (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:976`, `:1026`). Real `Sidebar` treats a nav item's `permission` as a module key passed to `canAccessModule()` (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:97`, `:102`, `:366`, `:374`). `canAccessModule()` returns true when the string is not in `MODULE_PERMISSIONS` (`apps/web/src/hooks/usePermissions.ts:252`, `:253`, `:254`), and `MODULE_PERMISSIONS` contains `dashboard` and `reports`, not `dashboard.owner` (`apps/web/src/hooks/usePermissions.ts:189`, `:190`, `:197`). The actual permission exists separately in `PERMISSIONS` (`apps/web/src/hooks/usePermissions.ts:62`).  
   Concrete fix: Add a separate `requiredPermission?: Permission` field to `NavModule`/`NavChild` and check `hasPermission(requiredPermission)`, or add a dedicated module key such as `ownerReports` mapped to `['dashboard.owner']` and use that module key in Sidebar.

3. **Task 5 imports `formatQuantity` from a module that does not export it; the available fallback is float-based.**  
   Evidence: The plan imports `formatCurrency, formatQuantity` from `@/lib/decimal` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:642`, `:692`). `apps/web/src/lib/decimal.ts` exports `formatCurrency` (`apps/web/src/lib/decimal.ts:178`) but has no `formatQuantity` export; the existing `formatQuantity` is in `apps/web/src/lib/format.ts:93` and uses `parseFloat` (`apps/web/src/lib/format.ts:98`).  
   Concrete fix: Add a Big.js-backed `formatQuantity(value: string, scale = 4, locale?)` to `lib/decimal.ts` and import it from there, or move the existing formatter to Big.js before using it in new owner-report code.

4. **The plan has no same-currency rule for multi-company aggregation.**  
   Evidence: `OwnerReportScope::companyIds()` includes the root company and child companies (`apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php:34`, `:36`, `:43`, `:44`), and `companies.currency` is a required per-company column (`apps/api/database/migrations/tenant/2025_11_30_104000_create_companies_table.php:59`). The planned service aggregates all selected companies into one money summary while using one request-context scale (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:316`, `:318`, `:369`).  
   Concrete fix: Enforce one currency across `companyIds` before aggregating and return `currencyCode` in `SalesSummaryData`, or group the summary by currency. Add a test with parent/child companies that have different currencies and assert rejection or grouping.

5. **Task 9 leaves stock alerts scope wiring conditional even though the real API type supports it.**  
   Evidence: The plan says "Stock alerts uses `location_ids` too — pass it through if the param shape allows" (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:1097`). The real `StockAlertsParams` already allows `location_ids?: string[]` (`apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts:19`, `:21`), but the current page calls `useLowStockAlerts({ threshold_pct: 100 }, ...)` without location scope (`apps/web/src/features/owner-dashboard/OwnerDashboardPage.tsx:46`).  
   Concrete fix: Make stock alert scoping mandatory in Task 9: pass `location_ids` to `useLowStockAlerts` when `filters.locationIds` is non-empty and add a test asserting the hook receives it.

### MEDIUM
1. **The percentage helper truncates instead of rounding percentage deltas.**  
   Evidence: The plan computes percentages with `bcdiv(..., 6)`, `bcmul(..., 4)`, then `bcadd(..., '0', 2)` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:407`). `CurrencyScale::bcformatStrict()` also uses `bcadd($trimmed, '0', $scale)`, which formats/truncates rather than rounding (`apps/api/app/Shared/Domain/CurrencyScale.php:130`, `:143`, `:144`). The spec says ratios should be rounded once at the boundary (`docs/superpowers/specs/2026-06-19-owner-reporting-experience-design.md:65`).  
   Concrete fix: Add a small decimal rounding helper for percent strings, or compute percent with sufficient precision and use an existing round-half-away-from-zero boundary helper if one exists. Add a test such as prior `3`, current `5` expecting `66.67`, not `66.66`.

2. **Task 7 test code uses `as any`, and later lint commands with `--max-warnings=0` can fail on that warning.**  
   Evidence: The planned `rollupSalesByPeriod` test casts fixtures `as any` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:882`). The base ESLint config sets `@typescript-eslint/no-explicit-any` to warn (`apps/web/eslint.config.js:120`), and Task 8 later runs `pnpm lint --max-warnings=0 src/features/owner-dashboard ...` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:1031`).  
   Concrete fix: Type the fixture with `satisfies Partial<SalesByLocationReport>[]` plus a test-only factory that fills required fields, or use `const rows: SalesByLocationReport[] = [...]` with all generated DTO fields.

3. **Task 6 only tests `TopBar` prop behavior, not the route/layout integration that hides the switcher on `/reports`.**  
   Evidence: The plan's test renders `<TopBar showLocationSwitcher={false} />` and `<TopBar />` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:793`, `:795`, `:800`). The actual behavior depends on `DashboardLayout` passing the prop; today it always renders `<TopBar ... />` with no prop (`apps/web/src/components/templates/DashboardLayout/DashboardLayout.tsx:44`) and `TopBar` always renders `LocationSwitcher` (`apps/web/src/components/organisms/TopBar/TopBar.tsx:98`, `:99`).  
   Concrete fix: Add a `DashboardLayout` test with `MemoryRouter initialEntries={['/reports']}` and mocked `LocationSwitcher`, asserting it is absent on `/reports` and present on an operational route.

4. **Task 3 asserts only a one-sale happy path and 403, leaving core summary math untested at the endpoint boundary.**  
   Evidence: The planned endpoint test asserts only `data.grossSales = '120.000'` plus structure (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:481`, `:484`, `:485`). The required backend contract includes returns, sale counts, return counts, items sold, average basket, prior-period deltas, zero-previous percentages, and scope filtering (`docs/superpowers/specs/2026-06-19-owner-reporting-experience-design.md:46`, `:48`, `:49`, `:50`, `:51`, `:52`, `:53`, `:56`). Existing owner endpoint tests include scope rejection and child company coverage (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:199`, `:228`, `:240`).  
   Concrete fix: Extend the endpoint feature test to cover at least one return, one prior-period sale, scoped `location_ids[]`, and unauthorized location/company requests. Keep detailed arithmetic in the service test, but verify the controller wires scope into the service.

### LOW
1. **Plan commit messages do not follow the repository commit convention.**  
   Evidence: The repository instruction requires `Phase <major.minor.patch>: <imperative summary>`, while the plan uses `feat(reports): ...` commit messages (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:164`, `:443`, `:553`, `:630`, `:766`, `:854`, `:965`, `:1040`, `:1108`).  
   Concrete fix: Replace commit commands with phase-style messages or remove commit commands from the implementation plan and let the implementer commit according to the active phase number.

2. **The plan tells implementers to create new test directories instead of extending established nearby tests.**  
   Evidence: Existing owner-report frontend API tests live at `apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.test.tsx` (`apps/web/src/features/owner-dashboard/__tests__/ownerReportsApi.test.tsx:1`, `:18`), but Task 4 creates `apps/web/src/features/owner-dashboard/api/__tests__/ownerReportsApi.summary.test.ts` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:563`). Existing backend owner-report tests live under `apps/api/tests/Feature/Accounting` (`apps/api/tests/Feature/Accounting/OwnerReportingTest.php:5`), while Tasks 2 and 3 create `tests/Feature/Modules/Accounting/Reports` (`docs/superpowers/plans/2026-06-20-owner-reporting-dashboard.md:173`, `:453`).  
   Concrete fix: Prefer extending existing colocated owner-report tests unless there is a deliberate reason to split; if split, state the namespace/path convention explicitly and add shared fixture helpers first.

## Task-by-Task Executability Notes
Task 1 is mostly executable, but the test path `tests/Unit/Modules/Accounting/Reports` is a new convention compared with existing `tests/Unit/Accounting` and `tests/Feature/Accounting` structure; this is workable but inconsistent. The DTO shape is clear and generated TypeScript flow is correct.

Task 2 is not executable as written. The red test will fail on invalid raw inserts and/or `UnboundCompanyContextException`, not because `OwnerSalesSummaryService` is missing. The service math is directionally correct on date windows, but the scale and currency assumptions need hardening before implementation.

Task 3 is not executable as written. The test references nonexistent helpers and a nonexistent concern, omits the required company header used by existing endpoint tests, and will not fail for the intended missing route/action until that scaffolding is fixed.

Task 4 is executable if Task 1 has generated the DTO type. `apiGet` does unwrap `{data:{...}}` correctly (`apps/web/src/lib/api.ts:197`, `:198`, `:199`), and `buildParams` already serializes arrays as `location_ids[]` (`apps/web/src/features/owner-dashboard/api/ownerReportsApi.ts:68`, `:70`).

Task 5 will not typecheck with the exact imports because `@/lib/decimal` has no `formatQuantity`. The component test only checks labels, not formatted string values, currency code behavior, delta rendering, or the "new" null-percent state, so it would miss important display regressions.

Task 6 is partially executable. The prop seam is sound, but the plan uses path-based logic in `DashboardLayout` and tests only `TopBar`, not the layout route behavior. Add one layout integration test to make the red step fail for the actual `/reports` requirement.

Task 7 will not typecheck because `OwnerChart` is used with children instead of the required `option` prop. The rollup test also uses `as any` and does not test negative returns, precision-scale decimals, or the "legacy `gross_sales` is signed/net" naming risk.

Task 8 is under-specified around route imports and nav permissions. The router currently redirects `/reports` to `/finance` (`apps/web/src/routes/index.tsx:1581`, `:1582`), so the route replacement is real; however, adding a Sidebar item with `permission: 'dashboard.owner'` will not gate it unless Sidebar permission semantics are changed.

Task 9 is mostly executable for the filter UI because `useLocationStore` exposes `locations` with `id` and `name` (`apps/web/src/stores/locationStore.ts:34`, `:36`, `:73`). It should explicitly wire stock alerts because the type already supports `location_ids`, and the test should assert the page passes selected locations into every owner report hook, not only that the filter emits them.

## Verdict
REVISE-AND-RESUBMIT
Rationale: The plan resolves several spec-level decisions, but it still contains blockers in backend fixtures, request-scoped currency resolution, endpoint test scaffolding, and frontend chart typing. Fix those before asking an implementation agent to execute it.
Summary verdict: REVISE-AND-RESUBMIT — the plan is directionally right but not yet executable without significant corrective edits.
