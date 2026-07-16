# Multi-Location Management — Design Spec

> **Date:** 2026-07-16 · **Status:** owner-approved design (brainstorm session 2026-07-13→16)
> **Seed:** `docs/superpowers/audits/2026-07-09-multi-location-management-audit.md` (G1–G15 register) + 4 fresh research lanes (2026-07-13: company/location switching map, treasury location dimension post-Phases ②–④, web surface inventory, industry standards).
> **Owner rulings:** scope = locations within ONE company (no cross-company consolidation); two-scope model (view scope ≠ transact scope); all four work packages in; design financial attribution now so Treasury Phase ⑤ (bank import) builds on it.

---

## 1. Problem

The data model is largely multi-location-ready, but the product forces users to *switch* locations to see anything: one global single-location `LocationSwitcher` (whose switch invalidates every company-scoped query), ad-hoc per-page multi-selects, and no aggregate surfaces. The owner's canonical pains:

- Stock transfer creation cannot show per-location stock at a glance (the page fetches the full vector then discards all but source — `CreateStockTransferPage.tsx:380-419`).
- No consolidated financial views: due payments (échéancier), settlement/clearing of instruments, cash position, AR/AP aging, and expenses are all company-wide blobs with no location dimension.
- First customer is a parapharmacy chain (one company, N stores); F&B central-kitchen/franchise vertical next.

## 2. Research verdicts this design rests on

### 2.1 Codebase (verified on dev @ `f1943d621`, 2026-07-13)

All four dormant subsystems flagged 2026-07-09 are **still dormant**:
- `LocationContext::setLocationId()` — zero callers; every request falls back to the company default location.
- `ValidateLocationAccess` middleware — registered (`bootstrap/app.php:50`), applied to zero routes.
- `user_company_memberships.allowed_location_ids` — no write path anywhere (`UserController::store` creates staff with **no membership row at all**); only reader is `OwnerReportScope` (allow-all in practice since the field is always NULL).
- Web axios sends only `X-Company-Id` (`apps/web/src/lib/api.ts:144-148`); no `X-Location` header exists anywhere.

Treasury (post Phases ②③④) is **location-blind end to end**: no `location_id` on `payments`, `payment_instruments`, `expense_metadata`, `expense_recurrence_templates`, or the `documents` header (only `document_lines.location_id` exists). Exceptions: `payment_repositories.location_id` exists but is **write-only** (never returned by `formatRepository()`, never grouped on by `CashPositionController`); POS shifts reach location transitively via `pos_terminals.location_id`. `payment_allocations.location_id` (added 2025-12-27) is **dead schema** — not fillable, never written, never read.

The proven pattern to generalize: **Owner Dashboard** = `OwnerReportScope` (resolves `companyIds`/`locationIds` from membership, intersects requested vs allowed, fail-closed) + query-param `location_ids[]` transport + multi-select filter UI (`OwnerDashboardFilters.tsx`) + per-location grouping in report services (`salesByLocation`, `cashRegisterReconciliation`, `liveSales`). Mature, tested, stops at the treasury boundary.

Web surface deltas since the audit: replenishment (shipped 2026-07-12) built the first real **product × location matrix** (`ReplenishmentQueuePage.tsx:158-203`) and `RequestContextPanel` (per-location vector + surplus-source highlighting — exactly the G3 fix, unported). Debt found: `SalesByLocationChart` is dead code (never imported); `BranchLeaderboard` ignores the page's location filter; `ReceiveGoodsDialog` has **no location UI at all** (destination resolved silently server-side); `PosAnalyticsService` has zero location dimension; `ProductMovementsTab` still filters client-side (G15 variant).

### 2.2 Industry (Lightspeed, Square, Toast, Odoo, NetSuite, Cegid, Pharmagest, McKesson)

1. **"All locations" is a first-class value of a persistent scope picker** (multi-select or hierarchy node). Forcing a location switch to see aggregates is a named anti-pattern.
2. **Transact scope ≠ view scope** — POS/register sessions are single-location; back-office reporting scope is multi-select. Toast separates the two as independent permission axes and warns against conflating them.
3. **Consolidated home = comparison widgets pre-aggregated to the user's scope, each drillable** into the same filtered detail report — never a separate "corporate" module.
4. **Financial segmentation = reporting dimension on transactions** (Xero tracking categories, QuickBooks Locations, NetSuite Location dimension) — never new legal entities. Promote to Company only on a real legal/fiscal signal (own tax ID / country / statutory books) — our existing Company vs Location split already matches.
5. **Access control attaches to the set/group, not per-location grants**; a restricted manager's allowed set silently becomes *their* "All" — same UI, narrower data.
6. Auto-rebalancing suggestions are bolt-ons everywhere (McKesson Pinpoint, third-party apps) — a genuine differentiation opportunity.

## 3. Design

### §1 — Scope foundation (package 1; blocks the rest)

