# Branch / Per-Establishment — Doc 08: Cross-Module Impact Map & Program Scope

**Date:** 2026-06-04
**Branch:** `feat/branch-tax-id-spec`
**Type:** Synthesis + program scoping (built on docs 01–07). RESEARCH/PLANNING ONLY — no code change.
**Why this doc exists:** The owner flagged that the *branch tax-ID* spec (docs 01–05) is the narrow B2C-receipt slice, but the **B2B path + the accounting ledger** make per-branch much bigger, and asked for "proper research to figure out the complete list of all the modules" so the program is planned correctly from the start. This doc is that complete map. **It is explicitly OUT OF SCOPE for the branch-tax-id spec** — that spec stays limited to seller-identity/tax-number resolution (docs 01–05). This doc defines the *separate, larger* per-branch program that the tax-ID work plugs into.

> Inputs: Doc 01 (tax model & flow), Doc 02 (canonical payload), Doc 03 (compliance), Doc 04 (numbering), Doc 05 (multi-country), Doc 06 (accounting current state), Doc 07 (multi-branch accounting modeling), Doc 09a (sub-account practice), Doc 09b (sub-account mechanics & design), Doc 09c (per-branch valuation & event sourcing), plus a 6-cluster cross-module survey covering all 40 backend modules (2026-06-04).

> **Owner decisions locked 2026-06-04** (refine §0 below): (A) **branch = a *sellable* location only** (`LocationType::Shop`); warehouses/offices are plain locations with no branch tax-ID / sub-account. (B) **hybrid = ledger dimension (always) + optional per-branch sub-accounts (company-settings toggle, default off)** for analytics/presentation — see Doc 09b. (C) WAC unit cost stays **company-wide**; per-branch **valuation/reporting** added with proper event sourcing — see Doc 09c. (D) per-branch **access control**: build supporting infrastructure now, ship the feature phased/separately.

---

## 0. The two load-bearing decisions (read first)

### Decision A — "Branch" = `locations`, NOT a child `company`
The codebase contains **two competing models** for "branch" and they must be reconciled before any per-branch work:

- **`companies` (legal entity) → `locations` (physical place).** Stock, POS terminals, scheduling, and documents all already treat **`locations`** as the branch (`Location.php:45-63`; one company → many locations; `is_default`). This is the correct unit for *branches of one legal entity* — they share one `tax_id`, one `country_code`, one fiscal chain seed (`Company.php:101,124-126`).
- **`companies.parent_company_id` + `is_headquarters`** with `childCompanies()` whose docblock literally says "branches" (`Company.php:332-345`), and `OwnerReportScope.php:34-40` rolls up reporting via `parent_company_id`. This models branches as **separate legal entities** — separate `tax_id`s, fragmented fiscal hash chains.

**Recommendation (high confidence) — LOCKED:** for per-branch-of-one-legal-entity, **`locations` is the branch unit.** This aligns with the tax-ID spec's `locations.tax_id` override (docs 01/05), keeps one fiscal chain per company, and matches FR/TN law (one SIREN/matricule taxpayer, many établissements). The `parent_company_id` path stays for genuine multi-*company* groups (statutory consolidation) but must NOT be the per-branch primitive. **`OwnerReportScope` currently mixes both axes (`:62-99`) — that ambiguity is a program risk to resolve early.**

**Owner refinement (LOCKED 2026-06-04): only a *sellable* location is a "branch."** Not every location is a branch — a branch is a location that can sell (`LocationType::Shop`, `Location::isShop()` `Location.php:135-138`). Warehouses, offices, and mobile locations stay plain locations: **no branch tax-ID sub-id, no branch sub-account, no establishment identity.** The branch tax-ID override (docs 01/05) and the sub-account generator (§0-B / Doc 09b) apply to sellable locations only; the ledger *dimension* (`location_id`) can still tag any location's activity, but establishment identity attaches to shops.

