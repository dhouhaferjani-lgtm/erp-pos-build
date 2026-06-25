# Adversarial Review: Owner Reporting Experience Design
Date: 2026-06-19
Reviewer: Codex

## Executive Summary
The spec is directionally strong: it correctly identifies the global location switcher conflict, keeps gross margin out of the demo scope, and chooses the existing Accounting owner-report surface instead of expanding POS analytics. It is not ready for implementation because the KPI math is under-specified around signed returns, the precision plan reuses existing float/`Number()` paths, and the route/permission wording misses tenant-token enforcement. Resolve the blockers before writing the implementation plan.

## BLOCKER Findings

### B-1: KPI formulas are underspecified around signed returns | Severity: BLOCKER | Section: §4.1 Backend, §5 Risks / open questions
Evidence: Spec line 55 says returns and net are "handled by `receipt_type` (SALE vs RETURN)" and line 59 asks tests to assert "net-of-returns, avg basket, delta math", but no exact formulas are specified. Actual code shows return receipts are already signed: `apps/api/database/migrations/tenant/2026_03_09_200000_add_return_fields_to_pos_receipts.php:15-19` says returns create negative receipts, and lines 64-65 say return lines have negative quantities and totals. The enum values are lowercase `sale` / `return`, not uppercase `SALE` / `RETURN`: `apps/api/app/Modules/POS/Domain/Enums/ReceiptType.php:7-10`.
Recommendation: Define the formulas explicitly before coding. At minimum: `totalSales = SUM(pos_receipts.total)` across sale and return rows because returns are already negative; `returnsAmount = ABS(SUM(total WHERE receipt_type = 'return'))`; `returnsCount = COUNT(return receipts)`; `itemsSold = SUM(pos_receipt_lines.quantity)` if the KPI is net items, or sale-only if it is gross items; `transactionCount` and `averageBasket` must state whether return receipts are excluded. Add tests for sale 100 + return -30 to catch accidental double subtraction.

### B-2: The precision plan reuses existing float/Number money paths | Severity: BLOCKER | Section: §3 Decisions, §4.2 Frontend
Evidence: Spec line 46 says "No float money math in the client" and line 68 says to reuse `salesByLocation` rolled up to a total trend line. The current backend formatter for owner report numbers uses `(float)` in `FormatsReportNumbers::decimalString`: `apps/api/app/Modules/Accounting/Application/Services/Reports/FormatsReportNumbers.php:9-13`. `SalesReportService` also computes category/payment percentages through floats at `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:131-138` and `:169-175`. The current frontend chart converts `gross_sales` with `Number(...)`: `apps/web/src/features/owner-dashboard/components/SalesByLocationChart.tsx:27-31`.
Recommendation: Do not build the KPI row or trend on these paths as-is. Either harden `SalesReportService` first to use `CurrencyScale::bcformatStrict`/bcmath and return a server-computed trend summary, or create a new summary/trend endpoint that performs all monetary aggregation server-side and returns display strings plus a non-money chart coordinate contract. Add a regression test that fails on `parseFloat`/`Number` in the new owner-report money path.

### B-3: Proposed route middleware omits tenant-token enforcement | Severity: BLOCKER | Section: §4.1 Backend
Evidence: Spec line 56 proposes route middleware `['api','auth:sanctum', SetPermissionsTeam::class]` and says it matches existing owner-report routes. Existing Accounting routes are grouped with `EnforceTokenTenantClaim::class` as well: `apps/api/app/Modules/Accounting/Presentation/routes.php:25`. This matters for multi-tenant permission isolation and token tenant binding.
Recommendation: Add the summary route inside the existing Accounting route group or explicitly include `EnforceTokenTenantClaim::class`. The spec should say the route inherits `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` plus `can:dashboard.owner`.

## HIGH Findings

### H-1: Multi-company currency aggregation is not defined | Severity: HIGH | Section: §4.1 Backend
Evidence: Spec line 55 allows `salesSummary(DateRangeData $range, array $companyIds, array $locationIds)` and computes one `totalSales`. `OwnerReportScope` can return parent plus child companies: `apps/api/app/Modules/Accounting/Application/Services/Reports/OwnerReportScope.php:34-44`. Companies have their own `currency` field: `docs/architecture/database.md:188`. A single current-company `CurrencyScaleResolverInterface` is not enough if selected companies can differ in currency.
Recommendation: Either enforce a same-currency scope before aggregation or group summary results by currency. Include `currencyCode` in `SalesSummaryData` and in tests. If the product rule is "one tenant/company tree uses one currency," assert that invariant instead of relying on demo data.