**View scope (global, read-only concerns).** Replace `LocationSwitcher` semantics with a TopBar **scope picker**: value = "All locations" (default) or any subset; persisted per company (localStorage, cross-tab synced like `companyStore`). It drives every list, report, and dashboard. Single-location pick remains possible — it is just a subset of size 1, not a different mode.

**Transact scope (per-form).** Every write flow (stock transfer, goods receipt, document, expense, counting) carries an **explicit `location_id` field in the form**, pre-filled from a per-user "working location" preference (a default, not a filter; editable inline). No write ever infers its location from the global scope or a hidden fallback.

**Transport & backend resolution.** Reads travel as explicit `location_ids[]` query params. Generalize `OwnerReportScope` into a shared **`LocationScopeResolver`** (Shared/Contracts or Company module service): resolves the user's allowed set from `UserCompanyMembership.allowed_location_ids` (NULL = all), intersects with requested `location_ids[]`, fail-closes (`AuthorizationException`) on out-of-scope ids, returns the effective set. All scoped endpoints (existing owner reports + everything added in §2–§4) consume it. **Formally retire** the header-bound `LocationContext::setLocationId` ambition: reads = params + resolver; writes = explicit body field validated against the allowed set (activate the existing `App\Rules\ValidLocationAccess` in FormRequests for write paths). `ValidateLocationAccess` middleware stays retired unless a route genuinely needs pre-controller gating.

**Authorization activation (G2).**
- Staff create/edit UI gains a location-assignment control ("all locations" or subset) writing `allowed_location_ids`.
- Fix `UserController::store`: staff creation must create a `user_company_memberships` row (today it creates none).
- `LocationController::index` returns only the requesting user's allowed locations — a restricted user's picker only ever shows their set; their "All" = their set. No separate "restricted mode" UI.
- New permission `locations.manage-access` for assigning location access.

**Query-cache discipline.** Scope changes stop invalidating the world (today: `activeScopePredicate` refetches every company-scoped query). Location scope joins the query-key convention: location-scoped queries bake the effective scope into their key segments (existing pattern, now mandatory for location-consuming queries); extend `apps/web/tools/audit-tanstack-keys.mjs` to enforce it. The broad-invalidate path is deleted with the old switcher.

**Route/layout integration.** `DashboardLayout`'s `showLocationSwitcher` suppression becomes unnecessary — the scope picker is coherent on every page; owner-dashboard's own filter bar is replaced by (or synced to) the global scope control.

### §2 — Inventory visibility (package 2)

