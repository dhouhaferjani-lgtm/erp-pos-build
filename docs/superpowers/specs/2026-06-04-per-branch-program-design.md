# Per-Branch / Per-Establishment Program — Design Spec (B2B, Accounting, Reporting, Access)

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec` · worktree `apps/erp.branch-tax-id`
**Status:** Program design — **rev 2** after Codex adversarial review 2026-06-04 (`docs/superpowers/reviews/2026-06-04-per-branch-program-codex-adversarial-review.md`, verdict NEEDS-REWORK → remediated below). Umbrella spec; each phase becomes its own implementation spec + plan.
**Research basis:** Doc 06 (accounting current state), 07 (modeling), 08 (cross-module map + program scope), 09a (sub-account practice), 09b (sub-account mechanics), 09c (valuation/event-sourcing — note 09c rev for the `recordCostAdjustment`/null-cost-writer corrections). The tax-ID slice is a **separate spec** (`2026-06-04-branch-tax-id-design.md`, = P0).

**Rev-2 changes (Codex review remediation):** B1 — P2/P2.5 require a **generated inventory** of all posting + purpose-resolution sites (§3 P2). B2 — **retroactivity is a P2.5 gate**, country-driven auto-activation + before-first-posting + mandatory warning, future HQ-child path reserved (§3 P2.5). M1 — **line-level `location_id` is canonical**, header is convenience (§3 P2, §2). M2 — precise GL-hash attestation wording (§4). M3 — event-sourcing broadened to all null-cost writers; `recordCostAdjustment` exists (§3 P2). M4 — `OwnerReportScope` cleanup is **security-significant** (§3 P1). M5 — a **minimal branch read model moves into P2** (§3 P2/P3). m1/m2/n1 — accountant-gated default, valuation-test scope, type-gating sentence.

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

> **Reconciliation with P0 (Codex n1):** P0 adds nullable tax-identity columns to **all** locations (no DB-level type-gating). That is consistent with "branch = sellable location": the **columns are universal**, but **establishment meaning, required-validation, sub-account generation, and UI affordances apply only to `LocationType::Shop`.** Implementers must NOT add hard NOT-NULL/type constraints that P0 deliberately avoided — gating is at the request/UI/generator layer, not the schema.

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
- Resolve Decision A in code: confirm `locations` as the branch unit; **separate the two axes in `OwnerReportScope` — this is SECURITY-significant, not taxonomy (Codex M4).** Today it expands scope to root + child companies (`OwnerReportScope.php:34-40`) then falls back to root-company membership when a child has none (`:62-86`), so **root-company membership can implicitly authorize child-company locations** in owner reports. P1 must split and test: (a) legal-entity *group* reporting via `parent_company_id`, vs (b) *branch/location* filtering inside one company via `allowed_location_ids`. They are different authorization primitives.
- Populate `documents.location_id` (header) + `document_lines.location_id` at the seams that drop it: **Cart checkout** (`CartConversionService` never sets it), **Workshop WO→invoice** (`WorkOrderCreationService.php:87` hardcodes null; `DocumentGenerationAdapter` omits it), **Goods Receipt** (defaults to company default instead of the real branch), conversions/refunds (already partly propagate).
- Add the `pos_receipts.location_id` index.
**Risk:** Low–medium (additive population). **Deliverable:** every new document/receipt carries its branch.

### P2 — Ledger dimension + costing event-sourcing (the big one)
**Goal:** carry `location_id` into the GL and make inventory movements a complete, replayable ledger.
**Scope:**
- **Line-level `location_id` is canonical (Codex M1, owner-confirmed).** Add `location_id` to `journal_lines` (currently has none — `JournalLine.php:14-46`) as the source of truth; `journal_entries.location_id` (already exists, just not in `$fillable` — add it) is **header convenience set only when all lines share one location, else null**. Mixed-location/inter-branch entries are represented at the line level. **Reports filter on lines, not headers.** State this invariant in the P2 implementation spec.
- **B1 — generate a complete posting/resolution INVENTORY first.** Do NOT rely on the ≈4 sites this spec originally named. Codex found many more: `JournalEntry::create` at `JournalEntryController.php:88-105` (manual), `AccountingService.php:74-89,112-123,228-239` (opening/invoice/credit), `InventoryOpeningService.php:325-337`, and ~12 sites across `GeneralLedgerService.php` (advances, payments, write-offs, vouchers, expenses, POS tolerance, COGS `:848-857`, POS payment `:1232-1241`, POS charge `:1306-1315`, inventory write-off, …); and purpose-resolution via `GeneralLedgerService::getAccountByPurpose` (~12 call sites), the duplicate `AccountingService::findAccountByPurpose` (`:331-352`), `Account::findByPurposeOrFail` direct (`InventoryOpeningService.php:321-323`), `PartnerBalanceService.php:165-199`, `ChartOfAccountsService.php:70-72`. The P2 plan starts by **generating** the authoritative list (grep `JournalEntry::create`/`JournalLine::create`/purpose lookups) with per-phase ownership — incomplete coverage silently loses postings via the roll-up trap once P2.5 lands. Unify the duplicate purpose-resolvers.
- **Costing event-sourcing (Doc 09c, same `location_id` plumbing) — ALL null-cost writers (Codex M3), not just POS:** populate `unit_cost`+`avg_cost_after`+`location_id` on every `stock_movements` writer — `ReceiptCreationService::issueStock:900-918`, `StockAdjustmentService:611-622`, `ReceiptVoidService:160-175`, `ReceiptReturnService:1019-1032`, `InventoryOpeningService:279-315`. Route company-WAC changes through the **existing** `WeightedAverageCostService::recordCostAdjustment()` (`:646-731`, called by `StockTransferService:506-521`) — do not invent a parallel path. Subscribe `StockMovementRecorded` (+ `ProductCostPriceUpdated`) in `DomainEventSubscriber`; thread `location_id` into COGS.
- **M5 — include a minimal branch read model in P2** (not deferred entirely to P3): a test-only/internal query asserting the line-vs-header invariant and a minimal branch P&L/GL slice, so P2's data is proven *interpretable* before P3 builds full reporting.
**Risk:** Medium (wide call-site surface). **GL-hash note (Codex M2):** adding `location_id` does not invalidate existing chains (hash excludes it, `GeneralLedgerHashService.php:54-76`) — but see §4: the current hash will not *attest* branch attribution; that is a conscious decision, not "no integrity concern." **Deliverable:** branch-tagged ledger (line-level) + durable, complete inventory cost trail + a proven minimal branch read model.

### P2.5 — Optional branch sub-accounts (hybrid presentation, Decision B)
**Goal:** the toggle-gated `6420→64201` presentation layer. **Additive, but gated on retroactivity (Codex B2) — NOT freely shippable until the gate is enforced.**
**Scope (Doc 09b §3):** `companies.subdivide_accounts_by_branch` boolean + per-account opt-in (default set: class 6/7 + liaison) · new nullable `accounts.location_id` (children point to branch; purpose stays on parent) · `findByPurposeForLocation()` resolver threaded through the **full** posting inventory from P2 · `ChartOfAccountsService::seedBranchSubAccounts(Company, Location)` fired on `Shop` creation when active (reuses the country-seeder two-pass pattern) · **roll-up-trap posting guard** (assert no line posts to a parent that has children) · branch close ⇒ deactivate (never delete) children.

**Retroactivity gate (owner decision 2026-06-04) — REQUIRED before P2.5 ships:**
- **Country-driven activation:** the country config carries a `subaccounts_auto` flag. For TN-like countries the toggle is **auto-activated**; elsewhere it defaults off.
- **Now:** expansion is **enable-only-before-first-posting** on an account (the safe path) — refuse to expand an account that already has direct postings (avoids the roll-up trap clobbering historical parent balances).
- **Always warn:** if a branch (sellable `Shop`) is created without sub-accounts activated, the user gets a **confirm-warning** they must acknowledge.
- **Future path reserved (must not be designed out):** the long-term target is **auto-create an HQ/`…-00` child + migrate the parent's existing balances/lines into it**, so a company can adopt sub-accounts after it already has postings. P2.5's schema + generator must be shaped so this migration can be added later without rework (e.g. the HQ child is just another `accounts.location_id` child; migration moves parent lines to it).
**Risk:** Medium — the roll-up-trap behavioral change needs a structural guard test; the retroactivity gate needs an explicit precondition check + the warning UX. **Deliverable:** branch breakdown visible directly in the trial balance for opted-in accounts, auto-rolling up to statutory parents, with the before-first-posting gate enforced and the HQ-child migration path left open.

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
- **Fiscal safety (precise — Codex M2):** adding `location_id` will **not invalidate existing GL chains** (the GL hash covers entry-number/date/company/total-debit/total-credit only — `GeneralLedgerHashService.php:54-76` — and model observers block post-hash mutation). **But the current GL hash will NOT *attest* branch attribution** (it already omits line account ids, source ids, descriptions). This is a **conscious compliance decision**, not "no integrity concern": decide whether branch location stays outside the GL hash forever, or — once branch P&L is user-facing/audit-relevant — gets a versioned hash-v2 treatment. Contrast P0's device seller-sourcing, which IS hash-covered (value changes the per-receipt hash) but needs no version bump.

## 5. Testing strategy
- Per phase, TDD with `RefreshDatabase` + real models + seeders (AutoERP conventions). Key adversarial tests:
  - **line-vs-header invariant** (line-level `location_id` canonical; header set only when all lines share a location; reports filter lines) — Codex M1.
  - **roll-up-trap guard** (no posting to an expanded parent; children sum to parent) + **retroactivity precondition** (expansion refused on an account with existing direct postings) — Codex B2.
  - **purpose-resolution** (`findByPurposeForLocation` picks the branch child when expanded, the parent otherwise) across the **full** posting inventory — Codex B1.
  - **posting-site coverage** (every site in the generated inventory writes `location_id`).
  - **valuation reconciliation — scoped (Codex m2):** assert **current** Σ(branch valuation) = current company valuation (`stock_levels.qty × products.cost_price`); historical/point-in-time valuation is explicitly **out of the MVP** (requires completed movement costs + the snapshot table).
  - **OwnerReportScope axis separation (Codex M4):** group reporting (`parent_company_id`) and branch filtering (`allowed_location_ids`) authorize independently; root-company membership does NOT implicitly grant child-company locations.
  - **report roll-up** (consolidated statements identical before/after sub-accounts exist).

## 6. Remaining gates / open decisions (before each phase locks)
1. **Expandable-account default set** + **branch suffix scheme** (`64201` vs `6420-01`) — Doc 09b §5; worth a Tunisian accountant's confirmation. *(Retroactivity is now DECIDED — see P2.5 gate: before-first-posting + country auto-activation + warning, HQ-child path reserved.)*
2. **P&L-by-branch vs balance-sheet-by-branch** MVP scope (P&L recommended).
3. **Multi-site B2B customers** — model ship-to/bill-to establishment on `partners`, or out of scope?
4. **TN gates** (carried): per-establishment VAT-account granularity; invoice-series confirmation; NACEF (1 Jul 2026) per-register dimension.
5. **P5 commerce** — which, if any, are near-term.

## 7. Sequencing
P0 (tax-ID, separate spec) → P1 → P2 (+costing) → P2.5 (optional) → P3 → P4 (infra) → P5 (optional). P1–P2 are the load-bearing core; P2.5/P3/P4/P5 layer on the dimension and can be reordered by business priority once P2 lands.

**End of program design spec.** Each phase → its own implementation spec + plan (writing-plans), after this umbrella spec is reviewed.