### H-2: `dashboard.owner` is too broad for the whole Reports IA without a role decision | Severity: HIGH | Section: §3 Decisions, §5 Risks / open questions
Evidence: Spec line 43 gates the dedicated Reports section on `dashboard.owner`, and line 85 asks whether `dashboard.owner` or a new `reports.view` should be used. Existing seeders register `dashboard.owner`: `apps/api/database/seeders/RolesAndPermissionsSeeder.php:187-192`, and the frontend hardcoded map grants it to `admin` and `manager`: `apps/web/src/hooks/usePermissions.ts:60-63`. OwnerReportScope does restrict requested locations by membership, as tested in `apps/api/tests/Feature/Accounting/OwnerReportingTest.php:199-225`, but a manager with root membership and `allowed_location_ids = null` can still see all owner-report locations.
Recommendation: Decide the permission model explicitly. If this is owner-only, introduce `reports.owner.view` or tighten role grants. If branch managers may access it, document that access is membership-scoped and add tests for manager root membership, child-company membership, and custom owner roles.

### H-3: `/reports` collides with existing finance report routing and frontend module mapping | Severity: HIGH | Section: §4.2 Frontend
Evidence: Spec line 63 proposes a new `/reports` owner overview and says to repurpose/delete the orphaned reports page. Existing routing redirects `/reports` to `/finance`: `apps/web/src/routes/index.tsx:1581-1582`. Existing frontend module access maps `reports` to `reports.view`, not `dashboard.owner`: `apps/web/src/hooks/usePermissions.ts:188-198`.
Recommendation: Make the route and nav contract explicit. Either replace the `/reports` redirect and gate it with `RequirePermission permission="dashboard.owner"`, or use a less ambiguous route such as `/owner-reports`. Update `MODULE_PERMISSIONS` only if the module key is intended to mean owner reporting.

### H-4: Route-aware TopBar hiding is serviceable but couples the shell to owner-report URL shape | Severity: HIGH | Section: §4.2 Frontend, §5 Risks / open questions
Evidence: Spec line 65 proposes `TopBar` conditionally hiding `LocationSwitcher` based on owner Reports routes; line 83 asks whether route awareness is the cleanest seam. Current `TopBar` renders `LocationSwitcher` unconditionally at `apps/web/src/components/organisms/TopBar/TopBar.tsx:95-100`, while `LocationSwitcher` mutates persisted operational scope and invalidates stock queries at `apps/web/src/components/organisms/LocationSwitcher/LocationSwitcher.tsx:70-80`.
Recommendation: Avoid hard-coded path checks in `TopBar`. Prefer route metadata/layout configuration such as `hideGlobalLocationSwitcher` on the owner-report route, or a narrow layout prop from the route shell. That keeps the global shell ignorant of feature route strings while preserving the desired UX.

### H-5: Cross-module read coupling to POS schema is being expanded, not isolated | Severity: HIGH | Section: §4.1 Backend
Evidence: Spec line 55 adds another `SalesReportService` aggregate over `pos_receipts` and `pos_receipt_lines`. The architecture guide says cross-module communication is allowed through shared contracts, events, or public service classes and forbids direct cross-module entity imports: `.claude/context/architecture.md:45-52`. The current service already reads POS tables directly: `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:31-50` and `:74-88`.
Recommendation: If this owner-report surface is strategic, introduce a POS reporting read port or documented read-model contract rather than adding more ad hoc Accounting queries over POS tables. If direct query reads remain accepted for reporting, state that exception in the spec and pin the queried columns in tests.

### H-6: Prior-period delta semantics are not complete enough to implement safely | Severity: HIGH | Section: §4.1 Backend, §5 Risks / open questions
Evidence: Spec line 55 says prior period is "shifting the range by its own length" and line 82 asks if equal-length immediately preceding is acceptable. It does not define inclusive boundary behavior, absolute delta versus percent delta, or prior-zero behavior. This will affect every KPI card and can produce divide-by-zero or misleading "infinite" changes.
Recommendation: Specify: previous range is the same number of calendar days immediately before `from`, using inclusive day boundaries; absolute delta is `current - previous`; percent delta is null/`not_applicable` when previous is zero unless a product rule says otherwise. Return both machine values and display labels from the API.

## MEDIUM Findings

### M-1: `SalesSummaryData` shape does not name the delta fields or scale contract | Severity: MEDIUM | Section: §4.1 Backend
Evidence: Spec line 54 lists current values and then says "plus prior-period counterparts for deltas." That is not enough for generated TypeScript consumers because the field names, nullable cases, and whether percent deltas are numeric strings are undefined.
Recommendation: Define the DTO explicitly, for example `current: SalesSummaryPeriodData`, `previous: SalesSummaryPeriodData`, and `deltas: { totalSalesAmount: string; totalSalesPct: string|null; ... }`, with `currencyCode` and quantity scale documented.

