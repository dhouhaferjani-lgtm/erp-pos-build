# Product Discovery Session — AutoERP

You are starting a product discovery session for AutoERP. Your goal is to create a comprehensive `docs/PRODUCT-BIBLE.md` that will serve as the single source of truth for all future development — every architectural decision, business rule, priority, and quality bar documented in one place.

This is a two-phase session: autonomous codebase exploration first, then a focused interview with the founder. Do not ask questions until you have exhausted what the code can tell you.

---

## PHASE 1: Codebase Exploration

Do this FIRST, before asking any questions. Write your findings incrementally to `docs/discovery-notes.md` as you go. This phase should take 30-60 minutes of thorough exploration.

### 1.1 Architecture Extraction

Read these files in order:
- `CLAUDE.md` (master architecture doc — 18 operational rules, tech stack, quality gates)
- `AGENTS.md` (monorepo structure, coding style, commit conventions)
- `.claude/context/architecture.md` (hexagonal layers, module structure, cross-module rules, CQRS, transaction boundaries)
- `.claude/context/compliance.md` (two-tier hash chain, NF525, ZATCA, event-first pattern)
- `.claude/context/i18n.md` (translation setup, RTL, key naming)
- `.claude/context/new-feature-checklist.md` (end-to-end steps for adding features)

Then read all convention docs:
- `docs/conventions/01-API-RESPONSES.md` through `docs/conventions/07-DEPENDENCY-INJECTION.md`

Document:
- Tech stack versions (Laravel 12, PHP 8.2+, PostgreSQL 16+, React 19, Vite 7, TypeScript strict, TanStack Query 5, Zustand 5, Tailwind CSS 4, Tauri 2, Redis 7+, Meilisearch, TimescaleDB)
- Hexagonal layer structure and enforcement
- Cross-module communication patterns (Shared/Contracts/ interfaces, Events, public Service classes)
- Multi-tenancy model (PostgreSQL schema-based)
- CQRS light pattern (Commands vs Queries vs materialized views)
- Transaction boundaries and pessimistic locking rules
- Event sourcing approach (event-first, state-second)
- What conventions are consistently enforced vs where the codebase deviates

### 1.2 Module Inventory

The backend modules live at `apps/api/app/Modules/`. There are approximately 35 modules. For each one:

```bash
# List all modules
ls apps/api/app/Modules/

# For each module, check its internal structure
ls -R apps/api/app/Modules/{ModuleName}/
```

The known modules are: Accounting, Admin, BatchExpiry, Billing, Cart, Catalog, Communication, Company, Compliance, Contact, Coupon, Dashboard, Document, Expense, Identity, Import, Inventory, Loyalty, Marketplace, Media, Menu, POS, Partner, PlatformIntegration, Pricing, Product, Progression, Promotion, PurchaseHub, Scheduling, Service, SmartPrompts, Taxation, Tenant, Treasury, Uom, Vehicle, Workshop.

For each module, assess:
- **Has Domain layer?** (Domain/Entities, Domain/Services, Domain/Events, Domain/ValueObjects)
- **Has Application layer?** (Application/Commands, Application/DTOs, Application/Services)
- **Has Infrastructure?** (Infrastructure/Repositories — Eloquent implementations)
- **Has Presentation?** (Controllers, Requests, Resources)
- **Has routes?** Check `apps/api/routes/` for module route files
- **Has tests?** Search `apps/api/tests/` for module-specific test files
- **Has frontend?** Check `apps/web/src/features/` — the known frontend features are: admin, auth, batches, catalog, categories, company, compliance, coupons, crm, dashboard, documents, enrichment, expenses, finance, import, inventory, inventory-counting, location, locations, loyalty, marketing, menu, opening-balances, parapharmacy, partners, parts-catalog, pos, pricing, products, progression, promotions, purchases, reports, scheduling, services, settings, treasury, uom, users, vat-reporting, vehicles, withholding, workshop-bundles, workshop-technicians, workshop-work-orders

Categorize each module as:
- **Complete**: All layers present, has tests, has frontend, routes wired
- **Functional**: Core logic works but missing some layers (e.g., no tests, partial frontend)
- **Partial**: Some code exists but significant gaps
- **Scaffolded-only**: Directory structure exists, little to no implementation
- **Missing**: Referenced in docs but no code exists

### 1.3 Business Flow Mapping

Trace end-to-end flows through the code. For each flow, follow the actual code path — don't guess from docs alone.

**Purchase flow:**
- Start at `PurchaseHub` module — check for PO creation, approval, receiving
- Trace: Purchase Order creation → Goods receipt → Inventory update → Supplier invoice → AP entry → Payment
- Check `Document` module for purchase document types (look at DocumentType enum in `apps/api/app/Modules/Document/`)
- Check if `Inventory` module has receiving/goods-receipt logic
- Check if `Accounting` creates AP journal entries on purchase invoice posting

