# Owner Reporting Experience — Design

**Date:** 2026-06-19 (revised 2026-06-20 after Codex adversarial review)
**Branch:** `feat/reporting-dashboard` (worktree `.worktrees/reporting`, off `origin/dev` @ 6c599d788)
**Status:** Design approved-in-dialogue; Codex-hardened; pending final owner review before writing the implementation plan.
**Codex review:** `docs/superpowers/reviews/2026-06-19-owner-reporting-codex-review.md` (2 BLOCKER, 4 HIGH, 4 MEDIUM, 3 LOW — all accepted; resolutions inline below).
**Driver:** Customer demo (parapharmacy, multi-branch, Tunisia). Demo data seeded in a parallel session. This spec is the *experience + architecture*; it must be production-grade and expandable, not a demo hack.

---

## 1. Problem

The web admin already has substantial, real-data reporting (owner dashboard, POS analytics, Z-reports, finance reports — ~75–80% demo-ready). The specific problems to solve:

1. **Conflicting scope models.** One global header location switcher (`TopBar` → `LocationSwitcher`, backed by the persisted `stores/locationStore.ts` `currentLocationId`) exists for *operational, single-location* work. Separately, the **Owner Dashboard** (`features/owner-dashboard/OwnerDashboardPage.tsx`) aggregates across ALL locations and is *embedded inside the main Dashboard* (`features/dashboard/Dashboard.tsx:314`). A header switcher parked on "Shop A" above an all-shops roll-up is contradictory and confusing for the owner persona.
2. **Multi-store story under-surfaced.** The owner-report backend already accepts `company_ids[]` / `location_ids[]` (`OwnerReportScope`), but the frontend never exposes a location scope control. Industry research (Square / Lightspeed / Shopify / Odoo) flags an explicit store selector + per-store comparison as THE multi-store credibility signal.
3. **No owner-level KPI summary + consolidated trend.** No top KPI-card row, no all-store sales-over-time trend.

---

## 2. Decisions (locked with owner)

1. **Dedicated owner Reports section** — a distinct top-level "Reports" area, gated `dashboard.owner`, that ALWAYS aggregates across locations. The header `LocationSwitcher` is **hidden** on these pages. Operational pages keep the switcher unchanged.
2. **Reports section = OWNER OVERVIEW ONLY.** It does NOT absorb the existing Finance/VAT/aged-receivables reports (those remain in the Accounting nav, gated `reports.view`). This avoids a permission migration and keeps accountant access intact. (Resolves Codex MEDIUM-permission + Open-Q5.)
3. **Tonight's build scope:** relocate the owner overview into the new section + make scope consistent + add a KPI-card row + a sales-over-time trend + verify real data end-to-end. New report *types* beyond this are a planned later phase.
4. **Gross margin KPI: DEFERRED** (needs a costed WAC aggregate; not rushed under demo pressure).
5. **KPI semantics = gross sales + separate returns card** (see §4 contract).
6. **Data sourcing = hybrid:** reuse existing `salesByLocation` for the trend + per-store comparison (already per-period, per-location); add ONE new, precision-correct, tested backend summary for the KPI cards. **No float money math in the client.**

---

## 3. Current-state facts (verified against code)

