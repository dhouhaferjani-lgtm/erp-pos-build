# ERP Platform Project Status

**Last Updated:** December 29, 2025  
**Products:** Otospex (Automotive) | IziPOS (Generic Retail/F&B)

---

## Executive Summary

Multi-product ERP platform built on a single codebase serving two distinct markets:
- **Otospex**: Automotive businesses (mechanics, body shops, parts retailers)
- **IziPOS**: Generic retail and F&B (para-pharmacy, coffee shops, restaurants)

---

## Core Platform Status: ✅ READY (90% Confidence)

Verified December 28, 2025. The core is ready for module development.

### What's Complete

| Component | Status | Notes |
|-----------|--------|-------|
| Multi-tenancy | ✅ Ready | Schema-based PostgreSQL with RLS |
| Multi-company | ✅ Ready | Tenant → multiple Companies (legal entities) |
| Identity/Auth | ✅ Ready | Full authentication, authorization |
| Product Catalog | ✅ Ready | Categories (materialized path), variants |
| Partner Management | ✅ Ready | Customers, suppliers, contacts |
| Document System | ✅ Ready | Quote→Order→Invoice→CreditNote flow |
| Inventory | ✅ Ready | Stock reservations, fraud detection |
| Treasury | ✅ Ready | Payments, cash tracking |
| Accounting | ✅ Ready | Chart of accounts, journal entries |
| Hash Chains | ✅ Ready | Fiscal compliance foundation |
| Vehicle Module | ✅ Ready | Decoupled from core via snapshots |

### Known Minor Issues (Non-Blocking)

- Some i18n translations incomplete
- UI polish needed in some areas
- Test coverage could be expanded
- Performance baseline not yet established

---

## Development Tracks

### Track 1: IziPOS Module (Primary Focus)
**Goal:** Functional POS for para-pharmacy and coffee shops

| Phase | Description | Status |
|-------|-------------|--------|
| 1A | Core POS Infrastructure (terminal, cash register, X/Z reports) | 🔵 Not Started |
| 1B | Retail POS Features (scanning, payments, discounts) | 🔵 Not Started |
| 1C | Batch/Expiry Tracking (FEFO for pharmacy) | 🔵 Not Started |

### Track 2: Tunisia Compliance (Strategic)
**Goal:** TEJ platform integration for withholding tax

| Phase | Description | Status |
|-------|-------------|--------|
| 2A | WithholdingTaxRule model + Tunisia rates | 🔵 Not Started |
| 2B | Purchase withholding + certificate generation | 🔵 Not Started |
| 2C | TEJ XML export | 🔵 Not Started |
| 2D | Sales withholding tracking | 🔵 Not Started |

### Track 3: Core Refinement (Background)
**Goal:** Ongoing improvements while building modules

| Task | Description | Status |
|------|-------------|--------|
| Import | Product/Partner import improvements | 🟡 In Progress |
| Tests | Expand test coverage | 🔵 Not Started |
| Performance | Baseline and optimization | 🔵 Not Started |

---

## Technical Architecture

### Single Codebase, Two Products

```
┌─────────────────────────────────────────────────┐
│                 SINGLE CODEBASE                 │
├─────────────────────────────────────────────────┤
│                                                 │
│  ┌─────────────┐  ┌─────────────────────────┐   │
│  │    CORE     │  │    SHARED BUSINESS      │   │
│  │  Identity   │  │  Products, Partners     │   │
│  │  Company    │  │  Documents, Inventory   │   │
│  │  Settings   │  │  Treasury, Accounting   │   │
│  └─────────────┘  └─────────────────────────┘   │
│                                                 │
│  ┌─────────────────────┐ ┌───────────────────┐  │
│  │   OTOSPEX MODULES   │ │  IZIPOS MODULES   │  │
│  │   Vehicle           │ │  POS              │  │
│  │   Workshop          │ │  Menu             │  │
│  │   (automotive)      │ │  Tables           │  │
│  └─────────────────────┘ └───────────────────┘  │
│                                                 │
└─────────────────────────────────────────────────┘
           │                        │
           ▼                        ▼
    ┌──────────────┐        ┌──────────────┐
    │   OTOSPEX    │        │    IZIPOS    │
    │  Deployment  │        │  Deployment  │
    │              │        │              │
    │ APP_PRODUCT  │        │ APP_PRODUCT  │
    │ = otospex    │        │ = izipos     │
    └──────────────┘        └──────────────┘
```

### Key Environment Variable

```env
APP_PRODUCT=otospex   # or izipos
```

This controls:
- Which modules are loaded
- Visual theming (colors, logo)
- Feature availability
- Signup flow presets

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `config/products.php` | Product definitions, module registry |
| `App\Services\ProductService` | Runtime product detection |
| `App\Modules\Company\Domain\Company` | Company model |
| `App\Modules\Document\Domain\CreditNote` | Credit note (not CreditMemo) |

---

## Contacts & Resources

- **Architecture Planning**: Claude (this project)
- **Implementation**: Claude Code
- **Verification**: Codex, Gemini (for cross-checking)

---

## Next Actions

1. ☐ Complete import functionality improvements (Track 3)
2. ☐ Begin POS module Phase 1A (terminal management)
3. ☐ Research TEJ XML schema specifications
4. ☐ Establish performance baseline
