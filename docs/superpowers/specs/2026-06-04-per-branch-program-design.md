# Per-Branch / Per-Establishment Program — Design Spec (B2B, Accounting, Reporting, Access)

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec` · worktree `apps/erp.branch-tax-id`
**Status:** Program design — pending review. Umbrella spec; each phase below becomes its own implementation spec + plan.
**Research basis:** Doc 06 (accounting current state), 07 (modeling), 08 (cross-module map + program scope), 09a (sub-account practice), 09b (sub-account mechanics), 09c (valuation/event-sourcing). The tax-ID slice is a **separate spec** (`2026-06-04-branch-tax-id-design.md`, = P0).

## 0. What this program is (and is not)

**Is:** make AutoERP **per-branch** for branches of **one legal entity** — one set of books, one tax entity, sliceable and (optionally) sub-accounted **by branch**, with the branch dimension reaching the **General Ledger** and the **reports**, plus the **infrastructure** for per-branch access control.

**Is not:** multi-*company* / statutory consolidation (that's the existing `parent_company_id` path, untouched here); per-branch **unit cost** (WAC stays company-wide); a new `establishments` entity (branch = a sellable `location`).

**Relationship to P0:** the tax-ID spec ships first and stands alone. This program is P1–P5. It depends on P0 only conceptually (same "branch = sellable location" model), not technically.

## 1. Locked decisions (owner, 2026-06-04 — see Doc 08 §0)

| | Decision |
|---|---|
| **A** | **Branch = a *sellable* location** (`LocationType::Shop`, `Location::isShop()`). Warehouses/offices/mobile are plain locations: no establishment identity, no branch sub-account. The ledger *dimension* (`location_id`) may still tag any location's activity. `parent_company_id` reserved for genuine multi-company groups. |
| **B** | **Hybrid accounting:** line-level `location_id` **dimension** is the always-on single source of truth; per-branch **sub-accounts** (`6420`→`64201`…) are an **optional, company-settings-toggled, selective** presentation layer (class 6/7 P&L + TN compte 17 / FR compte 18 liaison only). |
| **C** | **WAC unit cost stays company-wide.** Add per-branch **valuation + reporting** (branch qty × shared WAC) with proper **event sourcing**. No per-location cost column. |
| **D** | **Per-branch access control:** build the supporting **infrastructure** during this program; ship the **feature** as its own phase. |

## 2. The architectural spine (the one idea the whole program turns on)

> The branch dimension already exists at every transactional edge — POS terminals→location, inventory `stock_levels`/transfers, scheduling bays/appointments (`NOT NULL location_id`), `documents` header + lines — **but it dies the moment it should post to the General Ledger.** `journal_entries.location_id` has existed since Dec 2025 but is a **dead half-feature**: not in `$fillable`, written by zero posting sites, read by zero reports. The GL hash **excludes** `location_id`, so adopting it is **non-breaking** to the fiscal/GL chain.

**Therefore the program is, in order: (1) populate the branch dimension everywhere it's currently dropped, (2) carry it into the ledger, (3) report on it, (4) optionally sub-account it, (5) gate access by it.** Subaccounts ride on top of the dimension; they never replace it.

Three hard constraints govern the accounting work (Doc 09b):
- **`unique(company_id, system_purpose)`** ⇒ branch sub-accounts cannot share a purpose; purpose stays on the parent, children get a new `accounts.location_id`, and posting resolves via a new `findByPurposeForLocation()`.
- **Roll-up trap** (`AccountHierarchyService::calculateSubtotals` overwrites a parent with the sum of children) ⇒ once an account is branch-expanded, **all postings hit the branch child, never the parent**; children then auto-roll-up to the statutory parent for free.
- **Selective expansion only** ⇒ VAT/tax (445x) and balance-sheet accounts stay single (dimension-only).

## 3. Phases

Each phase is independently shippable, in order; each becomes its own implementation spec + plan.

### P1 — Branch-dimension foundation (populate what already exists)
**Goal:** make `location_id` reliably present on every source document/transaction so it *can* flow to the ledger. Mostly "populate columns that already exist."
**Scope:**
- Resolve Decision A in code: confirm `locations` as the branch unit; **scope/clean `OwnerReportScope`** which currently mixes `parent_company_id` and `allowed_location_ids` axes (`:62-99`).
- Populate `documents.location_id` (header) + `document_lines.location_id` at the seams that drop it: **Cart checkout** (`CartConversionService` never sets it), **Workshop WO→invoice** (`WorkOrderCreationService.php:87` hardcodes null; `DocumentGenerationAdapter` omits it), **Goods Receipt** (defaults to company default instead of the real branch), conversions/refunds (already partly propagate).
- Add the `pos_receipts.location_id` index.
**Risk:** Low–medium (additive population). **Deliverable:** every new document/receipt carries its branch.

### P2 — Ledger dimension + costing event-sourcing (the big one)
**Goal:** carry `location_id` into the GL and make inventory movements a complete, replayable ledger.
**Scope:**
- Adopt `journal_entries.location_id` (add to `$fillable`); decide **entry-level vs line-level** tag — **line-level** chosen (needed for inter-branch entries, Doc 07). Add `location_id` to `journal_lines` where required.
- Write the branch from the source doc/receipt at **every posting site**: `AccountingService::createInvoiceGLEntries/postCreditNote`, `GeneralLedgerService::createPOSPaymentEntry/createCOGSEntry/createPOSChargeEntry` (≈4 sites). Unify the duplicate purpose-resolvers.
- **Costing event-sourcing (Doc 09c items 1–3, same `location_id` plumbing):** populate `unit_cost`+`avg_cost_after`+`location_id` on **every** `stock_movements` writer (close the POS null-cost gap at `ReceiptCreationService::issueStock:894`); subscribe `StockMovementRecorded` (+ `ProductCostPriceUpdated`) in `DomainEventSubscriber`; thread `location_id` into COGS.
**Risk:** Medium (wide call-site surface) but **GL hash unaffected** → no fiscal-chain risk. **Deliverable:** branch-tagged ledger + durable, complete inventory cost trail.

### P2.5 — Optional branch sub-accounts (hybrid presentation, Decision B)
**Goal:** the toggle-gated `6420→64201` presentation layer. **Strictly additive; layered after P2 is proven.**
**Scope (Doc 09b §3):** `companies.subdivide_accounts_by_branch` boolean (default off) + per-account opt-in (default set: class 6/7 + liaison) · new nullable `accounts.location_id` (children point to branch; purpose stays on parent) · `findByPurposeForLocation()` resolver threaded through posting · `ChartOfAccountsService::seedBranchSubAccounts(Company, Location)` fired on `Shop` creation when toggle on (reuses the country-seeder two-pass pattern) · **roll-up-trap posting guard** (assert no line posts to a parent with children) · branch close ⇒ deactivate (never delete) children.
**Risk:** Medium — the roll-up-trap behavioral change needs a structural guard test. **Deliverable:** branch breakdown visible directly in the trial balance for opted-in accounts, auto-rolling up to statutory parents.

### P3 — Branch reporting
**Goal:** expose the dimension to humans.
**Scope:** add `location_id` filter/group-by to GL reports (`TrialBalanceService`, `ProfitLossService`, `BalanceSheetService`, general ledger, aged) + `PosAnalyticsService` + `DashboardController` (all company-only today). **P&L-by-branch = MVP**; per-branch balance sheet = stretch (needs balance accounts dimensioned). **Per-branch inventory valuation MVP** = `stock_levels.qty@branch × products.cost_price` (Doc 09c, no schema change); historical valuation needs the periodic snapshot (Doc 09c item 4).
**Risk:** Low–medium. **Deliverable:** consolidated + per-branch P&L and valuation; branch-filtered dashboards.

### P4 — Access-control infrastructure + platform (Decision D)
**Goal:** the *infrastructure* now; the feature can follow.
**Scope:** build the `UserCompanyMembership.allowed_location_ids` **write path** (currently read-only, no API); wire the existing `ValidateLocationAccess` middleware into routes (applied to zero today); decide tenant-wide vs **location-qualified roles** (Spatie team key is tenant_id today). Add `X-Location-Id` to `PlatformIntegration` outbound if the platform reasons about branches.
**Risk:** Medium (auth surface). **Deliverable:** infra that supports per-branch access; the user-facing feature ships as a follow-on.

### P5 — Optional commerce/ops per-branch (demand-driven, not a dependency)
Per-branch **pricing** (price lists are company-wide), branch-restricted **promotions/coupons** (distinguish *restriction* = new scope columns vs *reporting* = join via receipt→document), **channel** fulfillment branch (stock currently fans to all channels), per-branch **service/menu** availability, technician **home-branch**. Each is a product decision; pick per real need. **Loyalty balance stays cross-branch** (earn at A, redeem at B) — only attribution is per-branch.

## 4. Cross-cutting

- **Event sourcing:** Spatie laravel-event-sourcing is installed but inventory isn't plugged in; `audit_events` + `fiscal_events` are the durable patterns to follow. P2 makes `stock_movements` the authoritative replayable ledger; P3 adds the valuation snapshot. (Doc 09c §5.)
- **WAC invariant:** never add a per-location cost column; per-branch is valuation/reporting only (memory `project_inventory_costing`).
- **Fiscal safety:** the GL/inventory dimension work is non-breaking to the POS fiscal chain (hash excludes `location_id`); contrast P0's device seller-sourcing, which is value-only (no version bump).

## 5. Testing strategy
- Per phase, TDD with `RefreshDatabase` + real models + seeders (AutoERP conventions). Key adversarial tests: **roll-up-trap guard** (no posting to an expanded parent; children sum to parent), **purpose-resolution** (`findByPurposeForLocation` picks branch child when expanded, parent otherwise), **posting-site coverage** (every posting site writes `location_id`), **valuation reconciliation** (Σ branch valuations = company valuation), **report roll-up** (consolidated statements identical before/after sub-accounts exist).

## 6. Remaining gates / open decisions (before each phase locks)
1. **Expandable-account default set** + **branch suffix scheme** (`64201` vs `6420-01`) + **retroactivity** rule (enabling the toggle on a company with existing postings) — Doc 09b §5; worth a Tunisian accountant's confirmation.
2. **P&L-by-branch vs balance-sheet-by-branch** MVP scope (P&L recommended).
3. **Multi-site B2B customers** — model ship-to/bill-to establishment on `partners`, or out of scope?
4. **TN gates** (carried): per-establishment VAT-account granularity; invoice-series confirmation; NACEF (1 Jul 2026) per-register dimension.
5. **P5 commerce** — which, if any, are near-term.

## 7. Sequencing
P0 (tax-ID, separate spec) → P1 → P2 (+costing) → P2.5 (optional) → P3 → P4 (infra) → P5 (optional). P1–P2 are the load-bearing core; P2.5/P3/P4/P5 layer on the dimension and can be reordered by business priority once P2 lands.

**End of program design spec.** Each phase → its own implementation spec + plan (writing-plans), after this umbrella spec is reviewed.
