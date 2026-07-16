# Adversarial Review — Multi-Location Management Design Spec (Rev 1)

> 4 Opus lanes vs `docs/superpowers/specs/2026-07-16-multi-location-management-design.md` @ `37ee87ac2` (2026-07-16). All findings verified against dev code with file:line evidence (full agent reports in session transcript; this is the durable register). **All findings below were folded into spec Rev 2 in the same commit as this file.**

| Lane | Verdict |
|---|---|
| tenancy-authz (§1) | APPROVE-WITH-FIXES, leaning REJECT for §1 as written |
| treasury (§3) | **REJECT** — two false load-bearing premises |
| inventory-costing (§2) | APPROVE-WITH-FIXES — 1 BLOCKER (feature ships dead) |
| frontend-conventions (§1/§2/§4) | APPROVE-WITH-FIXES — spec-completeness gaps |

## BLOCKERS (spec premises falsified against code)

- **A1 (authz):** `allowed_location_ids` is NOT inert — actively enforced fail-closed on `StockTransferController.php:48,131,221,254` and `ReplenishmentRequestController.php:51-56`; `LocationContext::getAllowedLocationIds()` returns `[]` (deny-all) for users with **no membership row** (`LocationContext.php:198,228`). Absent-row ≠ NULL-column.
- **A2 (authz):** staff created via `UserController::store` have no membership row → activating enforcement bricks all existing staff. Requires guarded membership backfill (NULL = all) BEFORE any enforcement activation; strict migration ordering under push=deploy.
- **A3 (authz):** `OwnerReportScope` cannot be generalized as-is — no permission-bypass concept (replenishment processors deliberately see all shops: `ReplenishmentRequestController.php:50-59`), and it hard-wires parent-company expansion. Resolver needs `?string $bypassPermission` and single-company default.
- **T1 (treasury):** `documents.location_id` ALREADY EXISTS (`2025_11_30_130000_add_company_id_to_existing_tables.php:67`, fillable `Document.php:122`, written on every create via `resolveLocationId` — values = company default today). Spec's add-migration would fail; its backfill would overwrite live data.
- **T2 (treasury):** `payment_repositories.location_id` is NULL in every seeded/real tenant (no seeder sets it, no FE round-trip since `formatRepository` omits it) → "default from repository" attributes ~100% to Unattributed; feature ships empty without a repository→location assignment prerequisite.
- **I1 (inventory):** `stock_levels.min/max_quantity` have NO write path in the app (only demo seeder) → rebalancing view + suggest-source (`available > max_quantity`) render dead for every real tenant. Need min/max editor in scope AND NULL-threshold fallback + empty states.

## MAJOR

