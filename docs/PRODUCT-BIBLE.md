# Synerivia ERP — Product Bible

> **Last updated:** 2026-07-04 (re-verified against `dev` @ `8cb507faa`; original discovery 2026-06-30)
> **Session:** Product Discovery with Houssam (founder)
> **Status:** DRAFT (vision + architecture + launch boundary locked; some §6–§10 cells pending — see Open Questions)

> **Re-verification note (2026-07-04):** §3 module map, §4 flows, and §8 tech-debt re-checked against the actual code on `dev` @ `8cb507faa` (a ~5-day shipping window since the 2026-06-29 pass). Substantive deltas: the **Income** module (created 2026-07-03) is now folded in everywhere; module count corrected **44 → 45** (43 top-level dirs, not 42; Workshop = 3); unified-imports phases 0-2, procurement RFQ Waves 1-2, treasury C1-C7, enrichment H-A/H-B/H-C, owner-dashboard live sales, and cross-ERP brand mapping verified shipped; four newly-surfaced debt/bug items added to §8 (TD-011..TD-014). Per the laptop constraint, no test suites were executed — items requiring a red/green run to confirm are flagged **unverified (not test-run)** with a dated note.

This document is the single source of truth for **Synerivia ERP**. If it is not in this document, it is not decided. Every architectural choice, business rule, priority, and quality bar is documented here. It is the operating manual for the orchestrator agent and all domain agents.

**Naming note:** the working codename "AutoERP" is **retired** — it stemmed from a spare-parts origin and misrepresents a generic, whole-chain product. The canonical name is **Synerivia ERP** (the suite). **Otospex** (automotive) and **IziPOS** (retail/parapharmacy) are its vertical **editions**. A codebase-wide rename of the `AutoERP` / `syneriva` strings is a **separate deferred task** (like the existing deferred `syneriva → Synerivia` rename); this Bible and all new docs use **Synerivia ERP**.

---

## Table of Contents