**Sales flow:**
- Start at `Document` module — trace DocumentType enum values
- Follow: Quote → Sales Order → Delivery Note → Invoice → Payment → GL posting
- Check `POS` module for point-of-sale receipt flow (this is well-developed — receipts, shifts, Z-reports)
- Check `Treasury` module for payment recording and allocation
- Check `Accounting` for journal entry creation on invoice posting

**Inventory flow:**
- Explore `Inventory` module thoroughly — stock levels, movements, reservations, counting
- Check for valuation methods (FIFO, weighted average — referenced in roadmap as complete)
- Check `BatchExpiry` module for lot/batch tracking
- Look for stock adjustment, transfer between locations, cycle counting
- Check `Uom` (Unit of Measure) module for conversion logic

**Treasury flow:**
- Read `docs/modules/treasury.md` first
- Explore `Treasury` module — payment instruments, bank accounts, reconciliation
- Check for: cash management, check tracking, bank reconciliation, cash flow forecasting
- Look at payment method configuration and instrument custody

**Accounting flow:**
- Explore `Accounting` module — chart of accounts, journal entries, GL
- Check for: trial balance, financial statements, period closing, fiscal years
- Look at `Taxation` module — tax configuration, VAT reporting
- Check `Expense` module and its GL integration
- Look for `vat-reporting` in frontend features

**POS flow (well-developed — verify completeness):**
- Read `docs/modules/pos-terminal-flow.md`
- Check POS module: shifts, receipts, Z-reports, cash counting
- Check offline capabilities (Tauri desktop app, SQLite, ESC/POS printing)
- Verify hash chain compliance integration
- Check for: barcode scanning, multi-payment, returns, voids, discounts

**Workshop flow (automotive vertical):**
- Read `docs/modules/workshop-work-orders.md`
- Read `docs/otospex/ROADMAP.md` (detailed gap analysis already exists)
- Check Workshop module actual code vs documented gaps
- Check Vehicle module completeness
- Check for: work order lifecycle, technician assignment, labor tracking, core charges

For each flow, document: what steps exist in code, what is stubbed/partial, what is completely missing.

### 1.4 Data Model Analysis

```bash
# Count all migrations
ls apps/api/database/migrations/ | wc -l
# There are ~328 migrations

# Read migrations chronologically to understand schema evolution
# Focus on the most recent 30-40 migrations to see current direction
ls apps/api/database/migrations/ | tail -40

# Check for seeders
ls apps/api/database/seeders/

# Check for factories
ls apps/api/database/factories/
```

Read `docs/architecture/database.md` and `docs/architecture/database-schema.md` for documented schema.

Map key entity relationships:
- Company → Tenants → Users (multi-tenancy)
- Documents (unified table) → DocumentLines → Products/Services
- Partners (customers/suppliers) → Documents → Payments
- Products → Categories → Inventory (StockLevels, StockMovements)
- Vehicles → AutomotiveProductMetadata → CrossReferences → Fitment
- Accounting: ChartOfAccounts → JournalEntries → JournalEntryLines

