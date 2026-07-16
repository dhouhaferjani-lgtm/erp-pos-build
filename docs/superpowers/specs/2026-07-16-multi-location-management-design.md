# Multi-Location Management — Design Spec

> **Date:** 2026-07-16 · **Rev 2** (Rev 1 owner-approved 2026-07-16; Rev 2 folds in the 4-lane adversarial review — see `reviews/2026-07-16-multi-location-design-review.md`; verified against dev @ `37ee87ac2`)
> **Seed:** `docs/superpowers/audits/2026-07-09-multi-location-management-audit.md` (G1–G15) + 4 research lanes (2026-07-13) + 4 adversarial review lanes (2026-07-16).
> **Owner rulings:** scope = locations within ONE company (no cross-company consolidation); two-scope model (view scope ≠ transact scope); all four work packages in; design financial attribution now so Treasury Phase ⑤ (bank import) builds on it.

---

## 1. Problem

The data model is largely multi-location-ready, but the product forces users to *switch* locations to see anything: one global single-location `LocationSwitcher` (whose switch invalidates every company-scoped query), ad-hoc per-page multi-selects, and no aggregate surfaces. The owner's canonical pains:

- Stock transfer creation cannot show per-location stock at a glance (the page fetches the full vector then discards all but source — `CreateStockTransferPage.tsx:385-408`).
- No consolidated financial views: due payments (échéancier), settlement/clearing of instruments, cash position, AR/AP aging, and expenses are all company-wide blobs with no location dimension.
- First customer is a parapharmacy chain (one company, N stores); F&B central-kitchen/franchise vertical next.

## 2. Research verdicts this design rests on

### 2.1 Codebase (verified on dev @ `37ee87ac2`, re-verified by adversarial review 2026-07-16)

**Scope/context plumbing:**
- `LocationContext::setLocationId()` — zero callers; `resolveLocationId()` falls back to the company default location on every request. The header-bound-context ambition never shipped; web axios sends only `X-Company-Id` (`apps/web/src/lib/api.ts:144-148`).
- `ValidateLocationAccess` middleware — registered (`bootstrap/app.php:50`), applied to zero routes. Companion `App\Rules\ValidLocationAccess` unused in any FormRequest and uses the forbidden `app()` helper (`:83,93`).
- **⚠ `allowed_location_ids` is NOT inert (Rev 1 premise was wrong):** it is actively enforced, fail-closed, on stock transfers (`StockTransferController.php:48,131,221,254`) and replenishment (`ReplenishmentRequestController.php:51-56` — with a deliberate `replenishment.process` bypass: processors see all shops). `LocationContext::getAllowedLocationIds()` returns `[]` = deny-all for users with **no membership row** (`LocationContext.php:198,228`). **Absent-row ≠ NULL-column.**
- No write path sets `allowed_location_ids`, and `UserController::store` creates staff with **no membership row at all** — so existing staff are already a degraded class on the enforced endpoints.
- TWO location-list controllers exist: `Company\...\LocationController::index` (the wired one, inside `module:Inventory` + `can:inventory.view` — `Inventory/routes.php:29-33`) and a duplicate `Inventory\...\LocationController`. Neither filters by membership.