- **A4:** the only locations-list endpoint is inside `module:Inventory` + `can:inventory.view` (`Inventory/routes.php:29-33`) → global scope picker 403s for treasury-only users/verticals. Need module-agnostic company locations endpoint.
- **A5:** TWO `LocationController::index` (Company + Inventory duplicates); filtering only one leaks the full set.
- **A6:** picker filtering starves management surfaces — access-assignment UI and cross-location transact destinations need an unfiltered, management-gated list variant.
- **A7:** new permission undefined: enforcement point, seeding (`RolesAndPermissionsSeeder` has no `locations.*`), tenant-blind cache reset, and a self-escalation hole (user with `users.update` widening own `allowed_location_ids`). Rename to `users.manage_location_access` (convention).
- **A8:** `App\Rules\ValidLocationAccess` uses `app()` (`:83,93`) and 422s memberless staff — refactor + activate only post-backfill.
- **T3:** POS payment writers are the queued bridges `TreasuryReceiptBridge` (`:604`, repo via `resolveDefaultRepository` `:460-468`), `TreasuryAccountPaymentBridge`, `TreasuryDepositBridge` — spec named none. Precedence must be terminal→location (available via `event->terminal_id`, cf. `PosCoreReceiptProjection.php:181,260`), overriding repository default; rules 19/20 apply (queued, no context, explicit currency).
- **T4:** 8+ `payments` writers (PaymentController, MultiPaymentService, VendorRefundService, PaymentRefundService, 3 POS bridges, PosCoreReceiptProjection, AdminBillingController) — each needs an explicit location source; outbound supplier instruments ← originating expense/document (G20/Phase ⑤ fold-in).
- **T5:** instrument location must be SET AT RECEIVE AND FROZEN across custody-transfer/deposit/clear/bounce; échéancier (instrument-grain, origin) vs cash position (repository-grain, custody) are intentionally different dimensions — never presented as mutually reconcilable.
- **T6:** reconciliation modes: payment allocations spanning locations; bounce = signed reversals (`InstrumentLifecycleService.php:508-528`); instrument-in-transit double-representation. Per-surface single grain + sign convention + 3 named reconciliation tests.
- **T7:** `expense_metadata.location_id` redundant — expense IS a Document; use parent `documents.location_id` (drop the column from the spec).
- **F2 (FE):** store topology undefined — keep `locationStore` as working-location (transact), add distinct `viewScopeStore`; migrate `StockLevelsPage`/`StockMovementsPage`/`ExpiryWriteOffPage`/`POSShiftsDashboard` off `currentLocationId`.
- **F3 (FE):** per-page picker rule missing — view-filter pickers (replenishment queue, movements) default to global scope w/ in-page narrowing ≤ allowed set; transact-selection pickers (counting creation) stay independent.
- **F4 (FE):** scope persistence must be keyed by companyId, reset only on real company change (mirror `previousCompanyIdRef`), reuse hardened cross-tab guard (`locationStore.ts:180-187`).
- **F5 (FE):** one canonical picker; delete `OwnerDashboardFilters` inline checkbox picker; `LocationSelectorMulti` retained for transact multi-select only.
- **F1 (FE):** `audit-tanstack-keys.mjs` is a syntax scanner — cannot detect "location-consuming" queries. Honest mechanism: `locationScopedKey()` helper (wraps `tenantScopedKey`, scope as NON-leading segment) added to APPROVED_FACTORY_CALLS; usage contract enforced by reviewer gate, not linter.
- **F6 (FE):** §4 POS analytics = 8 service methods + 8 endpoints + shared request + 4 DTOs (generated-type breaking) + page — bound §4 pass to "filter only, no group-by DTO reshaping" or promote to sub-plan.

## MINOR (fold into plans)

- **I2:** receipt destination grain: receipt posts ALL lines to header/default (`GoodsReceiptService.php:214-215,893-906`) while incoming projection reads per-line `document_lines.location_id` (`LocationStockQueryService.php:260`) — destination ruling must key both off the same field.
- **I3:** matrix grain = product-rollup rows w/ expand-to-variant; never double-count null-variant rows (`LocationStockQueryService.php:120-122` discipline).
- **I4:** matrix endpoint shape: paginate over PRODUCTS (search joins products), then ≤3 grouped queries for the page's ids; incoming/projected optional columns.
- **I5:** `/products/{id}/stock-levels` already returns reserved/min/max/incoming/projected per location (`productStock.ts:4-16`) — §2 product-page work = wiring, not backend.
- **I6:** pin semantics: available = quantity − reserved; in-transit already out of source qty; `pos_stock_policy_override` = oversell enforcement, irrelevant to source suggestion.
- **I7:** replace `parseFloat(movement.quantity)` (`ProductMovementsTab.tsx:268`) with `bccomp` during G15 rewrite.
- **I8:** matrix aggregate tests must run on Postgres, not SQLite.
- **I9:** matrix data path is NEW work (replenishment grid has no pagination/search/stock) — budget as such.
- **T8:** `journal_entries.location_id` exists dormant (`2025_12_27_150002`) — note, don't re-add, don't populate (GL roadmap's).
- **T9:** re-anchor spec to `37ee87ac2`; rule-19 line for new aggregations (bcmath + injected resolver + explicit currency in queued contexts).
- **A9:** permission naming → `users.manage_location_access`; seed + grant owner/admin + `permission:cache-reset` on deploy.
- **A10:** resolver is HTTP-only (requires CompanyContext); backfills/migrations derive location directly, never via resolver.
- **A11:** enumerate §1 migrations with pinned ordering: membership backfill → then enforcement.
- **F7 (FE):** matrix + picker pinned to DataTable/token grid, PageHeader, t()/RTL, formatQuantity — explicit contract for reviewer gate.
- **F8 (FE):** scope segment non-leading in query keys; mutations keep bare-literal-prefix invalidation (cross-scope correctness).
