# AutoERP — Product Bible

> **Last updated:** [DATE]
> **Session:** Product Discovery with [FOUNDER NAME]
> **Status:** [DRAFT | REVIEWED | APPROVED]

This document is the single source of truth for AutoERP. If it is not in this document, it is not decided. Every architectural choice, business rule, priority, and quality bar is documented here.

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

### 1.1 What AutoERP Is

<!-- One paragraph. What does this system do, for whom, and why does it exist? -->

### 1.2 Target Customer

<!-- Be specific. Not "small businesses" — the exact profile of the first paying customer. -->

| Attribute | Value |
|-----------|-------|
| Industry | |
| Company size | |
| Geography | |
| Current tools they use | |
| Pain point we solve | |
| Budget range | |

### 1.3 Revenue Model

<!-- SaaS, license, per-installation, hybrid. Pricing tiers if known. -->

### 1.4 Competitive Position

<!-- What differentiates AutoERP from Odoo, ERPNext, SAP Business One, and vertical-specific competitors? -->

| Competitor | Their strength | Our advantage |
|------------|---------------|---------------|
| | | |

### 1.5 Success Criteria (12-Month)

<!-- Specific, measurable targets. Number of installations, revenue, feature completeness, certifications obtained. -->

| Metric | Target | Current |
|--------|--------|---------|
| | | |

---

## 2. Architecture

### 2.1 Tech Stack

| Layer | Technology | Version | Notes |
|-------|-----------|---------|-------|
| Backend | Laravel | 12 | |
| Language | PHP | 8.2+ | Strict types enforced |
| Database | PostgreSQL | 16+ | Schema-based multi-tenancy |
| Cache/Queue | Redis | 7+ | Laravel Horizon |
| Search | Meilisearch | | Infrastructure ready, not yet integrated |
| Desktop | Tauri | 2 | IziPOS offline-first POS |
| Frontend | React | 19 | |
| Build | Vite | 7 | |
| Types | TypeScript | strict | |
| State (server) | TanStack Query | 5 | |
| State (client) | Zustand | 5 | |
| Styling | Tailwind CSS | 4 | Custom design system with tokens |
| Mobile | React Native + Expo | | TypeScript |
| Time-series | TimescaleDB | | Audit logs |

### 2.2 Monorepo Structure

```
autoerp/
├── apps/
│   ├── api/          — Laravel backend (Domain modules, API)
│   ├── web/          — React frontend (features, components)
│   └── pos/          — [DESCRIBE: what is this app?]
├── packages/
│   └── shared/       — Shared DTOs, types, utilities
├── docker/           — Docker configurations
└── docs/             — Architecture, conventions, specs
```

### 2.3 Architectural Patterns

<!-- For each pattern, state: what it is, where it is enforced, and any known deviations. -->

**Hexagonal Architecture (Ports and Adapters)**
- Domain layer: zero dependencies on infrastructure
- Application layer: commands, queries, DTOs
- Infrastructure layer: Eloquent repositories, external APIs
- Presentation layer: controllers, requests, resources
- Enforcement: [consistent | mostly consistent | inconsistent across modules]
- Known deviations: [list modules that do not follow the pattern]

**CQRS Light**
- Commands modify state through domain layer
- Queries read from optimized read models (PostgreSQL views/materialized views)
- Journal entries serve as the financial read model

**Event-First Pattern**
- Fiscal events created FIRST inside transactions, then state updates
- Two-tier hash chain: fiscal chain (compliance) + audit log (fraud detection)

**Multi-Tenancy**
- PostgreSQL schema-based: each tenant gets `tenant_{slug}` schema
- Public schema holds shared lookup data and tenant registry
- [How tenant provisioning works]
- [How tenant data isolation is verified]

**Cross-Module Communication**
- Allowed via: `Shared/Contracts/` interfaces, Events, public Service classes
- Forbidden: direct model imports across modules
- [Any violations found]

### 2.4 Design System

<!-- Reference docs/architecture/design-system.md. Summarize key tokens, theme variants (IziPOS copper, Otospex pink). -->

---

## 3. Module Status Map

### 3.1 Backend Modules