- **`ProductLocationMatrix`** shared component (generalize the replenishment matrix builder): rows = products/variants, columns = locations in scope, cell metric toggle (available / on-hand / incoming / vs min-max, deficit & surplus tinting). New **"Stock by location"** page under Inventory. Backend: new bulk endpoint (e.g. `GET /inventory/stock-matrix?location_ids[]&search&page`) over a generalized `LocationStockQueryService` — the per-product `/products/{id}/stock-levels` endpoint does not scale to a grid.
- **Transfers (G3, the trigger):** port the `RequestContextPanel` pattern into `CreateStockTransferPage` line entry — full per-location vector visible per line, surplus locations (`available > max_quantity`) highlighted as suggested sources, destination stock + min/max shown. "Suggest source" = pick the surplus location with the largest excess.
- **Product page (G5):** promote the collapsed `<details>` cross-location table to a real section: add per-location `reserved`, remove the >1-locations gate, add "transfer from here" CTA prefilling the transfer form.
- **Purchasing (G6):** `ReceiveGoodsDialog` gains an explicit destination selector with per-location context (current stock, min/max, open replenishment requests); same for `StandaloneReceiptPage`. This also closes the silent default-location fallback for receipts (G10's operational symptom).
- **Rebalancing view (G7):** owner-facing "Rebalance" list — products out/below-min at location A with surplus at location B (grain: `stock_levels.min/max_quantity`), grouped by product, with prefilled-transfer CTA. Ships as a section of the inventory comparison surface, drillable per industry pattern.
- **G15 fix:** movements endpoint accepts `location_ids[]`; `ProductMovementsTab` client-side filter deleted.

### §3 — Financial location dimension (package 3 — "due payments & clearing")

**Schema.** Nullable `location_id` (FK `locations`, indexed) on: `payment_instruments`, `payments`, `expense_metadata`, and the **`documents` header**. `payment_allocations.location_id` is documented as superseded and left inert (no resurrect, no drop in this spec).

**Write-time attribution.**
- Instruments & payments: default from their repository's `location_id` at creation (`InstrumentLifecycleService::receive`, payment creation paths); overridable where a form exists.
- Documents & expenses: explicit form field (transact scope), pre-filled from working location.
- POS-originated payments: attribute from the shift's terminal location (`pos_shifts → pos_terminals.location_id`).

**Backfill (tenant migration + guarded command, per push=deploy rule).** Instruments/payments ← repository `location_id` where set; documents ← dominant `document_lines.location_id`; expenses ← `expense_metadata.payment_repository_id → payment_repositories.location_id` where set. Everything else stays NULL = **"Unattributed"** — every by-location report renders an Unattributed bucket so totals always reconcile with company-wide figures.

**Surfaces.**
- **Cash position by location** (cheapest win): expose `location_id` in `formatRepository()`; `CashPositionController` gains `group_by=location` + `location_ids[]` via `LocationScopeResolver`.
- **Échéancier / clearing:** `PaymentInstrumentController::index` + `MaturingInstrumentsController` gain `location_ids[]` filter + by-location grouping; instrument list/detail pages show location; remittance flows unchanged.
- **Upcoming payments** (`UpcomingPaymentsService`) and **AR/AP aging** (`AgedReceivablesService`/`AgedPayablesService`): location filter + optional by-location breakdown (documents-header grain).
- **Expense analytics/export** (`ExpenseAnalyticsRequest`/`ExpenseIndexQuery`): `location_id` filter + by-location dimension.
- **Finance hub** adopts the global view scope.

**Treasury Phase ⑤ compatibility (owner directive).** Bank-imported instruments/statement lines attach to a repository → inherit its `location_id` by the same default rule; `location_id` is reserved as import-settable. Phase ⑤ builds on this dimension, never re-models it.

**Non-goal:** formal GL-level P&L by location (journal-entry dimension) stays with the accounting-GL roadmap. This package delivers the operational approximation: sales + expenses + cash by location.

### §4 — Analytics & dashboard fixes (package 4)

- `PosAnalyticsService`: `location_ids[]` filter + group-by-location on its methods; `AnalyticsDashboardPage` adopts the view scope; add a store-comparison widget.
- Owner dashboard: wire the orphaned `SalesByLocationChart`; `BranchLeaderboard` respects the scope filter; `SalesTrendChart` gains a per-location series toggle; Z-report list gets cross-terminal rollup with a location column.
- Consolidated home widgets — cash across stores (§3), due-this-week (échéancier), rebalance alerts (§2) — each deep-links into the matching detail page with scope preserved (drillable-rollup rule).

### §5 — Non-goals, interactions, delivery

**Non-goals:** cross-company consolidation (companies stay separate legal entities; revisit only on a real multi-company tenant); per-location pricing (G12 — separate deferred track, `project_cashier_price_override_deferred` adjacent); per-location WAC/cost re-grain; POS device changes (terminal remains location-bound; `CrossLocationStockSection` untouched); opening-hours/per-location config (G13).

**Interactions:** replenishment requests already ship location-grain min/max usage (G11 partially addressed there; no reorder-policy work here). Location hierarchy (`LocationNode`) is *within*-location and orthogonal — matrix columns are `Location` rows only.

**Delivery:** one umbrella spec (this doc), **four implementation plans** — §1 first (foundation), §2 and §3 parallelizable after §1, §4 last/opportunistic. Each plan gets the standing adversarial pre-dispatch review + per-milestone reviewer gates (`frontend-conventions-reviewer`, `treasury-reviewer` for §3, `inventory-costing-reviewer` for §2, `tenancy-authz-reviewer` for §1). All tenant migrations follow the self-guarding rule (push to origin/dev = staging auto-deploy incl. `tenants:migrate`).

**Testing:** each package's plan carries TDD-first backend (PHPUnit, scope-resolver fail-closed cases, backfill idempotency) and frontend (Vitest: scope picker persistence/cross-tab, matrix rendering, key-scoping audit rule) coverage; end-to-end critical paths = create transfer with suggested source, per-location cash position with Unattributed bucket reconciliation, restricted user sees only their set.

## 4. Gap-register mapping (G1–G15 → packages)

| Gap | Package | Note |
|---|---|---|
| G1 request-time location context | §1 | resolved as params + resolver, not header/singleton |
| G2 authz wiring | §1 | write path + enforcement + staff membership fix |
| G3 transfer visibility | §2 | port RequestContextPanel pattern |
| G4 product×location matrix | §2 | generalize replenishment matrix |
| G5 product detail breakdown | §2 | |
| G6 purchasing location-blind | §2 | ReceiveGoodsDialog worse than audited — no location UI at all |
| G7 owner inventory comparison | §2+§4 | rebalancing view + wired charts |
| G8 POS analytics | §4 | |
| G9 expenses/payments location | §3 | columns + backfill + surfaces |
| G10 receipts/documents location | §2+§3 | explicit destination + documents-header column |
| G11 reorder split-brain | out | replenishment track owns it |
| G12/G13 per-location pricing/config | out | deferred tracks unchanged |
| G14 two scope paradigms | §1 | unified two-scope model |
| G15 client-side filter hack | §2 | server-side `location_ids[]` |