1. [Product Vision and Market](#1-product-vision-and-market)
2. [Architecture](#2-architecture)
3. [Module Status Map](#3-module-status-map)
4. [Business Flows](#4-business-flows)
5. [Vertical Roadmap](#5-vertical-roadmap)
6. [Business Rules Reference](#6-business-rules-reference)
7. [Quality Standards and Release Process](#7-quality-standards-and-release-process)
8. [Technical Debt Register](#8-technical-debt-register)
9. [Decisions Log](#9-decisions-log)
10. [Open Questions](#10-open-questions)

---

## 1. Product Vision and Market

### 1.1 What Synerivia ERP Is

Synerivia ERP is a **whole-chain vertical ERP**: one system designed to serve *every tier of a vertical's supply chain* — for parapharmacy that is labs/manufacturers → importers → wholesalers → retailers → end customer → resale — so participants **interconnect** instead of each running a disconnected generic ERP. On top of the operational ERP sit three layers that are the real moat:

1. **End-customer growth products** that pull demand back through the chain — e.g. **Skin IQ** (loyalty/customer-success for parapharmacy) which benefits retailers → wholesalers → labs.
2. **A marketing & growth layer** — *this is where the business makes its money* — including the **Growth Coach**, an AI agent that guides even beginners on which modules to activate and what to focus on at each stage of their business lifecycle.
3. **An agent-native interaction surface** — a stable, well-documented **CLI + MCP** so a business can be run from WhatsApp/any messenger (photo → invoice, command-driven ops, push notifications) and, future-proof, be driven/monitored by Claude Code or any agent.

Underneath sit the table stakes that let underserved markets adopt without compromise: **certification-proof fiscal compliance, offline-first resilience** (never blocks, reconciles — built for unstable-internet African/MENA markets), **exact vertical fit** without generic-ERP customization cost, and **affordability**. **Scenario data enrichment** removes the tedious data-entry / verification / product-combination-discovery work. Geographic arc: **North Africa → MENA → wider Africa.**

### 1.2 Target Customer

| Attribute | Value |
|-----------|-------|
| Industry (first) | **Parapharmacy** (retail), Tunisia |
| Industry (second, customer waiting) | **Automotive — car repair shop**, France (needs e-facture) |
| Company size | SMB; single-tenant operators with one or more branches |
| Geography | Tunisia first; France (waiting auto client); then MENA / Africa |
| Current tools | Generic ERPs (Odoo / SAP B1) requiring expensive integrators, or disconnected POS + spreadsheets |
| Pain we solve | Fiscally-compliant + vertical-fit + offline-resilient + affordable, with no 6-month customization project |
| Budget range | SMB-affordable SaaS + paid upgrade modules (exact pricing: Open Question) |

### 1.3 Revenue Model

**SaaS subscription with paid upgrade modules.** Each vertical ships a **default module set**; additional capabilities are **paid upgrade extras**. This maps directly onto the existing vertical-module-gating system — `apps/api/config/verticals.php` (`default_modules` + `compatible_extras`) is the source of truth. The marketing/growth layer (Growth Coach, Skin IQ) is positioned as the higher-margin upsell. Exact pricing tiers: **Open Question (OQ-001).**

### 1.4 Competitive Position

The one-line moat: **a whole-chain, vertical-fit ERP that is fiscally compliant and offline-resilient out of the box for underserved markets — no integrator, no compromise — with a growth/marketing layer and an agent-native control surface on top.**

| Competitor | Their strength | Our advantage |
|------------|---------------|---------------|
| Odoo / ERPNext | Broad, modular, open | Fiscally compliant + vertical-fit on day one; no integrator project; offline-first POS for unstable-internet markets |
| SAP Business One | Enterprise depth | Affordable for SMBs; vertical-native; whole-chain interconnection via the Synerivia platform |
| Vertical POS point-tools | Narrow vertical fit | Full ERP + chain + growth layer, not just a till |

### 1.5 Success Criteria (12-Month)

| Metric | Target | Current |
|--------|--------|---------|
| Live parapharmacy installations (Tunisia) | ~10–20 paying tenants | 0 (pre-launch) |
| Recurring revenue | SaaS MRR > 0, ≥1 paid upgrade-module attached | 0 |
| **Whole chain — parapharmacy** | Full chain (labs → … → retailer) operational | retailer node only |
| **Whole chain — automotive** | Full chain operational | retailer/workshop node partial |
| First France client (car repair shop) | Live on Otospex w/ **e-facture (France)** | waiting |
| Certifications | **NACEF (Tunisia)**, **e-facture (Tunisia + France)**; NF525 (France) *in progress* | Tunisia-correct behavior built |
| Growth/moat layer | ≥1 of Skin IQ / Growth Coach in pilot | not started |

> Launch gates on **Tunisia-correct fiscal behavior**, not on a certificate. NACEF and e-facture are near-term certification milestones, not launch blockers.

---

## 2. Architecture

### 2.1 Tech Stack

| Layer | Technology | Version | Notes |
|-------|-----------|---------|-------|
| Backend | Laravel | 12 | PHP 8.2+, strict types |
| Database | PostgreSQL | 16+ | **Database-per-tenant** (see 2.4 — NOT schema-based) |
| Cache/Queue | Redis | 7+ | Laravel Horizon |
| Search | Meilisearch | | Infra ready, not yet integrated with Scout |
| Desktop POS | Tauri | 2 | IziPOS / Otospex, offline-first, SQLite |
| Frontend | React | 19 | Vite 7, TypeScript strict |
| State | TanStack Query 5 / Zustand 5 | | server / client |
| Styling | Tailwind CSS | 4 | design tokens; copper (IziPOS) / pink (Otospex) themes |
| Mobile | React Native + Expo | | |
| Time-series | TimescaleDB | | audit log (fraud-detection tier) |
| **Future surface** | **CLI + MCP** | planned | agent-native control (WhatsApp/messenger, agent-drivable) — post-launch |

### 2.2 Monorepo Structure

```
synerivia/ (repo path still "syneriva")
├── apps/
│   ├── erp/          — Synerivia ERP (this product)
│   │   ├── apps/api/ — Laravel backend (hexagonal modules)
│   │   ├── apps/web/ — React frontend (features)
│   │   └── apps/pos/ — Tauri offline-first POS (SQLite, ESC/POS)
│   ├── platform/     — Synerivia platform: the B2B chain-linking data mesh
│   ├── platform-ml/  — Platform ML (FastAPI)
│   └── erp-ml/       — ERP ML (FastAPI)
└── packages/shared/  — types-only (generated TS from PHP DTOs)
```

The **whole-chain interconnection is the `apps/platform` mesh's job**, not the ERP node's. The ERP is the node each business runs; the platform connects nodes across tiers. (Cross-tier interconnection = post-launch.)

### 2.3 Architectural Patterns

**Hexagonal (Domain / Application / Infrastructure / Presentation)** per module. Enforcement: mostly consistent; deviations tracked (service-provider/route placement at module root in several modules — see §8 and discovery-tech-debt.md). **Architecture enforcement now exists**: `apps/api/deptrac.yaml`.

**Cross-module communication** via `app/Shared/Contracts/` interfaces, Events, or a module's public Service class. Direct cross-module `Domain/` imports are a known debt (see §8).

**CQRS-light:** commands mutate through the domain layer; queries read optimized read models; journal entries serve as the financial read model.

**Event-first / device-authored fiscal:** fiscal events are authored **on the device** and ingested server-side (the `Fiscal` module — projection/quarantine engine, post-2026-06-12). Two-tier hash chain: **fiscal chain** (SHA-256, compliance) + **audit log** (TimescaleDB, fraud detection). Events are immutable forever (versioned replacements only).

**Unified `documents` table** for all document types; discriminator column is `type` (`Document\Domain\Enums\DocumentType`), **not** `document_type`.

### 2.4 Multi-Tenancy (CORRECTED)

**Database-per-tenant** via Stancl `PostgreSQLDatabaseManager` (since 2026-05-28). One central DB **`synerivia_central`** holds the tenant directory + auth (tenants/domains/plans/subscriptions/super_admins/central_identities/PATs); **one `tenant_<uuid>` DB per tenant** holds every tenant-scoped table. The default Laravel connection is swapped per-request to the tenant DB by `DatabaseTenancyBootstrapper`. **This supersedes the template/older-doc claim of "schema-based" multi-tenancy.**

**Tenant → Company → Branch hierarchy:** a **Tenant** can hold **multiple Companies**; a **Company** has **Locations/Branches**, each with its **own affiliated tax number**. This supports franchises mixing self-owned and franchisee-owned branches under one tenancy. Stock, POS terminals, and fiscal sequences scope by location. (Per-branch tax-ID lives in the signed fiscal bytes — see related branch-tax-id work.)

### 2.5 Design System

Tailwind v4 design tokens; two themes — **IziPOS copper**, **Otospex pink** — separate CSS variable sets, configs, vertical-scoped products. Never assume one edition's config applies to the other. `localStorage`/auth shared on localhost.

---

## 3. Module Status Map

> **Source of truth for per-module detail:** `docs/autonomous-product-dev-setup/discovery-module-inventory.md` (re-verified 2026-07-04 against `dev` @ `8cb507faa`; prior pass 2026-06-29 @ `96f421c56`). To avoid drift, this Bible records the **summary + deltas** and points there rather than duplicating all 45 rows.

**Current totals (verified 2026-07-04, `dev` @ `8cb507faa`):** **45 modules** (43 top-level dirs; Workshop = 3 sub-modules). Backend test files **1190** (`find apps/api/tests -name '*Test.php' | wc -l`). Migrations **456** (all `central`/`tenant`/`manual` subdirs). Frontend feature dirs **52** (`apps/web/src/features/`).

**Five modules added since the original 2026-06-15 audit** (Income is new since the 2026-06-29 pass):

| Module | Status | What it does | Path |
|--------|--------|--------------|------|
| **Income** | Partial | Business-income recording — the mirror of Expense. Records money received into cash/bank repositories against class-7 revenue accounts; posts a GL entry (Dr cash/bank, Cr class-7) and increases the receiving repository balance via the `RepositoryInflowInterface` port. Reuses the unified `documents` table (`DocumentType::Income`, `IncomeMetadata` sidecar). 6 permission-gated routes (`income.view/create/update/delete/post`), 2 feature tests. Shipped 2026-07-03 as part of treasury C7. | `apps/api/app/Modules/Income/` |
| **Procurement** | Partial | Phase-1 procure-to-pay AP: GR-first 3-way match + GR-IR clearing posting, **plus multi-supplier RFQ groups** (RFQ Waves 1-2 — fan-out create, response recording, ordered-lock award + reopen, RFQ→PO converter). Does NOT own GoodsReceipt (Inventory) or a supplier-invoice model (Documents). | `apps/api/app/Modules/Procurement/` |
| **Fiscal** | Complete | Device-authored fiscal event engine (projection/quarantine), 70 tests. NF525 canonical projection. | `apps/api/app/Modules/Fiscal/` |
| **Voucher** | Complete | Issuance/redemption/cascade/lookup, append-only ledger, fraud alerts, POS sync. | `apps/api/app/Modules/Voucher/` |
| **Channel** | Complete | Sales-channel / marketplace sync: webhook ingestion, dispatch jobs, drift detection. | `apps/api/app/Modules/Channel/` |

**Notable status corrections:** Income **new → Partial** (mirrors Expense; GL + repository-inflow wired, 2 feature tests). Expense **Scaffolded → Partial** (now 8 tests). Inventory/Treasury/Document/Product/BatchExpiry counts drifted up. The original "modules with zero tests" list is stale (Billing 3, Communication 1, Dashboard 1, Expense 8, Media 26 — none zero; treat Billing/Communication/Dashboard as *thin*).

**Frontend features:** ~52 feature dirs under `apps/web/src/features/` map ~1:1 to backend modules (incl. `income/`, `treasury/`, `finance/`, `enrichment/`, `purchases/quote-requests/`, `purchases/supplier-invoices/`). Parapharmacy is the most advanced retail-vertical feature set (merchandising, skin-type capture, Caisse redesign in progress).

---

## 4. Business Flows

> **Source of truth for per-step detail:** `docs/autonomous-product-dev-setup/discovery-flow-analysis.md` (verified 2026-06-29; flow deltas below re-checked 2026-07-04 @ `8cb507faa`). Summary + the gaps that matter for launch below.

| Flow | Completeness | Key remaining gap | Launch impact |
|------|-------------|-------------------|---------------|
| **Sales** | ~95% | minor (no auto-notifications). **Owner dashboard shipped**: hour-granularity sales report + live-sales endpoint `GET /reports/sales/live` (`Accounting/.../ReportsController@liveSales`, `GetOwnerSalesReportRequest` granularity `hour\|day\|week\|month`). | none |
| **Purchase / Procurement** | ~92% | no PO→supplier-invoice *registry converter* (covered via `source_line_id` + 3-way matcher); supplier payment via generic path. **RFQ Waves 1-2 shipped** — multi-supplier RFQ groups (fan-out create, response recording, ordered-lock award + reopen with fail-closed gating, RFQ→PO converter; FE list/create/detail/comparison at `apps/web/src/features/purchases/quote-requests/`). ⚠️ **Supplier invoices are API-only** — no web-UI create page (see TD-014). | none |
| **POS** | ~90% | **POS-sale COGS not posted to GL** (invoice COGS *is* posted via `PostCOGSOnInvoice` at `Inventory/Listeners/`; POS bypasses the invoice flow) | **Priority backlog** (TD-001) |
| **Inventory** | ~88% | batch write-off GL automated; **general adjustment/transfer still GL-blind**; no dedicated `WriteOff` movement type | medium |
| **Accounting** | ~92% | **hierarchy balance calc broken** (3 TODOs, `ReportsController.php:326/481/632` as of `8cb507faa` — line numbers drifted from the prior `275/430/581`) | **Priority backlog** (TD-003) |
| **Treasury** | ~85% | **C1-C7 shipped**: seeded books, report pages, upcoming payments (`Accounting/.../Reports/UpcomingPaymentsService` + `finance/hooks/useUpcomingPayments`), bank-rec fixes, trésorerie overview (`finance/pages/TreasuryOverviewPage`), and the **repository inflow/outflow money-movement ports** (`Treasury/.../RepositoryInflowService`/`RepositoryOutflowService`, consumed by Income + Expense). **Still open:** cash-flow report, instrument-lifecycle GL, bank-statement auto-match (rest of "money-movement spine") | **Priority backlog** (TD-002, narrowed) |
| **Income** | ~85% (new) | Income recording BE + FE shipped (mirror of Expense; GL Dr cash/bank / Cr class-7 + repository inflow). Known FE defect: `/income` list reportedly stuck on "Chargement…" (TD-013). | low (para launch: nice-to-have) |
| **Workshop (automotive)** | not re-traced | — | relevant to France auto client / vertical #2 |

**Top-3 prioritized gaps (founder-selected):** POS COGS posting, Treasury money-spine, Accounting hierarchy-balance bug. These are financial-correctness gaps prioritized *above* parapharmacy launch polish — the accounting backbone must be airtight even while parapharmacy is the go-to-market. (Treasury C1-C7 has since delivered the report/overview/upcoming-payments layer and the inflow/outflow ports; the remaining spine work — cash-flow report, instrument GL, bank auto-match — is what keeps TD-002 open.)

---

## 5. Vertical Roadmap

### 5.1 Priority Order

| Priority | Vertical | Product/Edition | Scope | Status |
|----------|----------|-----------------|-------|--------|
| 1 | **Parapharmacy** | IziPOS | **Launch = single-tier retailer node** (POS + inventory/FEFO + procure-to-pay + financial backbone + merchandising + loyalty) | imminent demo; financial-correctness gaps in flight |
| 2 | **Automotive (car repair)** | Otospex | **France client waiting**; needs **e-facture (France)**; Workshop + Vehicle modules substantial | next; pulled by real customer |
| 3 | **Restaurant / Coffee Shop** | IziPOS | F&B: Menu, composite items/recipes, table mgmt (composite work currently deferred) | later in 2026 |

**12-month chain target:** full supply chain for **both parapharmacy and automotive** (not just the retailer node). Chain interconnection is **platform-mediated** (`apps/platform`).

### 5.2 Vertical Feature Matrix

Source of truth: `apps/api/config/verticals.php` (`default_modules` + `compatible_extras` per vertical). The template's static matrix is indicative only — defer to the config file.

### 5.3 Post-Launch Moat Layers (committed direction, not launch blockers)

- **Skin IQ** — end-customer loyalty/customer-success app (parapharmacy), pulls demand back through the chain.
- **Growth Coach** — AI lifecycle advisor (module-activation + focus guidance). Likely evolves from the existing `Progression` (onboarding advisor) module.
- **Scenario data enrichment** — automated data entry / verification / product-combination discovery (relates to enrichment + `SmartPrompts`). **Pipeline foundations shipped (enrichment H-A/H-B/H-C):** FOUND-backlink + queued auto-accept, photo-first capture panel (server-normalized barcode, holder pre-check, upload-url proxy, fast-path polling), and non-regressing result versioning + fire-and-forget feedback callback. Also shipped: **cross-ERP brand mapping** — `canonical_brand_id` persistence + mapped-brand reuse + damped push-back (`Product/.../BrandResolutionService`, `SendBrandMappingJob`, brands `canonical_brand_id` unique index migration 2026-07-03). Webhook-driven enrichment remains blocked (webhook/poller is tenant-blind under db-per-tenant — see enrichment notes).
- **CLI + MCP control surface** — run the business from WhatsApp/messenger; agent-drivable.

---

## 6. Business Rules Reference

### 6.1 Inventory Rules

| Rule | Value | Source |
|------|-------|--------|
| Valuation method | **Weighted-Average Cost (WAC)** | `Inventory/.../WeightedAverageCostService` |
| **Negative stock / overselling** | **HARD BLOCK everywhere — no override, no negative sales** | **ADR-0002** (`docs/adr/2026-06-30-negative-stock-hard-block.md`) |
| Back-valuation / recosting engine | **Not built** (unnecessary while the block holds) | ADR-0002 |
| Backorder (sell against incoming PO) | Possible future feature, NOT an override; not designed | ADR-0002 |
| Batch/lot/expiry | Native; **FEFO** (First-Expired-First-Out); hard checkout block on missing/expired lot | `BatchExpiry/` |

### 6.2 Document Lifecycle Rules

Unified `documents` table; `type` ∈ `DocumentType` (Quote, SalesOrder, DeliveryNote, Invoice, CreditNote, PurchaseOrder, **PurchaseRfq** (procurement RFQ groups), GoodsReceipt, **SupplierInvoice**, **SupplierCreditNote**, **Income** (money-received records, `IncomeMetadata` sidecar), …). Lifecycle: **Draft → Confirmed → Posted → Paid / Voided** (fiscal_status `Voided` on cancel; posting adds to the fiscal hash chain). Precise transitions: `Document/Domain/Services/DocumentPostingService` + the conversion registry. Stock issues on **delivery-note confirmation**; posting validates delivery compliance for physical products.

### 6.3 Pricing Rules

Required at launch and **mostly implemented**: **B2C tax-inclusive (TTC) POS** (canonical SALE_RECEIPT `unit_price` is the inclusive cart price; net = `line_subtotal`); **B2B price lists / partner pricing**; **promotions & loyalty** (loyalty earn-on-purchase shipped); **margin-driven auto-pricing** (sale price from WAC cost + target margin; margin-override hierarchy on an unmerged branch). The **sell-ready products import** (unified imports Phase 2) applies the same authority resolution — a per-row `TTC | HT | margin` authority resolves the canonical `sale_price` (bcmath, conflict warnings) via `ProductPriceResolver` (`apps/api/app/Modules/Import/Services/ProductPriceResolver.php`). `unit_price` is context-overloaded (TTC in B2C POS, net/HT in B2B) — enforce fiscal integrity at the aggregate level. See `docs/architecture/precision-contract.md`.

### 6.4 Multi-Tenancy & Multi-Branch Rules

Database-per-tenant (§2.4). **Tenant → multiple Companies → Branches/Locations each with their own tax number.** Franchises (self-owned + franchisee-owned branches) live under one tenancy. Fiscal sequences and per-branch tax-ID scope by location.

### 6.5 Currency & Localization Rules

**Launch = Tunisia, TND single-currency**, 19% VAT, Tunisian fiscal rules. Multi-currency (EUR for France, etc.) is **post-launch** — but the France auto client (vertical #2) brings **EUR + French e-facture** into the 12-month window. Money at rest `decimal(N,3)`, quantity `decimal(N,4)`; never float on money/qty (precision contract).

### 6.6 Compliance Rules

- **Launch gate:** Tunisia-correct fiscal behavior (hash-chained device-authored fiscal events, immutable receipts, audit trail).
- **12-month certs:** **NACEF (Tunisia)**, **e-facture (Tunisia + France)**.
- **NF525 (France):** pursued at France-entry; **not a launch blocker**.
- Two-tier hash chain (fiscal + TimescaleDB audit); receipt immutability; Z-report obligations; e-invoicing (Factur-X / e-facture) for France.

### 6.7 Offline Behavior Rules

POS is **offline-first** (Tauri + SQLite). The device does **read-time availability** and does **not** author the authoritative stock decrement — the **server projection is the single source of truth**, so the negative-stock block is enforced server-side and offline oversell is naturally bounded and reconciled on sync. The web ERP is online. SQLite TEXT timestamps vs ISO 8601 and device-authored shift fields are known cross-layer contracts (CLAUDE.md rule 20).

---

## 7. Quality Standards and Release Process

### 7.1 Definition of Shippable

**Bar: 100% test coverage everywhere. TDD is a strict, non-negotiable requirement.**

- **TDD is mandatory** — write the failing test first (red), minimum code to pass (green), refactor. No implementation code lands without a test written first. This is a hard process rule, not a guideline.
- **Coverage target = 100%** (line + branch), backend and frontend. Because TDD is enforced, **new code is 100%-covered by construction**; **existing/legacy code is raised to 100% as it is touched** (so the bar drives coverage up continuously without freezing work on currently-undertested modules).

| Criterion | Target | Blocking? |
|-----------|--------|-----------|
| **TDD** | test-first, red→green→refactor, always | Yes (process rule) |
| **Coverage** (backend & frontend, line + branch) | **100%** (new code by construction; legacy as touched) | Yes |
| PHPStan level | 8, zero errors on new code | Yes |
| TypeScript strict | Yes | Yes |
| Critical-path E2E (money / fiscal / POS / inventory) | present + passing | Yes |
| Architecture (deptrac) | no new cross-boundary violations | Yes |
| Performance budgets | API p95 + POS interaction budgets | Yes (targets: OQ-002) |
| Fiscal behavior | Tunisia-correct, hash-chain verification passes | Yes |

### 7.2 Quality Gates

`scripts/preflight.sh` = PHPStan + Pint + PHPUnit + TypeScript check + ESLint. Plus: **deptrac** (architecture), **Playwright visual gate** (`scripts/visual-test-gate.sh`, wired as pre-commit in Phase 4 of setup), types-drift guard, fiscal hash-chain verification, multi-tenant isolation, offline-POS network-disconnect test.

> ⚠️ Never run the full PHPUnit suite without permission (crashes the laptop) — run by path.

### 7.3 Release Process & Autonomy Model

Local → **Dokploy** staging → production. **Branch discipline (CLAUDE.md rule 21):** work in a `git worktree` off `dev`; merge to **local** `dev` first; promote to `origin/dev` as clean **fast-forwards**; never force-push shared `dev` (`dev-push-guard` hook). Exact staging/prod URLs + approval gates: OQ-003.

**Autonomy tiers (BD-005, graduated):**
- **Autonomous work → local `dev`:** domain agents run TDD + code review + all gates (preflight, deptrac, Playwright, fiscal-chain verify). When everything is green, work is *ready* for merge.
- **Merges are performed by a dedicated, Bible- and architecture-aware merge agent/team** — not ad hoc by feature agents — and **only happen when the founder is around** (never while away). Promotion to `origin/dev` is batched and founder-aware.
- **Phase A (current): founder is notified for every merge** and approves/triggers it. No merge proceeds unprompted.
- **Phase B (later, once the approach proves itself): more freedom per task type** — low-risk categories (UI, docs, tests, non-fiscal refactors) may merge without per-merge notification, while the merge agent still runs all gates.
- **Always human sign-off for the certification-proof core:** anything touching **money / fiscal events / hash chain / GL postings**, **DB topology / schema** changes, **published API or contract** changes, and **production promotion**. "Very critical → human eyes always."

### 7.4 QA Process

Automated gates above + founder manual verification of critical flows (Playwright on web, computer-use on Tauri POS). Manual QA owner / cadence: OQ-004.

---

## 8. Technical Debt Register

> Verified detail: `docs/autonomous-product-dev-setup/discovery-tech-debt.md` (2026-06-29); register re-checked 2026-07-04 @ `8cb507faa`.

| ID | Item | Status | Priority |
|----|------|--------|----------|
| TD-001 | **POS-sale COGS not posted to GL** (invoice COGS posts via `Inventory/Listeners/PostCOGSOnInvoice`; POS bypasses it) | Open (verified still present) | **High (founder top-3)** |
| TD-002 | **Treasury money-spine**: cash-flow report, instrument-lifecycle GL, bank-statement auto-match. **Narrowed by C1-C7:** upcoming-payments report, trésorerie overview, and repository inflow/outflow ports now shipped; the three named items remain. | Open (narrowed) | **High (founder top-3)** |
| TD-003 | **Accounting hierarchy balance calc broken** (3 TODOs, `ReportsController.php:326/481/632`) | Open (verified; line #s drifted) | **High (founder top-3)** |
| TD-004 | General inventory adjustment/transfer GL-blind; no `WriteOff` movement type | Open | Medium |
| TD-005 | Hardcoded FE permissions map (`usePermissions.ts`) | Open | Medium |
| TD-006 | Category margin override commented out (lives on unmerged branch) | Open | Medium |
| TD-007 | `'XXX'` currency fallback (1 site, `ZReportSyncController.php:431`) | Open | Low |
| TD-008 | DailyExpiryCheck sysadmin alert TODO (company alerts done) | Open | Low |
| TD-009 | Residual `(float)` boundary casts (mostly fixed; PHPStan guard added) | Mostly resolved | Low |
| TD-010 | Cross-module `Domain/` imports / circular deps (deptrac now exists) | Open, guarded | Medium |
| TD-011 | **CustomerAdvance GL-line bug** surfaced by `tests/Feature/Partner/RecordCustomerDepositTest.php` (overflow deposit must post a customer-advance credit line via `SystemAccountPurpose::CustomerAdvance`) — reported 2026-07-04. **Unverified (not test-run)** per laptop constraint; test + `RecordCustomerDepositService` confirmed present. | Open | **High (money correctness)** |
| TD-012 | **`/reports/finance-summary` endpoint never existed on the backend** — FE (`web/src/features/finance/api.ts:217`) calls it, gets 404, so `FinanceWidget` shows zeros. Backend grep for the route is empty. | Open (verified) | Medium |
| TD-013 | **`/income` FE list reportedly stuck on "Chargement…"** — `IncomeListPage.tsx` present; stuck-loading behavior reported 2026-07-04 but **not runtime-verified** here. | Open (reported, not runtime-verified) | Medium |
| TD-014 | **Supplier invoices cannot be created via the web UI** — API-only; `useCreateSupplierInvoice` (`web/src/features/purchases/supplier-invoices/api.ts:143`) is defined but imported by no page (only its own tenant-scope test). FE has List + Detail pages, no Create page. | Open (verified) | Medium |
| TD-015 | **RoleController privesc (go-live audit) — RESOLVED 2026-07-04.** Re-verification showed store/update/assignRole/removeRole were already gated (`StoreRoleRequest`/`UpdateRoleRequest` → `roles.manage`; `AssignRoleRequest` → `users.assign-roles` + `AssignableRole` subset); the residual hole was the ungated DELETE `roles/{id}` — closed by `DeleteRoleRequest` (`roles.manage`), deny-path tested, tenancy-authz-reviewer APPROVED (negative control reproduced the cashier-delete on pre-fix code). Follow-ups (Minor, pre-existing): `RoleController::update` lacks a subset check on written permissions (latent only if `roles.manage` is ever granted below admin); read endpoints not gated on `roles.view`. | Resolved (2 minor follow-ups) | Closed (was launch blocker) |

### 8.3 Missing/Thin Test Coverage

Billing (3), Communication (1), Dashboard (1) are thin and financial/integration-adjacent — raise toward the 90% bar before those modules are launch-critical. Income is new with only 2 feature tests (store + post) — thin for a GL-posting module; raise before it is launch-critical.

---

## 9. Decisions Log

### 9.1 Architecture Decisions

| ID | Date | Decision | Rationale | Rejected |
|----|------|----------|-----------|----------|
| AD-001 | 2026-05-28 | **Database-per-tenant** (Stancl) | Strong isolation; per-tenant DB | schema-based, row-based |
| AD-002 | — | Hexagonal architecture | testability, module boundaries | MVC |
| AD-003 | — | Two-tier hash chain (fiscal + audit) | NF525/compliance + fraud detection | single chain, none |
| AD-004 | — | Unified `documents` table (`type` discriminator) | one lifecycle engine | table-per-type |
| AD-005 | — | Tauri 2 offline-first POS | resilient for unstable-internet markets | Electron, PWA-only |
| AD-006 | — | CQRS-light + device-authored fiscal events | device is fiscal SoT; GL is downstream projection | full ES, CRUD |
| AD-007 | 2026-06-30 | **Name = "Synerivia ERP"**; Otospex/IziPOS = editions; retire "AutoERP" | generic whole-chain product; unify under platform brand | keep AutoERP, new name |
| AD-008 | 2026-06-30 | **Negative stock hard-blocked, no override** | WAC/hash-chain integrity + simplicity (no back-valuation engine) | block-with-override, allow-on-POS — see ADR-0002 |

### 9.2 Business Decisions

| ID | Date | Decision |
|----|------|----------|
| BD-001 | 2026-06-30 | Revenue = **SaaS + paid upgrade modules** (verticals.php extras) |
| BD-002 | 2026-06-30 | **Launch = single-tier retailer node**; whole-chain + growth + CLI layers are post-launch (chain = 12-month target for para + auto) |
| BD-003 | 2026-06-30 | First market **Tunisia / TND**; France auto client (e-facture) is vertical #2 |
| BD-004 | 2026-06-30 | Quality bar: **100% coverage everywhere; TDD strict/mandatory** (new code 100% by construction, legacy raised as touched) |
| BD-005 | 2026-06-30 | **Autonomy model (graduated):** agents autonomous → local `dev` behind green gates. **Phase A (now):** founder is **notified for every merge** and approves/triggers it; a dedicated Bible-aware **merge agent/team** executes merges, **only when founder is around**. **Phase B (later):** as the approach proves itself, founder grants **more freedom per task type** (low-risk categories merge without per-merge notification). **Always human sign-off** for the certification-proof core: money/fiscal/hash-chain/GL, DB topology/schema, published API/contract, and prod promotion. |

### 9.3 Rejected Approaches

| Approach | Why rejected | Date |
|----------|--------------|------|
| Allowing negative stock / overselling | Breaks WAC + lot traceability; needs back-valuation engine | 2026-06-30 |
| "Schema-based" multi-tenancy (older docs) | Superseded by database-per-tenant | 2026-05-28 |

---

## 10. Open Questions

| ID | Question | Owner | Notes |
|----|----------|-------|-------|
| OQ-001 | Exact SaaS pricing tiers + which modules are base vs paid extras per vertical | Houssam | maps to verticals.php |
| OQ-002 | Concrete performance budgets (API p95 ms, POS interaction ms) | Houssam | enforced as hard gates |
| OQ-003 | Staging/prod URLs + promotion approval gates | Houssam | Dokploy |
| OQ-004 | Manual QA owner & cadence beyond automated gates | Houssam | |
| OQ-005 | Team capacity / who else works on the codebase | Houssam | |
| OQ-006 | Backorder feature scope (sell against incoming PO) | Houssam | future, post-block |
| OQ-007 | Chain-interconnection mechanics (how tiers link via platform) | Houssam | post-launch design |
| OQ-008 | Skin IQ / Growth Coach / CLI-MCP design specs | Houssam | post-launch direction |

---

## Appendices

### A. File Reference

| File | Purpose |
|------|---------|
| `CLAUDE.md` (apps/erp) | Master architecture + 21 operational rules |
| `docs/PRODUCT-BIBLE.md` | **This file** — single source of truth |
| `docs/adr/2026-06-30-negative-stock-hard-block.md` | ADR-0002 negative-stock policy |
| `docs/autonomous-product-dev-setup/discovery-*.md` | Verified module/flow/tech-debt detail |
| `apps/api/config/verticals.php` | Vertical → default modules + paid extras (SoT) |
| `docs/architecture/precision-contract.md` | Money/quantity precision |
| `docs/architecture/vertical-module-gating.md` | Two-edition / module-gating model |
| `scripts/preflight.sh`, `scripts/visual-test-gate.sh` | Quality gates |

### B. Glossary

| Term | Definition |
|------|-----------|
| **Synerivia ERP** | Canonical name of the product suite (formerly "AutoERP"). |
| **Synerivia (platform)** | The B2B chain-linking data mesh (`apps/platform`) connecting ERP nodes across supply-chain tiers. |
| **Edition** | A vertical-facing skin/config of Synerivia ERP: **Otospex** (automotive, pink), **IziPOS** (retail/parapharmacy, copper). |
| **Whole-chain** | The full vertical supply chain (lab → importer → wholesaler → retailer → customer → resale) the product aims to interconnect. |
| **Tenant / Company / Branch** | Tenant = one DB; can hold multiple Companies; a Company has Branches/Locations each with its own tax number. |
| **Skin IQ** | Planned end-customer loyalty/customer-success app for parapharmacy. |
| **Growth Coach** | Planned AI lifecycle advisor guiding module activation + focus. |
| **Scenario data enrichment** | Automated data-entry/verification/product-combination discovery. |
| **NACEF** | Tunisian fiscal certification (12-month target). |
| **e-facture** | E-invoicing certification (Tunisia + France). |
| **Backorder** | Future: selling against quantity backordered on an incoming PO (NOT a negative-stock override). |
| **Fiscal chain / Audit log** | SHA-256 fiscal hash chain (compliance) + TimescaleDB event log (fraud detection). |
| **Document** | Unified entity for quotes, orders, delivery/goods-receipt notes, invoices, credit notes, supplier invoices. |

### C. Stats (verified 2026-07-04, `dev` @ `8cb507faa`)

- Modules: **45** (43 top-level dirs, Workshop = 3)
- Backend test files: **1190**
- Migrations: **456**
- Frontend feature dirs: **52** (`apps/web/src/features/`)
- New modules since 2026-06-15: **Income** (2026-07-03), Channel, Fiscal, Procurement, Voucher

_Prior pass (2026-06-29 @ `96f421c56`): 44 modules / 1126 tests / 445 migrations._