Rate each module: COMPLETE, FUNCTIONAL, PARTIAL, SCAFFOLDED, MISSING.

| Module | Status | Domain | Application | Infrastructure | Presentation | Routes | Tests | Frontend | Notes |
|--------|--------|--------|-------------|----------------|--------------|--------|-------|----------|-------|
| Accounting | | | | | | | | | |
| Admin | | | | | | | | | |
| BatchExpiry | | | | | | | | | |
| Billing | | | | | | | | | |
| Cart | | | | | | | | | |
| Catalog | | | | | | | | | |
| Communication | | | | | | | | | |
| Company | | | | | | | | | |
| Compliance | | | | | | | | | |
| Contact | | | | | | | | | |
| Coupon | | | | | | | | | |
| Dashboard | | | | | | | | | |
| Document | | | | | | | | | |
| Expense | | | | | | | | | |
| Identity | | | | | | | | | |
| Import | | | | | | | | | |
| Inventory | | | | | | | | | |
| Loyalty | | | | | | | | | |
| Marketplace | | | | | | | | | |
| Media | | | | | | | | | |
| Menu | | | | | | | | | |
| POS | | | | | | | | | |
| Partner | | | | | | | | | |
| PlatformIntegration | | | | | | | | | |
| Pricing | | | | | | | | | |
| Product | | | | | | | | | |
| Progression | | | | | | | | | |
| Promotion | | | | | | | | | |
| PurchaseHub | | | | | | | | | |
| Scheduling | | | | | | | | | |
| Service | | | | | | | | | |
| SmartPrompts | | | | | | | | | |
| Taxation | | | | | | | | | |
| Tenant | | | | | | | | | |
| Treasury | | | | | | | | | |
| Uom | | | | | | | | | |
| Vehicle | | | | | | | | | |
| Workshop | | | | | | | | | |

### 3.2 Frontend Features

| Feature Directory | Corresponding Backend Module | Completeness | Notes |
|-------------------|------------------------------|-------------|-------|
| admin | Admin | | |
| auth | Identity | | |
| batches | BatchExpiry | | |
| catalog | Catalog | | |
| categories | Product (categories) | | |
| company | Company | | |
| compliance | Compliance | | |
| coupons | Coupon | | |
| crm | Contact/Partner | | |
| dashboard | Dashboard | | |
| documents | Document | | |
| enrichment | PlatformIntegration | | |
| expenses | Expense | | |
| finance | Accounting | | |
| import | Import | | |
| inventory | Inventory | | |
| inventory-counting | Inventory | | |
| location/locations | Company (branches) | | |
| loyalty | Loyalty | | |
| marketing | Promotion | | |
| menu | Menu | | |
| opening-balances | Accounting | | |
| parapharmacy | (vertical-specific) | | |
| partners | Partner | | |
| parts-catalog | Vehicle/Product | | |
| pos | POS | | |
| pricing | Pricing | | |
| products | Product | | |
| progression | Progression | | |
| promotions | Promotion | | |
| purchases | PurchaseHub | | |
| reports | (cross-module) | | |
| scheduling | Scheduling | | |
| services | Service | | |
| settings | (cross-module) | | |
| treasury | Treasury | | |
| uom | Uom | | |
| users | Identity | | |
| vat-reporting | Taxation | | |
| vehicles | Vehicle | | |
| withholding | Taxation | | |
| workshop-* | Workshop | | |

### 3.3 Shared Infrastructure

<!-- Describe what lives in apps/api/app/Shared/ — contracts, DTOs, events, enums, etc. -->

---

## 4. Business Flows

For each flow: describe the complete intended path, what steps exist in code, what is missing, and the priority for completion.

### 4.1 Sales Flow

```
[Quote] → [Sales Order] → [Delivery Note] → [Invoice] → [Payment] → [GL Posting]
   ?           ?               ?               ?            ?            ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Quote creation | | Document | | |
| Quote to Sales Order | | Document | | |
| Sales Order to Delivery | | Document | | |
| Delivery to Invoice | | Document | | |
| Invoice posting | | Document + Accounting | | |
| Payment recording | | Treasury | | |
| Payment allocation | | Treasury | | |
| GL journal entry | | Accounting | | |

### 4.2 Purchase Flow

```
[Purchase Order] → [Goods Receipt] → [Inventory Update] → [Supplier Invoice] → [AP Entry] → [Payment]
       ?                  ?                  ?                    ?                  ?            ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| PO creation | | PurchaseHub | | |