### Decision B — Accounting is a branch DIMENSION, not per-branch subaccounts
The owner's framing ("every branch gets its own subaccount; accounting is consolidated but displayable by branch") is **half-right** (Doc 07, high confidence):

- ✅ **Intent correct:** one set of books per legal entity, sliceable by branch.
- ❌ **Mechanism wrong:** the modern, dominant ERP pattern (Odoo analytic accounts, ERPNext accounting dimensions + Cost Center, SAP profit center/segment, Sage Intacct dimensions) is **ONE chart of accounts + a `branch`/`location` dimension tagged on each journal line** — *subaccounts are the legacy thing dimensions replaced.* Subaccounts per branch explode the CoA and break `Account::findByPurposeOrFail` (one account per `(company_id, system_purpose)`, `Account.php:252`).
- ❌ **Terminology:** branches of ONE legal entity are **never statutorily consolidated** — "consolidation" is for separate legal entities. This is internal **segment / branch reporting** within one set of books. FR: one SIREN files **one** statutory account set regardless of établissement count; per-établissement figures live in **comptabilité analytique (class 9), which is optional/free-form** — i.e. exactly the dimension model. TN: same shape (Loi 96-112, entity-level books) — medium confidence.

**Owner refinement (LOCKED 2026-06-04): hybrid — dimension AND optional sub-accounts.** Keep the dimension as the single source of truth, AND *additionally* offer per-branch **sub-accounts** (`6420` → `64201`/`64202` per branch) as a **company-settings toggle (default off)** for analytical accounting + end-of-period presentation. Doc 09a confirms this is a **Tunisia-sanctioned** pattern (SCE/NC 01 permits "subdivisions de comptes"; the framework itself subdivides compte 17 per établissement) — *permitted, not mandatory*. Doc 09b works out the mechanics and three hard constraints:
> - Sub-accounts are **selective opt-in** (class 6/7 P&L + TN compte 17 / FR compte 18 liaison), **NOT** every account — full expansion is redundant double-tracking + CoA explosion. VAT/tax (445x) and balance-sheet accounts stay single (dimension-only).
> - **`system_purpose` must stay on the parent only** (DB has `unique(company_id, system_purpose)`); branch children carry a new nullable `accounts.location_id` and no purpose → a new `findByPurposeForLocation()` resolver is required.
> - **The roll-up trap:** reports overwrite a parent's balance with the sum of its children (`AccountHierarchyService.php:160-177`), so once an account is branch-expanded, **all postings must hit the branch child, never the parent.** Children then auto-roll-up to the statutory parent for free.

**⇒ The program is: (1) add a `location_id` dimension end-to-end so it reaches the ledger and reports (single source of truth, always on); (2) layer optional, toggle-gated, selective branch sub-accounts on top for presentation (Doc 09b). Not consolidation — segment reporting on one set of books.**

---

## 1. The spine finding: branch exists at the edges, but DIES at the ledger boundary

The single most important technical finding across all surveys:

> **The branch dimension is already plumbed at the transactional edges (POS, inventory, scheduling, document headers/lines) but is silently dropped the moment it should post to the General Ledger.**

Concretely:
- `journal_entries.location_id` **already exists** (migration `2025_12_27_150002_...:14-19`) with a docblock promising per-location P&L/balance-sheet — but it is a **dead half-feature**: not in `JournalEntry::$fillable` (`JournalEntry.php:49-67`), **written by zero posting sites**, read by **zero GL reports** (Doc 06).
- Every posting service drops it: `AccountingService::createInvoiceGLEntries` (`:104,112-123`), `createPOSPaymentEntry` (`GeneralLedgerService.php:1210`), `createCOGSEntry` (`:799`), `createPOSChargeEntry` (`:1277`) all write `company_id` only, even though the source `Document.location_id` / `Receipt.location_id` is right there.
- The GL hash does **not** include `location_id` (`GeneralLedgerHashService.php:54,71-73`) ⇒ **adding the branch dimension to journal entries is non-breaking to the fiscal/GL chain** (contrast the POS canonical `seller.tax_number`, which IS hashed — Doc 02). This makes the ledger work *lower-risk than it looks.*
- The read side already half-anticipates it: `Accounting/.../SalesReportService.php:56` and `GetOwnerSalesReportRequest.php:32` **accept a `location_id` filter** — but it resolves to NULL because the write side never populates it.