**Financial spine (post Treasury ②③④):**
- No `location_id` on `payments`, `payment_instruments`. **`documents.location_id` ALREADY EXISTS** (`2025_11_30_130000:67`, fillable, written on every document create via `resolveLocationId` — which means today's values all equal the company *default* location, not a real per-store value). `document_lines.location_id` exists. `payment_allocations.location_id` and `journal_entries.location_id` exist but are **dead schema** (not fillable, never read/written) — both left inert, documented as superseded.
- `payment_repositories.location_id` exists but is **write-only** (`formatRepository()` omits it) **and NULL in every seeded/real tenant** — no seeder or UI populates it.
- POS-originated payments are written by **queued bridges with no CompanyContext** (rule 20): `TreasuryReceiptBridge` (`:604`; repository via `resolveDefaultRepository` `:460-468`), `TreasuryAccountPaymentBridge`, `TreasuryDepositBridge`. Terminal→location is resolvable in that layer (`event->terminal_id`, cf. `PosCoreReceiptProjection.php:181,260`).
- Échéancier/cash/AR-AP/expense analytics: all company-scoped only. The proven location pattern is the Owner Dashboard stack (`OwnerReportScope` + `location_ids[]` params + per-location grouping in `salesByLocation`/`cashRegisterReconciliation`/`liveSales`) — mature, but permission-blind (no bypass concept) and hard-wired to parent-company expansion.

**Inventory/web surfaces:**
- Replenishment (2026-07-12) shipped the first product×location matrix (`ReplenishmentQueuePage.tsx:158-203`, pivots pending requests — no pagination/search/stock data) and `RequestContextPanel` (per-location vector + surplus highlighting, `:47-48`).
- **⚠ `stock_levels.min_quantity`/`max_quantity` have NO write path in the app** (only the demo `StockLevelSeeder`) — NULL for every real tenant.
- Goods receipts post **all lines to the PO header location or company default** (`GoodsReceiptService.php:214-215,893-906`) while the incoming projection reads per-line `document_lines.location_id` (`LocationStockQueryService.php:260`) — projected and actual destinations can diverge today.
- `/products/{id}/stock-levels` already returns per-location reserved/min/max/incoming/projected (`productStock.ts:4-16`) — consumers discard fields, backend is not the gap.
- Semantics (pinned so implementers don't "fix" them): `available = quantity − reserved` (`StockLevel.php:102-105`); in-transit stock is already decremented from source quantity at dispatch; `pos_stock_policy_override` is oversell enforcement, irrelevant to availability math.
- Debt: `SalesByLocationChart` dead code; `BranchLeaderboard` ignores filters; `PosAnalyticsService` = 8 company-only methods/endpoints + 4 typed DTOs; `ProductMovementsTab` client-side filter (+ a `parseFloat` on quantity at `:268`); `audit-tanstack-keys.mjs` is a pure syntax scanner (cannot infer which queries are "location-consuming").

### 2.2 Industry (Lightspeed, Square, Toast, Odoo, NetSuite, Cegid, Pharmagest, McKesson)

1. **"All locations" is a first-class value of a persistent scope picker** (multi-select or group node). Forcing a location switch to see aggregates is a named anti-pattern.
2. **Transact scope ≠ view scope** — POS/register sessions are single-location; back-office reporting scope is multi-select. Toast separates the two as independent permission axes.
3. **Consolidated home = comparison widgets pre-aggregated to the user's scope, each drillable** into the same filtered detail report — never a separate "corporate" module.
4. **Financial segmentation = reporting dimension on transactions** (Xero tracking categories, QuickBooks Locations) — never new legal entities. Our Company vs Location split already matches.
5. **Access grants attach to the set, not per-location**; a restricted manager's allowed set silently becomes *their* "All" — same UI, narrower data.
6. Auto-rebalancing suggestions are bolt-ons everywhere — a genuine differentiation opportunity.

## 3. Design

### §1 — Scope foundation (package 1; blocks the rest)

**View scope (global, read concerns).** A TopBar **scope picker** replaces `LocationSwitcher` semantics: value = "All locations" (default) or any subset; single location = subset of size 1, not a different mode. It drives every list, report, and dashboard.

**Store topology (explicit).** Two stores, distinct jobs:
- **New `viewScopeStore`** — the multi-select view scope. Persisted to localStorage **keyed by companyId** (company A's subset never rehydrates under company B); reset to "All" only on a *real* company change (mirror `LocationProvider`'s `previousCompanyIdRef` guard); cross-tab sync reuses the hardened malformed-payload pattern (`locationStore.ts:180-187`).
- **Existing `locationStore`** — retired from view duty, kept as the per-user **working location** (transact default that pre-fills write forms). Migration targets off `currentLocationId`-as-view-filter: `StockLevelsPage`, `StockMovementsPage`, `ExpiryWriteOffPage`, `POSShiftsDashboard`.

**One canonical picker.** The TopBar scope picker is the single view-scope control. `OwnerDashboardFilters`' inline checkbox picker is **deleted** (dashboard consumes the global scope). `LocationSelectorMulti` is retained **only** for transact multi-select (e.g. counting-session creation). Per-page rule: **view-filter** pickers (replenishment queue, movements tab) default to the global scope with optional in-page narrowing that can never exceed the allowed set; **transact-selection** pickers (counting creation, transfer source/destination) are independent of view scope.

**Transact scope (per-form).** Every write flow (stock transfer, goods receipt, document, expense, counting) carries an **explicit `location_id` field in the form**, pre-filled from the working location. No write ever infers its location from the global scope or a hidden fallback.

**Transport & backend resolution.** Reads travel as explicit `location_ids[]` query params. New shared **`LocationScopeResolver`** (generalizing `OwnerReportScope`, which stays for owner reports until migrated):
- Signature carries an optional **bypass permission**: `resolve(user, requestedIds, ?string $bypassPermission)` — a user holding the bypass (e.g. `replenishment.process`, preserving today's deliberate carve-out) gets the full company set.
- Fail-closed (`AuthorizationException`) on out-of-scope requested ids; **treats absent membership row as NULL = all only AFTER the §1 backfill below** (until then absent-row is deny-all — that's the bug being fixed, not a behavior to preserve).
- **Single-company by default** — no `OwnerReportScope` parent-company expansion for non-owner callers.
- **HTTP-only** (requires bound CompanyContext). Backfills/migrations/queued jobs derive location directly from data, never via the resolver.
- Each consuming endpoint declares its bypass permission explicitly in its plan task.

Writes: explicit body `location_id` validated via `ValidLocationAccess` FormRequest rule — **after** refactoring it off `app()` (constructor-inject contexts) and **only after** the membership backfill lands. `ValidateLocationAccess` middleware stays retired.

**Authorization activation (G2) — ordered to avoid bricking:**
1. **Guarded tenant migration FIRST:** backfill a `user_company_memberships` row (`allowed_location_ids = NULL` = all) for every active user lacking one. Idempotent, self-guarding (push=deploy). Explicit test: absent-row user regains access, NULL-column user unaffected.
2. Fix `UserController::store` to create a membership row for new staff.
3. Staff create/edit UI gains location assignment ("all" or subset) writing `allowed_location_ids`. Gated by new permission **`users.manage_location_access`** (repo naming convention; seeded in `RolesAndPermissionsSeeder`, granted to owner/admin; deploy note: reseed + `permission:cache-reset` — tenant-blind cache). **Self-escalation forbidden:** a user can never edit their own `allowed_location_ids`, and cannot grant locations outside their own allowed set (owner exempt). Explicit deny-path tests.
4. Only then: enforcement extensions (resolver adoption on new endpoints, `ValidLocationAccess` on write paths).

**Behavioral-change surface (must be listed in the §1 plan):** endpoints already enforcing memberships (stock transfers, replenishment) change behavior the moment restricted memberships start existing — the plan enumerates and tests them.

**Locations-list endpoints (two variants, both specified):**
- **Scope-filtered** list for the picker: a **module-agnostic** endpoint (e.g. `GET /company/locations` in the Company route group, broad read gate — NOT behind `module:Inventory`/`can:inventory.view`, which would 403 treasury-only users). Returns only the user's allowed locations; a restricted user's "All" = their set.
- **Unfiltered management list** (gated by `users.manage_location_access` or equivalent) for the access-assignment UI and legitimate cross-location transact destinations (e.g. transfer *to* a store outside your view set).
- The duplicate `Inventory\...\LocationController` is removed or delegated — "every endpoint that lists locations honors the allowed set" is an invariant with a test (A5).

**Query-cache discipline (honest mechanism).** New `locationScopedKey(scope, [...])` helper wrapping `tenantScopedKey` (tenant/company stay suffixes), baking the effective scope as a **non-leading** segment — the resource literal stays `element[0]` so mutations keep cross-scope invalidation via bare-literal-prefix. The helper is added to `audit-tanstack-keys.mjs` `APPROVED_FACTORY_CALLS`; the "every location-consuming query uses it" contract is **enforced by the reviewer gate, not the linter** (a syntax scanner cannot infer endpoint semantics). Scope changes refetch only scoped queries; the old `activeScopePredicate` broad-invalidate is deleted with the switcher.

### §2 — Inventory visibility (package 2)

- **`ProductLocationMatrix`** shared component (visual pattern generalized from the replenishment grid; **data path is new work**): rows = product-rollup with expand-to-variant (never double-count the null-variant product row vs variant rows — mirror `LocationStockQueryService.php:120-122` discipline); columns = locations in scope; cell metric toggle (available / on-hand / vs min-max; incoming/projected as optional columns — they roughly double query cost). New "Stock by location" page. Backend: new bulk endpoint (`GET /inventory/stock-matrix`) — **paginate over products** (search joins products), then ≤3 grouped queries (on-hand, incoming-transfer, incoming-PO) restricted to the page's product ids × scoped locations, assembled into a zero-filled pivot. No per-cell queries. Aggregate tests run on **Postgres**.
- **Per-location min/max editor (pulled into scope — I1):** inline editing of `stock_levels.min_quantity`/`max_quantity` on the matrix and product stock section (+ endpoint). Without it the thresholds are NULL for every real tenant and both features below ship dead.
- **Suggested source & rebalancing — with NULL-threshold fallback:** primary rule = surplus (`available > max_quantity`) with largest excess; when thresholds are unset, fall back to "largest available above the requesting location's need"; explicit empty states when no source qualifies. Pinned semantics: available already excludes reserved and in-transit; `pos_stock_policy_override` is irrelevant here.
- **Transfers (G3, the trigger):** port the `RequestContextPanel` pattern into `CreateStockTransferPage` line entry — full per-location vector visible, suggested sources highlighted, destination stock + min/max shown.
- **Product page (G5):** wiring, not backend — the endpoint already returns reserved/min/max/incoming/projected per location; promote the collapsed table to a real section with a "transfer from here" CTA.
- **Purchasing (G6 + I2 destination-grain ruling):** destination is **per-receipt**: explicit selector in `ReceiveGoodsDialog`/`StandaloneReceiptPage` (with per-location stock/min-max context), written to the receipt AND kept consistent with the field the incoming projection reads — `GoodsReceiptService` posts to the chosen destination and the projection keys off the same source (reconcile the header-vs-line divergence; if per-line destinations are ever needed, that's a later extension).
- **Rebalancing view (G7):** owner-facing "out/below-min at A, surplus at B" list grouped by product, prefilled-transfer CTA, drillable per industry pattern; empty-state ruling per the fallback above.
- **G15 fix:** movements endpoint accepts `location_ids[]`; client-side filter deleted; replace `parseFloat(movement.quantity)` with `bccomp` while touching it.
- **Conventions contract (F7):** matrix + scope picker are pinned to canonical components (DataTable/token-styled grid, `PageHeader` on the new page), design tokens only, `t()`/RTL throughout, `formatQuantity` for cells (never `parseFloat`).

### §3 — Financial location dimension (package 3 — "due payments & clearing")

**Prerequisite (T2 — without this the package ships empty):** make `payment_repositories.location_id` real — return it from `formatRepository()`, add it to the repository create/edit UI (per-store assignment for cash registers/safes; bank accounts may stay NULL = company-level), and provide a one-time guided assignment (owner maps existing repositories to stores; no automatic guess).

**Schema (corrected — T1/T7):** add nullable indexed `location_id` ONLY to `payment_instruments` and `payments`. `documents.location_id` **already exists** — no migration, no line-derived backfill (it would overwrite live values). `expense_metadata` gets NO location column — an expense IS a Document; its location is the parent `documents.location_id` (single source; per-line splits would use `document_lines.location_id`). `payment_allocations.location_id` and `journal_entries.location_id` stay inert (documented, never populated here — GL dimension belongs to the accounting-GL roadmap).

**Documents-header semantics going forward:** §1's explicit transact-scope field starts writing *real* locations; historical rows keep their default-location attribution (documented caveat in every by-location financial report — pre-cutover data reads "default-attributed", no destructive correction).

**Write-time attribution — full writer enumeration (T3/T4), precedence explicit:**
| Writer | Location source |
|---|---|
| POS bridges `TreasuryReceiptBridge`/`TreasuryAccountPaymentBridge`/`TreasuryDepositBridge` (queued, no context) | **terminal→location via `event->terminal_id`** — overrides repository default; explicit entity currency to scale resolver (rules 19/20) |
| `PaymentController` (B2B/manual, incl. deferred-supplier) | the paid document's `documents.location_id`; outbound supplier instruments inherit from originating expense/document (G20/Phase ⑤ fold-in) |
| `MultiPaymentService`, `VendorRefundService`, `PaymentRefundService` | originating document/payment's location |
| `InstrumentLifecycleService::receive` | form field if present, else repository default |
| `PosCoreReceiptProjection`, `AdminBillingController` | terminal location / NULL (billing is company-level) |

**Instrument lifecycle rule (T5):** `payment_instruments.location_id` is set once at receive and **frozen** across custody-transfer/deposit/clear/bounce (never re-derived from the moving `repository_id`). Échéancier/clearing (instrument-grain = origin store) and cash position (repository-grain = custody location) are **intentionally different dimensions** and are never presented as mutually reconcilable.

**Reconciliation & signs (T6):** every by-location surface declares exactly one grain and sums **signed** amounts (bounce writes negative allocations). Named tests: (a) payment allocated across documents at two locations, (b) bounce-then-reallocate signed sum, (c) instrument in transit between repositories not double-counted across location views. Every report renders an **"Unattributed"** bucket so totals reconcile with company-wide figures.

**Backfill (guarded command + tenant migration, self-guarding per push=deploy):** instruments/payments ← repository `location_id` where set (meaningful only after the prerequisite); POS-originated rows ← shift→terminal→location where derivable; everything else stays NULL/Unattributed. Idempotent; never touches `documents`.

**Surfaces:** cash position by location (`formatRepository` exposure + `CashPositionController` `group_by=location` + `location_ids[]` via resolver); échéancier (`PaymentInstrumentController::index` + `MaturingInstrumentsController` filter/grouping); upcoming payments + AR/AP aging (documents-header grain, filter + optional breakdown); expense analytics/export (`location_id` filter + dimension via parent document); Finance hub adopts the global view scope. All new aggregations: bcmath + injected scale resolver + explicit currency (rule 19; matches `CashPositionController.php:69`).

**Treasury Phase ⑤ compatibility (owner directive):** bank-imported instruments/statement lines attach to a repository → inherit its `location_id` by the same default rule; `location_id` is reserved as import-settable. Phase ⑤ builds on this dimension, never re-models it.

**Non-goal:** formal GL-level P&L by location (journal-entry dimension) stays with the accounting-GL roadmap; this package delivers the operational approximation (sales + expenses + cash by location).

### §4 — Analytics & dashboard fixes (package 4)

- `PosAnalyticsService`: **bounded to "location filter only, no group-by DTO reshaping"** in this package (F6 — the full group-by touches 8 methods/endpoints + 4 generated DTOs = its own follow-up if wanted). `AnalyticsDashboardPage` adopts the view scope; a store-comparison widget rides the existing owner-report endpoints instead.
- Owner dashboard: wire the orphaned `SalesByLocationChart`; `BranchLeaderboard` respects the scope filter; `SalesTrendChart` gains a per-location series toggle; Z-report list gets cross-terminal rollup with a location column.
- Consolidated home widgets — cash across stores (§3), due-this-week (échéancier), rebalance alerts (§2) — each deep-links into the matching detail page with scope preserved (drillable-rollup rule).

### §5 — Non-goals, interactions, delivery

**Non-goals:** cross-company consolidation; per-location pricing (G12 — separate deferred track); per-location WAC/cost re-grain; GL-level P&L by location; POS device changes (terminal stays location-bound; `CrossLocationStockSection` untouched); per-location opening hours/config (G13); POS analytics group-by DTO reshaping (bounded out, see §4).

**Interactions:** replenishment owns G11 reorder-policy semantics (the §2 min/max editor is a data-entry surface, not a policy engine). Location hierarchy (`LocationNode`) is within-location and orthogonal. G20 outbound instruments handoff folds into the §3 writer table (no schema collision verified).

**Delivery:** one umbrella spec (this doc), **four implementation plans** — §1 first (foundation; carries the ordered backfill), §2 and §3 parallelizable after §1, §4 last/opportunistic. Migration ordering is explicit per plan (membership backfill → enforcement; repository-assignment → financial backfill). Reviewer gates per standing rule: `tenancy-authz-reviewer` (§1), `inventory-costing-reviewer` (§2), `treasury-reviewer` (§3), `frontend-conventions-reviewer` (all FE milestones).

**Testing:** TDD-first per package. Named critical tests: resolver fail-closed + absent-row-post-backfill + bypass permission; self-escalation deny paths; matrix pivot correctness on Postgres (variant no-double-count); transfer suggested-source incl. NULL-threshold fallback; receipt destination = projection source; the three §3 reconciliation tests; Unattributed bucket reconciles to company totals; scope-picker persistence across company switch + cross-tab; restricted user's picker shows only their set. E2E critical paths: create transfer with suggested source; per-location cash position; restricted manager sees only their stores end-to-end.

## 4. Gap-register mapping (G1–G15 → packages)

| Gap | Package | Note |
|---|---|---|
| G1 request-time location context | §1 | params + resolver, not header/singleton |
| G2 authz wiring | §1 | ordered: backfill → write path → enforcement |
| G3 transfer visibility | §2 | port RequestContextPanel pattern |
| G4 product×location matrix | §2 | new data path, generalized visual |
| G5 product detail breakdown | §2 | wiring only — fields already served |
| G6 purchasing location-blind | §2 | per-receipt destination, aligned w/ projection |
| G7 owner inventory comparison | §2+§4 | rebalancing view + wired charts |
| G8 POS analytics | §4 | filter-only in this pass |
| G9 expenses/payments location | §3 | via documents header + new payment/instrument columns |
| G10 receipts/documents location | §2+§3 | explicit destination; documents column already existed |
| G11 reorder split-brain | out | replenishment track owns it |
| G12/G13 pricing/config | out | deferred tracks unchanged |
| G14 two scope paradigms | §1 | two-scope model, one canonical picker |
| G15 client-side filter hack | §2 | server-side `location_ids[]` + bccomp cleanup |