Check for:
- Orphaned tables (referenced in migrations but no model)
- Missing relationships (FKs without corresponding Eloquent relations)
- Inconsistent naming (e.g., some tables use snake_case, some don't; some use UUID PKs, some use auto-increment)
- JSONB columns — find all and check if they have corresponding PHP DTOs (per CLAUDE.md rule 3)

### 1.5 Test Coverage Assessment

```bash
# Backend tests
find apps/api/tests -name "*.php" -type f | wc -l
# ~574 test files

# Check test organization
ls apps/api/tests/
# Directories: E2E, Feature, Fixtures, Integration, Unit, Traits

# Find modules with NO tests
# For each module, check if tests reference it
```

```bash
# Frontend tests
find apps/web/src -name "*.test.*" -type f | wc -l
# ~163 test files

# E2E tests (Playwright)
ls apps/web/e2e/
# Known specs: add-to-inventory, article-detail, auth, company, composite-item-delete, documents, part-number-search, partner-form, parts-catalog
```

Document:
- Which modules have thorough test coverage
- Which critical flows are tested end-to-end
- Which modules have zero tests
- Test patterns used (Pest-style, PHPUnit, Vitest, Playwright)
- Any test helpers, traits, or fixtures that indicate testing philosophy

### 1.6 Technical Debt Inventory

```bash
# Search for debt markers
grep -r "TODO" apps/api/app/ --include="*.php" -l | wc -l
grep -r "FIXME" apps/api/app/ --include="*.php" -l | wc -l
grep -r "HACK" apps/api/app/ --include="*.php" -l | wc -l
grep -r "@deprecated" apps/api/app/ --include="*.php" -l | wc -l
grep -r "temporary" apps/api/app/ --include="*.php" -l -i | wc -l

# Same for frontend
grep -r "TODO\|FIXME\|HACK\|@deprecated" apps/web/src/ --include="*.ts" --include="*.tsx" -l | wc -l

# Check for any type:any in TypeScript (violates CLAUDE.md rule 3)
grep -r ": any" apps/web/src/ --include="*.ts" --include="*.tsx" -l | wc -l

# Check for mixed types in PHP (violates CLAUDE.md rule 3)
grep -r ": mixed" apps/api/app/ --include="*.php" -l | wc -l
```

Also check:
- Inconsistent patterns across modules (e.g., some modules use hexagonal structure properly, some don't)
- Dead code or unused imports
- Models that live at `Domain/` root vs `Domain/Entities/` (the architecture doc notes both patterns exist)
- Any `app()` helper usage (violates CLAUDE.md rule 13 — constructor injection only)

### 1.7 Existing Documentation Audit

Read these documentation directories:
- `docs/architecture/` — 17 files covering backend, frontend, database, events, multi-tenancy, security, verticals, products, POS, etc.
- `docs/modules/` — treasury, imports, POS terminal flow, workshop work orders
- `docs/otospex/` — Otospex roadmap, scheduling and team plan
- `docs/country-readiness/` — multi-country compliance status
- `docs/planning/` — media architecture plan
- `docs/superpowers/specs/` — ~17 design specs (signup flow, POS bugs, tax selector, discount permissions, payment tolerance, etc.)
- `docs/superpowers/plans/` — ~33 implementation plans
- `docs/adr/` — architecture decision records (at least: TypeScript types pipeline)
- `docs/bugs/` — known bugs
- `docs/follow-ups/` — deferred items
- `docs/operations/` — operational docs
- `docs/TAX_IMPLEMENTATION_ANALYSIS.md` and `docs/TAX_MODULE_VERIFICATION_REPORT.md`

Document:
- What decisions are well-documented vs assumed
- What areas have specs but no implementation (or vice versa)
- Any contradictions between docs and actual code
- The maturity of the documentation (some docs say "Generated: November 2025" — is the code ahead of the docs?)

### 1.8 Vertical Analysis

Read `docs/architecture/verticals.md` for the full vertical map. The system supports 12 verticals across two products:

**IziPOS** (6 verticals): Pharmacy, Restaurant, Coffee Shop, Retail, Fashion, Parapharmacy
**Otospex** (6 verticals): Mechanic, Body Shop, Parts Retailer, Car Glass, Tire Shop, Service Station

For each product/vertical:
- What modules are needed for end-to-end operation?
- What exists in code?
- What's missing?
- What compliance requirements apply? (NF525 for France, ZATCA for Saudi, Tunisian fiscal rules)
- Cross-reference `docs/otospex/ROADMAP.md` which already has a detailed gap analysis for the automotive vertical

### 1.9 Monorepo & DevOps Assessment

Check:
- `pnpm-workspace.yaml` — workspace configuration
- `package.json` — root scripts
- `docker-compose.yml` and variants (`.override.yml`, `.staging.yml`, `.dokploy.yml`)
- `scripts/preflight.sh` — the pre-commit quality gate
- `scripts/setup.sh` — development setup
- `sonar-project.properties` — SonarQube integration
- `DEPLOYMENT.md` — deployment procedures
- `apps/pos/` — what is this third app? (alongside api and web)
- `packages/` — shared packages structure

---

## PHASE 2: Present Findings

Once Phase 1 is complete, present a structured summary to the founder. Format it as:

> "Here is what I learned from the codebase. Please correct anything wrong and fill in what I could not determine from the code alone."

Organize your findings as:

1. **Architecture decisions I found** — list each decision with your understanding. Ask the founder to confirm or correct.
2. **Module status map** — a table showing every module, its completeness score, and what layers exist. Ask the founder to add context you are missing.
3. **Business flows: what exists vs gaps** — for each flow, show what steps work and what is missing. Ask the founder to prioritize the gaps.
4. **Technical debt: what is intentional vs needs fixing** — present the debt you found and ask which items are known tradeoffs vs bugs.
5. **Compromises I noticed** — patterns that deviate from the stated architecture (e.g., models at Domain root vs Entities, `app()` usage, `any` types). Ask if these should be remediated or accepted.

Do NOT ask questions the codebase already answered. Be specific — reference file paths, module names, line counts.

---

## PHASE 3: Strategic Interview

After the founder confirms or corrects Phase 2, ask these categories of questions. Skip any question the codebase already answered. Reference what you found when asking.

### 3.1 Vision and Market

- The system targets automotive (Otospex) and retail/pharma (IziPOS) as two separate products. Which one ships first, and to whom specifically?
- What is the revenue model? The codebase has a `Billing` module — is this SaaS subscription, per-installation license, hybrid?
- The Otospex roadmap mentions Tunisia as the first market. Is that still accurate? What is the timeline?
- What differentiates this from Odoo, ERPNext, SAP Business One? What is the one thing competitors cannot do that AutoERP can?
- Where does this system need to be in 12 months for you to consider it successful? Be specific — number of installations, revenue, feature completeness.

### 3.2 Vertical Priorities

- You have 12 verticals defined in code. Realistically, which 2-3 ship in 2026?
- For the first vertical: what is the minimum viable feature set to close a sale? Not what is ideal — what is the absolute minimum a customer would pay for?
- Are there existing customers or pilots waiting? What are they asking for that does not exist yet?
- The parapharmacy vertical has a dedicated frontend feature directory. Is this the most advanced retail vertical?

### 3.3 Business Rules the Code Cannot Tell Me

- **Pricing**: The `Pricing` module supports multi-tier price lists and partner categories. But what are the actual pricing scenarios? B2B volume discounts? Contract pricing? Promotional rules beyond what the `Promotion` and `Coupon` modules handle?
- **Multi-company / multi-branch**: The multi-tenancy is schema-based (one schema per tenant). But how do multi-branch businesses work? Is one branch = one tenant? Or does a company have multiple locations within one tenant?
- **Currency**: What currencies need to be supported? What exchange rate sources? The codebase has monetary precision work (see `docs/superpowers/specs/2026-03-24-monetary-precision-frontend-db-design.md`) — is multi-currency a launch requirement or post-launch?
- **Offline behavior**: The POS has offline-first Tauri support with SQLite. But what about the web ERP — does it need offline capability? What happens during internet outages for non-POS operations?
- **Integrations**: The `PlatformIntegration` module exists, and there is a product enrichment feature. What external systems need to integrate at launch? TecDoc? Accounting software? Payment gateways? E-invoicing platforms?

### 3.4 Quality and Release

- What does "shippable" mean to you? The codebase has PHPStan level 8, strict TypeScript, Pint formatting, and a preflight script. But what about: minimum test coverage %, performance targets (page load, API response time), uptime SLA?
- Release cadence: The system has a Dokploy deployment setup. Is this continuous deployment to staging, then manual promotion to production? Or versioned releases?
- Who does QA beyond automated tests? Is there a manual testing process before releases?
- The `Compliance` module and hash chains are built for NF525 certification. When does actual certification need to happen? Is it blocking launch?

### 3.5 Decisions Already Made

- What approaches have you tried and rejected? (So I do not suggest them)
- The codebase uses Spatie packages heavily (permissions, data, media library). Any Spatie packages you have tried and removed?
- The `SmartPrompts` module exists — what is the AI strategy for the ERP? Where does AI add value vs where should it stay out?
- Strong opinions about: API design patterns, state management approach (Zustand 5 is used), form handling (react-hook-form + zod)?

### 3.6 Team and Process

- Who else works on this codebase? What are their strengths and where do they need support?
- The AGENTS.md mentions Claude Code and Codex. What has worked well with AI-assisted development? What has not?
- The commit convention is `Phase X.Y.Z: description`. What phase is the project currently in?
- What is the merge and deployment discipline? Feature branches? Direct to main? Review process?

### 3.7 Commercial and Operational

- The `docs/otospex/SCHEDULING-AND-TEAM.md` exists — what is the current team capacity?
- Are there any contractual deadlines or demo dates driving priorities?
- What does customer support look like at launch? Is the system self-service or does it need onboarding/training features?
- Localization: The system supports i18n with react-i18next. What languages are needed at launch? The compliance docs mention France, Tunisia, UK, Italy, North Africa — does each market need its own language?

---

## PHASE 4: Generate PRODUCT-BIBLE.md

After the interview, compile everything into `docs/PRODUCT-BIBLE.md`. Use the template at `docs/PRODUCT-BIBLE-TEMPLATE.md` as the structural guide.

The Product Bible must be:
- **Authoritative**: If it is not in the Bible, it is not decided.
- **Actionable**: Every section should help a developer or agent make decisions without asking the founder.
- **Current**: Include timestamps. Mark anything uncertain as "UNCONFIRMED — ask founder."
- **Connected**: Reference specific files, modules, and code paths. Not abstract — grounded in the actual codebase.

When writing the Bible, follow these rules:
- State facts, not aspirations. If a module is 30% done, say so.
- Distinguish between "decided and implemented," "decided but not implemented," and "not yet decided."
- For every business rule, cite where in the code it is enforced (or note that it is not enforced anywhere yet).
- For every gap, suggest whether it is a launch blocker or post-launch improvement (but let the founder override).

The Bible becomes the operating manual for the ERP orchestrator agent and all domain agents working on this codebase.