**This is the highest-leverage fix in the whole program:** populate `journal_entries.location_id` (and likely a `journal_lines`-level tag for inter-branch entries — Doc 07) from the source document/receipt at every posting site, then teach the GL reports to group/filter by it.

---

## 2. Complete module impact map (all 40 backend modules)

Legend — **Status:** ✅ already per-branch (don't re-plan) · 🟡 partial (column/seam exists, under-wired) · 🔴 gap (net-new dimension needed) · ⚪ out-of-scope / N-A.

### 2.1 Financial core (the B2B heart)
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **Accounting / GL** | 🔴 | Dead `journal_entries.location_id`; no posting site writes it; no report reads it; one account per `(company_id, purpose)`. **The core program work.** | `JournalEntry.php:49-67`; `AccountingService.php:104`; `GeneralLedgerService.php:799,1210,1277` |
| **Document** | ✅/🟡 | `documents.location_id` + `document_lines.location_id` exist; propagate into credit notes/refunds. Header→ledger drop is the break. | `2025_12_27_150000_...:24`; `DocumentLine.php:191-200`; `CreditNoteService.php:95`; `RefundService.php:102` |
| **Treasury** | 🟡 | `payment_repositories.location_id` (cash drawer↔branch) + `payment_allocations.location_id` exist; **`payments` itself has NO location.** | `2025_11_30_120000_...:34,139-168`; `2025_12_27_150001_...:22-36` |
| **Taxation** | 🔴 | VAT periods, withholding certs/rules, TEJ — **zero location awareness.** Seller `tax_number` per-establishment is the tax-ID spec; VAT-period/cert numbering per branch is open. | `2026_03_23_200000_create_vat_periods_table.php:15-46`; `2026_01_08_172147_..._withholding_certificates:16-78` |
| **Expense** | 🟡 | Expenses ARE documents → inherit `documents.location_id` for free. Category company-wide. | `2025_12_23_145311_...:16-32` |
| **Partner / Contact** | 🟡/🔴 | Customer/supplier identity is company-wide (correct). **No ship-to/bill-to establishment model** for multi-site B2B customers. | `2026_03_11_600000_add_b2b_fields_to_partners.php:14-21` |
| **Billing** | ⚪ | Synerivia's own SaaS tenant-billing. Out of scope. | `2025_12_16_100002_...:13-68` |

### 2.2 Inventory & supply
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **Inventory** | ✅ | **Most mature.** `stock_levels`/`stock_movements`/`stock_reservations`/`stock_transfers` all per-location; branch-to-branch transfers modeled. | `StockLevel.php:41-47`; `StockTransfer.php:60-67` |
| **Inventory — WAC** | ⚪ constraint | **Company-wide per product by design** (`companyOwnedQuantity` sums all locations → one `cost_price`). Per-branch *valuation* OK (qty/location × shared WAC); per-branch *unit cost* would collide. **Do not promise per-branch costing.** | `WeightedAverageCostService.php:94-118,257-260,367` |
| **BatchExpiry** | ✅ | `BatchStock` per location; batch identity company-wide. | `BatchStock.php:15-21` |
| **PurchaseHub** | 🔴 (low) | Outbound platform buying; no location concept. Only if platform POs must target a receiving branch. | `PurchaseHubService.php:79,128-132` |
| **Goods Receipt / PO** | 🟡 | Targets a location but **falls back to a default** (`$po->location ?? getDefaultLocation()`) — branch optional, not enforced. | `GoodsReceiptService.php:53,324-343` |
| **Uom / Catalog / Product** | ⚪/🔴(low) | Units = global ref data. Catalog/product company-wide (correct). Per-branch availability/price overrides = net-new product decision (would touch WAC). | `Product.php:77-105`; `CompositeItem.php:70-72` |

### 2.3 Commerce
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **Cart** | 🔴 | **No `location_id` anywhere** — checkout silently drops the branch; `CartConversionService` never sets it on `Document::create`. | `2026_03_10_500000_...:13-29`; `CartConversionService.php:114,204` |
| **Pricing** | 🔴 | Price lists company-wide; `PricingService` has zero location awareness. Per-branch pricing = product decision. | `2025_12_01_201012_create_price_lists_table.php:16-17` |
| **Promotion / Coupon** | 🔴 | Company-wide. Distinguish **restriction** ("valid only at branch X" = new scope cols) vs **reporting** ("redeemed at X" = join via receipt→document, mostly free). | `2026_03_02_200000_...:15-16`; `..._200003_create_coupons_table.php:15-16` |
| **Voucher** | ✅ | Terminal-bound (`issued/redeemable_at_terminal_id`) ⇒ transitively branch-scoped. | `2026_05_02_000001_...:61,66`; `VoucherRedemptionService.php:110` |
| **Loyalty** | ⚪/🟡 | **Balance is company/program-wide and should stay so** (earn at A, redeem at B). Only earn/redeem *attribution* is per-branch (derivable via `order_id`→document). | `loyalty_transactions` `..._100006_...:31`; programs `company_ids` json |
| **Channel** | 🔴 | Channels have no location; `DispatchStockChangeToChannels` fans **every** location's stock to **every** channel — no fulfillment/stock-source branch. | `2026_05_24_120000_create_channels_table.php:15`; `DispatchStockChangeToChannels.php:37,48` |
| **Marketplace** | 🟡 | Cross-tenant B2B; branch matters as stock-source/fulfilling establishment; buyer/seller docs already carry location. | `..._400001_create_marketplace_listings_table.php:15,30` |

### 2.4 Services & operations
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **Scheduling** | ✅ | **Most branch-mature.** `bays`/`configs`/`appointments` all NOT NULL `location_id`; per-location codes; GiST no-overlap per bay. | `2026_04_19_140003_...:38,42,121` |
| **Workshop** | 🟡 | `workshop_work_orders.location_id` exists but **hardcoded NULL on appointment-conversion** and dropped at invoice gen. Technicians company-scoped (no home-branch). | `WorkOrderCreationService.php:87`; `DocumentGenerationAdapter.php:119-136` |
| **Service / Menu** | 🔴(low) | Company-wide catalogs; per-branch availability/pricing not modeled (product decision). | `2025_12_12_120001_create_services_table.php:18-19`; `2026_02_20_100001_create_menus_table.php:15-16` |
| **Vehicle** | ⚪ | Tenant-global, no company/location (correct — a car isn't owned by a branch). | `2025_11_30_070000_...:15,32` |

### 2.5 POS / Fiscal / Compliance / Reporting
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **POS (transactional)** | ✅ | Terminals→location; receipts carry `location_id`+`terminal_id`; X/Z/shift per-terminal. (`pos_receipts.location_id` lacks an index.) | `Terminal.php:93,235`; `Receipt.php:39-40`; `PosCoreReceiptProjection.php:251` |
| **Fiscal chain** | ✅ | Keyed per-terminal (per-branch transitively). Adding location to GL is non-breaking (hash excludes it). | `FiscalEvent.php:26,97`; `GeneralLedgerHashService.php:54` |
| **POS owner analytics** | 🔴 | `PosAnalyticsService` filters `company_id` only, **bypasses the GL ledger** (reads `pos_receipts` projection) — operational, not financial-grade; no location filter/groupBy. | `PosAnalyticsService.php:19-22,302`; `AnalyticsRequest.php:23-28` |
| **Dashboard** | 🔴 | Company-wide KPIs only; no location dimension. | `DashboardController.php:30,37,54` |
| **Compliance — NF525 JET** | 🟡 | Export is company-scoped, single SIRET/address; per-establishment export open. (Also the null-SIRET bug — see tax-ID spec.) | `Nf525JetExportService.php:35-42`; `Nf525XmlBuilder.php:72` |
| **Compliance — audit/fraud** | 🔴(low) | `AuditEvent` has only `company_id` (no `location_id`/`terminal_id`) — can't slice audit by branch. | `AuditEvent.php:41-52` |

### 2.6 Platform & cross-cutting
| Module | Status | What's there / what's missing | Key file:line |
|---|---|---|---|
| **Company / Location** | 🔴 | Owns Decision A (locations vs child-company). `locations.tax_id` override is the tax-ID spec. | `Company.php:92-93,332-345`; `Location.php:45-63` |
| **Identity / access control** | 🔴 | **Per-branch user access is half-built and inert:** `UserCompanyMembership.allowed_location_ids` + `canAccessLocation()` + `LocationContext` + `ValidateLocationAccess` middleware all exist, but the middleware is on **zero routes**, there is **no write path** for `allowed_location_ids`, and Spatie roles are **tenant-wide**. | `UserCompanyMembership.php:25,113-121`; `LocationContext.php:196-236`; `SetPermissionsTeam.php:28` |
| **PlatformIntegration** | 🟡 | Outbound sends `X-Tenant-Id`+`X-Company-Id` only — **branch invisible to the platform.** | `PlatformHttpClient.php:213-245` |
| **Import** | 🔴(med) | `import_jobs` scoped by `tenant_id`+`user_id` only — no company *or* location dimension. | `ImportJob.php:20-21,53-69` |
| **Tenant** | ⚪ | Above company/location; branches live inside one tenant DB (no DB-per-branch). | `Tenant.php:229-232` |
| **Admin / Progression / SmartPrompts / Communication / Media** | ⚪/🟡(low) | Admin = N/A. Progression/SmartPrompts company-level. Communication/Media inherit branch from parent Document. | `RegisterCompanyWithGrowthAdvisor.php:21-41`; `DocumentAttachment.php:21-22` |

---

## 3. Proposed program structure (phased, by risk & dependency)

This is a **multi-spec epic**, separate from the branch-tax-id spec. Suggested ordering:

- **P0 — Tax-ID / seller identity (the existing branch-tax-id spec, docs 01–05).** `locations.tax_id`/`vat_number`/`legal_identifiers` + resolver + device sourcing. Independent; ships first. *No accounting impact.*
- **P1 — Branch dimension foundation (Decision A locked).** Confirm `locations` = branch; reconcile/scope `parent_company_id`; ensure `documents.location_id` (header) + `document_lines.location_id` are consistently **populated** at every creation seam (Cart checkout, Workshop WO→invoice, GR, conversions). This is mostly *populate the columns that already exist*.
- **P2 — Ledger dimension (the big one, Decision B core).** Adopt the dead `journal_entries.location_id`; decide entry-level vs **line-level** tag (line-level needed for inter-branch entries — Doc 07); write it from source doc/receipt at all posting sites (`AccountingService`, `GeneralLedgerService` ×4). GL hash unaffected. **Fold in the costing event-sourcing plumbing (Doc 09c items 1–3):** populate `unit_cost`+`location_id` on every `stock_movements` writer (close the POS null-cost gap `ReceiptCreationService.php:894`), subscribe `StockMovementRecorded` to the audit log, thread `location_id` into COGS — same `location_id`-threading, do it once.
- **P2.5 — Optional branch sub-accounts (Decision B hybrid, Doc 09b).** Strictly additive, toggle-gated, layered *after* the dimension is proven: `companies.subdivide_accounts_by_branch` + per-account opt-in + `accounts.location_id` + `findByPurposeForLocation()` + `seedBranchSubAccounts()` on Shop creation + the roll-up-trap posting guard. Selective set (class 6/7 + liaison), not full expansion.
- **P3 — Branch reporting.** Teach GL reports (trial balance, P&L, GL, aged) + `PosAnalyticsService` + Dashboard to filter/group by `location_id`. P&L-by-branch is the realistic MVP; per-branch balance sheet is a stretch (needs balance accounts dimensioned). **Per-branch inventory valuation MVP = `stock_levels.qty@branch × products.cost_price` (Doc 09c, no schema change);** historical valuation needs the snapshot (Doc 09c item 4). Add the `pos_receipts.location_id` index.
- **P4 — Access control & platform (Decision D: infra now, feature phased).** Build the supporting infrastructure during the program — the `allowed_location_ids` write path + wiring `ValidateLocationAccess` into routes — but the per-branch access *feature* can ship as its own phase. Decide tenant-wide vs location-qualified roles. Add `X-Location-Id` to PlatformIntegration if the platform reasons about branches.
- **P5 — Commerce/ops product decisions (optional, demand-driven).** Per-branch pricing, branch-restricted promos/coupons, channel fulfillment branch, per-branch service/menu availability, technician home-branch. Each is a product decision, not a forced dependency.

---

## 4. Decisions the owner must make (gates before the program spec locks)

1. ~~**Decision A:** `locations` = branch.~~ **LOCKED 2026-06-04** — branch = *sellable* location (`LocationType::Shop`); `parent_company_id` reserved for true multi-company groups. `OwnerReportScope` ambiguity still to resolve in P1.
2. ~~**Decision B:** dimension.~~ **LOCKED 2026-06-04** — **hybrid**: line-level `location_id` dimension (always) + optional toggle-gated selective sub-accounts (Doc 09b). Still to confirm: the **expandable-account default set** + **branch suffix scheme** + **retroactivity** (enabling on an existing company) — Doc 09b §5.
3. **Scope of P&L-by-branch vs balance-sheet-by-branch** for the MVP (P&L recommended).
4. ~~**Per-branch user access control** — in or deferred?~~ **LOCKED 2026-06-04 (Decision D)** — build infra now, ship feature phased/separately.
5. **Multi-site B2B customers** — model ship-to/bill-to establishment on `partners`, or out of scope?
6. **Commerce per-branch** (pricing/promos/loyalty/channel) — which, if any, are real near-term needs vs YAGNI?
7. ~~**Per-branch costing** — confirm NOT wanted.~~ **LOCKED 2026-06-04 (Decision C)** — WAC unit cost stays company-wide; per-branch **valuation/reporting** only, with proper event sourcing (Doc 09c). *Per-location unit cost remains high-blast-radius and out.*
8. **TN accountant gate (carried from Doc 03/05/09a/09b):** per-establishment VAT-account granularity + invoice-series confirmation; the expandable-sub-account set (per-establishment subdivision is *permitted, not mandatory*); NACEF (1 Jul 2026) per-register dimension.

---

## 5. Relationship to the branch-tax-id spec

The branch-tax-id spec (docs 01–05) is **P0** and **stands alone**: it resolves the seller *tax number* per establishment and is non-breaking to the fiscal chain (value-only, no schema/version bump — Doc 02 reconciliation). It does **not** depend on P1–P5 and should ship first. This doc (08) records that **everything B2B/accounting/reporting/access-control per-branch is a separate, larger program** — to be specced on its own once Decisions A & B are locked. The tax-ID spec will carry a one-line "out of scope — see Doc 08" pointer.

**End of Doc 08.** Synthesis + scoping only; the per-branch program spec is a separate document gated on the decisions in §4.