| PO approval | | PurchaseHub | | |
| Goods receipt | | Inventory | | |
| Inventory update | | Inventory | | |
| Supplier invoice | | Document | | |
| AP journal entry | | Accounting | | |
| Supplier payment | | Treasury | | |

### 4.3 POS Flow

```
[Open Shift] → [Scan/Search Items] → [Apply Discounts] → [Multi-Payment] → [Receipt] → [Z-Report] → [Close Shift]
      ?                ?                    ?                   ?               ?            ?              ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Shift management | | POS | | |
| Product lookup | | POS + Product | | |
| Cart management | | Cart | | |
| Discount application | | POS + Promotion | | |
| Payment processing | | POS + Treasury | | |
| Receipt generation | | POS + Compliance | | |
| Hash chain compliance | | Compliance | | |
| Z-report generation | | POS | | |
| Cash counting | | POS | | |
| Shift closing | | POS | | |

### 4.4 Inventory Flow

```
[Receipt] → [Stock Movement] → [Valuation] → [Adjustment] → [Counting] → [Reorder]
    ?              ?                ?              ?              ?            ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Goods receipt | | Inventory | | |
| Stock movements | | Inventory | | |
| Stock valuation | | Inventory | | |
| Stock adjustment | | Inventory | | |
| Cycle counting | | Inventory | | |
| Batch/expiry tracking | | BatchExpiry | | |
| Reorder management | | Inventory/PurchaseHub | | |

### 4.5 Accounting Flow

```
[Journal Entry] → [GL Posting] → [Trial Balance] → [Financial Statements] → [Period Close]
       ?                ?               ?                    ?                      ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Chart of accounts | | Accounting | | |
| Journal entries | | Accounting | | |
| GL posting | | Accounting | | |
| Trial balance | | Accounting | | |
| P&L statement | | Accounting | | |
| Balance sheet | | Accounting | | |
| Period closing | | Accounting | | |
| VAT reporting | | Taxation | | |

### 4.6 Treasury Flow

```
[Bank Accounts] → [Transactions] → [Reconciliation] → [Cash Flow]
       ?                ?                 ?                 ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Bank account setup | | Treasury | | |
| Payment instruments | | Treasury | | |
| Transaction recording | | Treasury | | |
| Bank reconciliation | | Treasury | | |
| Cash flow reporting | | Treasury | | |

### 4.7 Workshop Flow (Automotive)

```
[Vehicle Check-in] → [Work Order] → [Technician Assignment] → [Parts + Labor] → [Invoice] → [Vehicle Return]
         ?                ?                   ?                       ?               ?              ?
```

| Step | Status | Module | Key Files | Notes |
|------|--------|--------|-----------|-------|
| Vehicle registration | | Vehicle | | |
| Work order creation | | Workshop | | |
| Technician assignment | | Workshop | | |
| Parts requisition | | Workshop + Inventory | | |
| Labor tracking | | Workshop | | |
| Work order to invoice | | Workshop + Document | | |
| Core charge handling | | Workshop | | |

---

## 5. Vertical Roadmap

### 5.1 Priority Order

<!-- Which verticals ship first, second, third? With target dates. -->

| Priority | Vertical | Product | Target Date | MVP Feature Set | Status |
|----------|----------|---------|-------------|-----------------|--------|
| 1 | | | | | |
| 2 | | | | | |
| 3 | | | | | |

### 5.2 Vertical Feature Matrix

<!-- For each vertical, what modules are required? -->

| Module | Pharmacy | Parapharmacy | Restaurant | Retail | Mechanic | Parts Retailer | Tire Shop |
|--------|----------|-------------|------------|--------|----------|----------------|-----------|
| POS | Required | Required | Required | Required | Required | Required | Required |
| Inventory | Required | Required | Required | Required | Required | Required | Required |
| BatchExpiry | Required | Required | - | - | - | - | - |
| Vehicle | - | - | - | - | Required | Required | Required |
| Workshop | - | - | - | - | Required | - | - |
| Service | - | - | Required | - | Required | - | Required |
| Scheduling | - | - | Required | - | Required | - | - |
| Prescription | Required | - | - | - | - | - | - |
| Menu | - | - | Required | Required | - | - | - |
| Loyalty | Optional | Optional | Optional | Optional | Optional | Optional | Optional |