### M-2: Frontend permission checks currently ignore actual permission grants | Severity: MEDIUM | Section: §4.2 Frontend
Evidence: Spec line 63 says the sidebar Reports group is gated on `dashboard.owner`. The current hook has a TODO saying it uses a hardcoded role map and custom roles with granted permissions are silently denied: `apps/web/src/hooks/usePermissions.ts:1-4`. `hasPermission` checks roles, not a permissions array: `apps/web/src/hooks/usePermissions.ts:222-233`.
Recommendation: For this work, either use the real auth payload permissions if available or document the limitation and add a test for the intended owner/admin/manager roles. Do not assume backend `dashboard.owner` grants and frontend visibility are equivalent.

### M-3: Existing `salesByLocation` name/field semantics are misleading for net returns | Severity: MEDIUM | Section: §3 Decisions, §4.2 Frontend
Evidence: Spec line 46 says `salesByLocation` will be reused for trend and per-store comparison. The service field is named `gross_sales`, but it is `SUM(pos_receipts.total)`: `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:46-59`. Since returns are signed negative rows, this is net sales when returns are included, not gross sales.
Recommendation: Do not present this as "gross" unless return rows are filtered out. Rename the DTO field in a new endpoint, or explicitly document that existing `gross_sales` is legacy naming and the UI should label it as net sales when returns are included.

### M-4: Request validation guidance is lower-level than existing convention | Severity: MEDIUM | Section: §4.1 Backend
Evidence: Spec line 57 says to validate UUID arrays with `Str::isUuid`. Existing owner sales request already uses Laravel validation rules for `company_ids.*`, `location_ids.*`, and `location_id`: `apps/api/app/Modules/Accounting/Presentation/Requests/GetOwnerSalesReportRequest.php:23-36`.
Recommendation: Reuse or extend `GetOwnerSalesReportRequest` for the summary endpoint instead of hand-rolled `Str::isUuid` checks. That preserves consistent 422 responses and keeps validation in the Presentation request layer.

### M-5: TDD coverage is backend-heavy despite frontend behavior changes | Severity: MEDIUM | Section: §4.1 Backend, §6 Conventions
Evidence: Spec line 59 calls out PHPUnit feature/unit tests. Spec line 61-70 changes routing, filters, KPI cards, i18n, charting, and TopBar behavior, while line 90 says the work must honor "TDD (PHPUnit + Vitest, red→green)."
Recommendation: Add required Vitest tests before implementation: route guard/nav visibility for `dashboard.owner`, TopBar hides the switcher on owner-report routes only, filter multiselect serializes `location_ids[]`, and KPI cards render server-provided strings without money parsing.

### M-6: Table naming rule is not directly applicable, but the spec should say no new tables | Severity: MEDIUM | Section: §4 Proposed architecture
Evidence: The spec proposes DTOs, services, routes, and frontend components but no tables. The external 3-tier rule says generic vertical tables such as `reports` must be prefixed when scoped to one vertical: `../../../../claude/database-topology.md:21-43`. No proposed database table violates this because no table is proposed.
Recommendation: Add a short "No schema/table changes" note. If a future reporting snapshot/read-model table is introduced, apply the 3-tier rule at that point.

## LOW / NITPICK Findings

### L-1: Demo readiness percentage is unverifiable from this repo review | Severity: LOW | Section: §2 Current state
Evidence: Spec line 24 says four audits established the system is "~75-80% demo-ready." [UNVERIFIABLE] I did not find a cited audit artifact in the spec that defines that percentage or its scoring rubric.
Recommendation: Either cite the audit files/sections or remove the percentage. Keep the spec grounded in concrete capabilities and gaps.

### L-2: "SALE vs RETURN" casing is inaccurate | Severity: LOW | Section: §4.1 Backend, §5 Risks / open questions
Evidence: Spec lines 55 and 81 refer to `SALE`/`RETURN`. Actual enum values are lowercase `sale` and `return`: `apps/api/app/Modules/POS/Domain/Enums/ReceiptType.php:7-10`.
Recommendation: Use `ReceiptType::Sale->value` / `ReceiptType::Return->value` or quote the actual values `'sale'` and `'return'`.

## Verdict
NEEDS-REVISION — the direction is sound, but implementation should not start until the returns formulas, precision-safe aggregation path, tenant middleware, and owner-report permission model are tightened.