- **Owner-report backend** lives in `app/Modules/Accounting/`:
  - Services: `Application/Services/Reports/SalesReportService.php` (`salesByLocation`, `topSkus`, `revenueByCategory`, `paymentMethodBreakdown`), `StockAlertReportService`, `CashRegisterReportService`, `OwnerReportScope`.
  - Controller: `Presentation/Controllers/ReportsController.php` (verified path — NOT `Presentation/Http/Controllers`).
  - Routes: `Presentation/routes.php`, group middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]`; owner routes individually gated `can:dashboard.owner` (lines 181–204).
  - FormRequest: `Presentation/Requests/GetOwnerSalesReportRequest.php` — already validates `company_ids.*`/`location_ids.*` as `uuid`, singular `location_id`, `granularity` in day/week/month, `limit` 1–100, 366-day max range.
- **Precision debt (do NOT extend):** `SalesReportService` has no constructor injection and formats money via the `FormatsReportNumbers` trait → `number_format((float) $value, …)`. `revenueByCategory`/`paymentMethodBreakdown` also `(float)`-cast. This is the rule-19 anti-pattern; pre-existing, left out of scope, but **must not** be the home of new money math.
- **Receipt model:** `POS/Domain/Enums/ReceiptType.php` → `Sale = 'sale'`, `Return = 'return'` (lowercase). Return receipts are **negative** receipts referencing an original; return lines may be negative (`2026_03_09_200000_add_return_fields_to_pos_receipts` migration). `Receipt` casts `total`/`subtotal`/`tax_amount`/`discount_amount` as `decimal:3`; `ReceiptLine.quantity` `decimal:4`. There is an `is_voided` flag and a training/flag concept to exclude.
- **POS analytics** (`POS/Application/Services/PosAnalyticsService.php`) is single-company, no location filter — **not** a source for owner reporting, but a correct **reference** for return math: it uses `receipt_type='sale'` for average ticket, `COUNT(... 'return')`, and `SUM(ABS(total)) ... 'return'` for refunds.
- **Frontend owner dashboard:** `features/owner-dashboard/OwnerDashboardPage.tsx` is self-contained, gated `dashboard.owner`, ECharts widgets, data via `hooks/useOwnerReports.ts`, response types aliased from generated PHP DTOs in `api/ownerReportsApi.ts`. Its `OwnerDashboardFilters` exposes date range + granularity only — **no location control**.
- **Orphaned page:** `features/reports/ReportsPage.tsx` — hand-authored interfaces, `parseFloat` money, hardcoded Tailwind colors. **Do NOT build on it.**
- **Layout seam:** `components/templates/DashboardLayout/DashboardLayout.tsx` renders `TopBar` with no prop seam; `TopBar` unconditionally renders `<LocationSwitcher className="hidden lg:block" />`.

---

## 4. KPI summary SQL contract (exact — resolves BLOCKER-2)

Scope: receipts where `is_voided = false AND training_flag = false` (verified columns; `training_flag` exclusion is NF525-mandated and already applied by `salesByLocation`), filtered by `OwnerReportScope` (company_ids/location_ids) and `posted_at` in `[from, to]`. Enum values via `ReceiptType::Sale->value` / `ReceiptType::Return->value` (never string literals in app code).

| KPI | Definition |
|---|---|
| `grossSales` (string, currency-scaled) | `SUM(total) WHERE receipt_type = 'sale'` |
| `returnsAmount` (string, currency-scaled) | `ABS(SUM(total)) WHERE receipt_type = 'return'` |
| `netSales` (string) | `grossSales − returnsAmount` (derived server-side; shown implicitly, not a headline card tonight) |
| `salesCount` (int) | `COUNT(*) WHERE receipt_type = 'sale'` |
| `returnsCount` (int) | `COUNT(*) WHERE receipt_type = 'return'` |
| `itemsSold` (string, quantity-scaled) | `SUM(quantity) over receipt lines WHERE parent receipt_type = 'sale'` |
| `averageBasket` (string, currency-scaled) | `grossSales / NULLIF(salesCount, 0)` via BCMath; `null` when `salesCount = 0` |

**Cards rendered tonight (5):** Total sales (= `grossSales`), Transactions (= `salesCount`), Avg basket (= `averageBasket`), Items sold (= `itemsSold`), Returns (= `returnsAmount` + `returnsCount`).

**Prior-period deltas:** compute the same aggregates over the immediately-preceding equal-length window `[from − Δ, from)` where `Δ = (to − from)`. Each card shows **absolute + percentage** delta. Zero-previous → return `null` for the percentage and render a "new" indicator (never divide by zero, never fabricate 100%). (Resolves Open-Q3.)

**Money handling:** all monetary/quantity outputs are **strings**, computed and formatted server-side with `CurrencyScale::bcformatStrict`/`QuantityScale` using a constructor-injected `CurrencyScaleResolverInterface` (queued/no-context safety via `getScaleSafe($currency, 3)` if ever run outside a request). Ratios (avg basket, delta %) use BCMath at `scale+1` intermediate, rounded once at the boundary. No `(float)`, no `number_format` on money.

---

## 5. Backend design (Accounting module, hexagonal)

- **New DTO** `Accounting/Application/DTOs/Reports/SalesSummaryData.php` — strict-typed fields per §4 (current + prior + delta sub-DTO). No `mixed`. Add to `typescript:transform` output so types flow to `packages/shared/types/`.
- **New application service** `Accounting/Application/Services/Reports/OwnerSalesSummaryService.php` (NOT a method on `SalesReportService` — resolves BLOCKER-1 + HIGH-2). Constructor-injects `CurrencyScaleResolverInterface` and reuses `OwnerReportScope` for company/location filtering. One aggregate query for the period + one for the prior period (or a single windowed query). Returns `SalesSummaryData`.
- **Controller:** add `salesSummary()` to `Accounting/Presentation/Controllers/ReportsController.php`, returning a Resource/array of the DTO (respect the no-double-unwrap response convention).
- **Route:** `GET /api/v1/reports/sales/summary` added **inside the existing group** in `Accounting/Presentation/routes.php`, inheriting `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` and gated `->middleware('can:dashboard.owner')`. (Resolves HIGH-1.)
- **Request:** reuse `GetOwnerSalesReportRequest` (already validates the params we need). Add a narrow new request only if a field diverges. (Resolves LOW-1.)
- **TDD (PG-backed feature test):** seed real receipts via `RolesAndPermissionsSeeder` + a small fixture with ≥2 locations, ≥1 return, prior-period rows. Assert: gross excludes returns, returns = ABS(negative), salesCount excludes returns, avg basket = gross/saleCount, items from sale lines only, delta math, zero-previous → null %, TND 3-decimal currency + 4-decimal quantity exactness, and `dashboard.owner` gating (403 without). Unit test the scale/ratio helper.

## 6. Frontend design (`features/owner-dashboard` → owner Reports section)

- **Routing/nav:** new route (owner Reports overview) + a "Reports" sidebar group gated `dashboard.owner` (per nav convention doc). **Quarantine/delete** `features/reports/ReportsPage.tsx`; do not evolve it. (Resolves HIGH-4.)
- **Relocate:** remove `<OwnerDashboardPage />` from `Dashboard.tsx:314`; the owner overview becomes the Reports section home. Main Dashboard keeps its general KPI/recent-activity landing (no duplication).
- **Hide switcher via layout seam (not route-sniffing):** add `showLocationSwitcher?: boolean` from `DashboardLayout` → `TopBar` (default true); the Reports route renders the layout with `showLocationSwitcher={false}`. `TopBar` renders `<LocationSwitcher>` only when true. Do NOT put route knowledge in `TopBar`; do NOT mutate `locationStore` for reports. (Resolves HIGH-3.)
- **In-page location scope control:** extend `OwnerDashboardFilters` with an "All locations" default + per-store multiselect, holding its own `selectedLocationIds` state. Source the option list from the accessible-locations list (`useLocationStore.locations` as the read-only source for tonight), tolerating loading/empty/error; it must NEVER read or write `currentLocationId` / call `switchLocation()`. Wire `location_ids[]` through `useOwnerReports` + `ownerReportsApi` (API already serializes it). (Resolves MEDIUM-location + Open-Q4. Follow-up noted: a dedicated owner-scope locations endpoint if owners need child-company locations beyond operational context.)
- **KPI-card row:** new component consuming a new `useSalesSummary` hook → renders the 5 cards + abs/% delta chips, using generated DTO types. Money/qty via `formatCurrency`/`formatQuantity` only (never `parseFloat`/`Number` on money). **Skeleton + error-card states required** (resolves MEDIUM-states).
- **Sales-over-time trend:** roll up the existing `salesByLocation` per-period series to a total line (ECharts). `Number()` conversion only at the final chart-adapter boundary for coordinates — never for displayed/KPI money. Treat `gross_sales` as the signed period total it actually is (documented). (Resolves MEDIUM-trend.)
- **Per-store comparison:** frame the existing `SalesByLocationChart` explicitly as the comparison view; respect the scope multiselect.
- **i18n + tokens:** all strings via the `reports` namespace `t()` keys (en + fr); any new `.tsx` uses `@/lib/designTokens` exclusively; if `TopBar` is touched for the prop seam, make a minimal prop-based change only — do not broaden existing color drift. (Resolves LOW-color.)

## 7. Test-fixture & demo-data criteria

**NO retroactive fiscal seeding.** The fiscal receipt chain is append-only and integrity-critical; we will not manufacture, backfill, or seed `pos_receipts` into the demo tenant — even for the demo. **Demo data comes from MANUAL sales** performed by the team (available in a couple of days). This work is **code-only**; the plan contains no demo-seeder task.

The criteria below therefore apply ONLY to **TDD test fixtures** built in the ephemeral test DB (`RefreshDatabase`), never to any real/demo tenant: ≥2 locations with sale receipts in the current period; nonzero sales in the prior equal-length period (deltas); ≥1 return receipt; varied payment methods; enough distinct products for a top-SKU list; a zero-previous-period case. UI resilience is independent of data: the KPI row must render a skeleton while loading and an error card on failure, so the page never collapses regardless of how much manual data exists at demo time.

## 8. Explicitly OUT of scope tonight

Gross margin/profit cards; new POS-module location filtering; export-to-Excel wiring (hide dead buttons); multi-shop Z-report aggregation page; absorbing finance/VAT reports into the Reports section; refactoring the pre-existing `SalesReportService` float formatting (untouched — we add a new precision-correct service instead).

## 9. Conventions honored

Hexagonal layering; constructor injection only (no `app()`); strict typing (no `mixed`/`any`); DTOs for structured data; enums via `->value`; route middleware group `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` + `can:dashboard.owner`; types flow from backend (`typescript:transform`); TDD (PHPUnit PG-backed + Vitest, red→green); precision contract (string money/qty, `bcformatStrict`, no float, no `parseFloat`/`Number` on money); no double-unwrap (use `api.get`+`response.data` for any `{data,meta}` shape); i18n `t()` only; design tokens only; PHPStan L8 + Pint + ESLint clean (scoped `--filter`, never the full suite without permission).

## 10. Resolved open questions
- **Q1 transactionCount** = sale receipts only (returns counted separately). 
- **Q2 totalSales naming** = card label "Total sales" maps to `grossSales`; the misleading legacy `gross_sales` (signed) stays only in the trend/legacy DTO, documented.
- **Q3 delta** = absolute + %, zero-previous → null % / "new", never divide by zero.
- **Q4 location source** = separate `selectedLocationIds` from accessible-locations list; never `currentLocationId`.
- **Q5 Reports IA** = owner overview only; finance reports unchanged.