### 5.3 Per-Vertical Requirements

<!-- For the top 2-3 verticals, detail: -->
<!-- - Minimum viable feature set for first sale -->
<!-- - Compliance requirements (NF525, ZATCA, local fiscal rules) -->
<!-- - Integration requirements (TecDoc, pharmaceutical databases, etc.) -->
<!-- - Known customer requests -->

#### Vertical 1: [Name]

**MVP features:**
1.
2.
3.

**Compliance:**
-

**Integrations:**
-

**Customer feedback:**
-

---

## 6. Business Rules Reference

### 6.1 Pricing Rules

<!-- Document all pricing scenarios: B2B, B2C, volume discounts, contract pricing, promotions, tax-inclusive vs tax-exclusive display. -->

| Rule | Description | Enforced in Code? | Module | Notes |
|------|-------------|-------------------|--------|-------|
| | | | | |

### 6.2 Document Lifecycle Rules

<!-- State transitions for each document type. What is allowed, what is forbidden. -->

| Document Type | Draft → | Posted → | Cancelled → | Notes |
|---------------|---------|----------|-------------|-------|
| Quote | | | | |
| Sales Order | | | | |
| Invoice | | | | |
| Credit Note | | | | |
| Purchase Order | | | | |

### 6.3 Inventory Rules

<!-- Valuation method, negative stock policy, reservation behavior, batch/lot rules. -->

| Rule | Value | Configurable? | Notes |
|------|-------|---------------|-------|
| Valuation method | FIFO / Weighted Avg | | |
| Allow negative stock | | | |
| Auto-reserve on order | | | |
| Batch tracking | | | |
| Expiry enforcement | | | |

### 6.4 Accounting Rules

<!-- Fiscal year, period closing policy, auto-posting rules, chart of accounts structure. -->

### 6.5 Multi-Tenancy and Multi-Branch Rules

<!-- How tenants, companies, branches, and locations relate. Schema isolation boundaries. -->

### 6.6 Currency and Localization Rules

<!-- Supported currencies, exchange rate sources, rounding rules, locale-specific formatting. -->

### 6.7 Compliance Rules

<!-- NF525 requirements, hash chain rules, Z-report obligations, e-invoicing formats. -->

### 6.8 Offline Behavior Rules

<!-- What works offline (POS), what does not (web ERP), sync conflict resolution. -->

---

## 7. Quality Standards and Release Process

### 7.1 Definition of Shippable

| Criterion | Target | Current | Blocking? |
|-----------|--------|---------|-----------|
| PHPStan level | 8 | | Yes |
| TypeScript strict | Yes | | Yes |
| Backend test coverage | % | % | |
| Frontend test coverage | % | % | |
| E2E test coverage | critical paths | | |
| API response time (p95) | ms | ms | |
| POS page load time | s | s | |
| Uptime SLA | % | N/A | |

### 7.2 Quality Gates

```
preflight.sh = PHPStan + Pint + PHPUnit + TypeScript check + ESLint
```

Additional gates:
- [ ] All migrations run cleanly on fresh database
- [ ] Seed data produces working demo
- [ ] Hash chain verification passes
- [ ] Multi-tenant isolation verified
- [ ] Offline POS tested with network disconnect

### 7.3 Release Process

<!-- Continuous deployment? Versioned releases? Staging environment? -->

| Environment | URL | Deployment Method | Approval Required? |
|-------------|-----|-------------------|-------------------|
| Development | localhost | Manual | No |
| Staging | | Dokploy | |
| Production | | Dokploy | |

### 7.4 QA Process

<!-- Manual testing checklist? Dedicated QA person? Automated regression suite? -->

---

## 8. Technical Debt Register

### 8.1 Architecture Deviations

| ID | Description | Location | Severity | Remediation Plan | Priority |
|----|-------------|----------|----------|------------------|----------|
| TD-001 | | | Low/Med/High | | |

### 8.2 Code Quality Issues

| ID | Description | File Count | Type | Priority |
|----|-------------|-----------|------|----------|
| TD-100 | TODO/FIXME markers | | Incomplete code | |
| TD-101 | `any` types in TypeScript | | Type safety | |
| TD-102 | `mixed` types in PHP | | Type safety | |
| TD-103 | `app()` helper usage | | DI violation | |
| TD-104 | Models at Domain/ root vs Entities/ | | Inconsistency | |

### 8.3 Missing Test Coverage

| Module | Backend Tests | Frontend Tests | E2E Tests | Risk Level |
|--------|--------------|----------------|-----------|------------|
| | | | | |

### 8.4 Documentation Gaps

| Area | What Exists | What Is Missing | Priority |
|------|-------------|-----------------|----------|
| | | | |

---

## 9. Decisions Log

Record every significant decision. Format: what was decided, why, what alternatives were considered, and when.

### 9.1 Architecture Decisions

| ID | Date | Decision | Rationale | Alternatives Rejected |
|----|------|----------|-----------|----------------------|
| AD-001 | | PostgreSQL schema-based multi-tenancy | | Row-based, database-per-tenant |
| AD-002 | | Hexagonal architecture | | MVC, clean architecture |
| AD-003 | | Two-tier hash chain for compliance | | Single chain, no chain |
| AD-004 | | Unified documents table with subtypes | | Separate tables per doc type |
| AD-005 | | Tauri 2 for desktop POS | | Electron, PWA-only |
| AD-006 | | CQRS light (not full event sourcing) | | Full ES, simple CRUD |

### 9.2 Technology Decisions

| ID | Date | Decision | Rationale | Alternatives Rejected |
|----|------|----------|-----------|----------------------|
| | | | | |

### 9.3 Business Decisions

| ID | Date | Decision | Rationale | Alternatives Rejected |
|----|------|----------|-----------|----------------------|
| | | | | |

### 9.4 Rejected Approaches

<!-- Things the founder has tried and explicitly rejected. Do not suggest these again. -->

| Approach | Why It Was Rejected | Date |
|----------|---------------------|------|
| | | |

---

## 10. Open Questions

Items that remain unresolved after the discovery session. Each must have an owner and a deadline for resolution.

| ID | Question | Context | Owner | Deadline | Resolution |
|----|----------|---------|-------|----------|------------|
| OQ-001 | | | | | |

---

## Appendices

### A. File Reference

Key files and their purposes, for quick navigation.

| File | Purpose |
|------|---------|
| `CLAUDE.md` | Master architecture doc and agent operational rules |
| `AGENTS.md` | Monorepo structure, coding style, commit conventions |
| `.claude/context/architecture.md` | Hexagonal layers, module structure, cross-module rules |
| `.claude/context/compliance.md` | Hash chains, NF525, ZATCA, event-first pattern |
| `.claude/context/i18n.md` | Translation setup, RTL, key naming |
| `docs/conventions/*.md` | API responses, routing, auth, types, queries, forms, DI |
| `docs/architecture/verticals.md` | All 12 business verticals |
| `docs/otospex/ROADMAP.md` | Automotive vertical gap analysis |
| `docs/modules/treasury.md` | Payment method configuration |
| `scripts/preflight.sh` | Pre-commit quality gate |

### B. Glossary

| Term | Definition |
|------|-----------|
| Tenant | A company/organization with its own PostgreSQL schema |
| Vertical | A business type (e.g., parapharmacy, mechanic) with specific module configuration |
| IziPOS | The retail/pharma product (copper theme) |
| Otospex | The automotive product (pink theme) |
| Fiscal chain | SHA-256 hash chain for compliance (NF525/ZATCA) |
| Audit log | TimescaleDB event log for fraud detection |
| Document | Unified entity covering quotes, orders, invoices, credit notes, receipts |

### C. Migration Count and Schema Stats

<!-- Populated during discovery -->

- Total migrations: ~328
- Total backend test files: ~574
- Total frontend test files: ~163
- Total backend modules: ~38
- Total frontend features: ~45
