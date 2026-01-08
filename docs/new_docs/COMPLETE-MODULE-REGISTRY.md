# Complete Module Registry

**Last Updated:** December 30, 2025  
**Purpose:** Master list of all modules, their dependencies, and parallel execution compatibility

---

## Module Categories

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           MODULE ARCHITECTURE                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─────────────────────────────────────────────────────────────────────┐    │
│  │                     CORE INFRASTRUCTURE                              │    │
│  │  Identity │ Company │ Settings │ Communication │ Media               │    │
│  │                        [ALL COMPLETE ✅]                             │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│  ┌─────────────────────────────────▼───────────────────────────────────┐    │
│  │                     SHARED BUSINESS                                  │    │
│  │  Product │ Partner │ Document │ Inventory │ Treasury │ Accounting   │    │
│  │                        [ALL COMPLETE ✅]                             │    │
│  └─────────────────────────────────────────────────────────────────────┘    │
│                                    │                                         │
│         ┌──────────────────────────┼──────────────────────────┐             │
│         │                          │                          │             │
│         ▼                          ▼                          ▼             │
│  ┌─────────────┐          ┌───────────────┐          ┌─────────────┐        │
│  │  COMPLIANCE │          │  POS SYSTEM   │          │  VERTICALS  │        │
│  │             │          │               │          │             │        │
│  │ E-Invoice   │          │ POS Core      │          │ Vehicle     │        │
│  │ Withholding │          │ Cashiers      │          │ Workshop    │        │
│  │ (per country)│         │ Hardware      │          │ Menu/Recipe │        │
│  │             │          │ Batch/Expiry  │          │ Tables      │        │
│  └─────────────┘          └───────────────┘          └─────────────┘        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Complete Module List

### ✅ COMPLETE - Core Infrastructure

| Module | Description | Status |
|--------|-------------|--------|
| Identity | Authentication, users, roles, permissions | ✅ Complete |
| Company | Multi-company, legal entities, settings | ✅ Complete |
| Settings | System configuration | ✅ Complete |
| Communication | Notifications, emails | ✅ Complete |
| Media | File storage, images | ✅ Complete |

### ✅ COMPLETE - Shared Business

| Module | Description | Status |
|--------|-------------|--------|
| Product | Catalog, categories, variants | ✅ Complete |
| Partner | Customers, suppliers, contacts | ✅ Complete |
| Document | Quotes, orders, invoices, credit notes | ✅ Complete |
| Inventory | Stock, movements, reservations | ✅ Complete |
| Treasury | Payments, cash tracking | ✅ Complete |
| Accounting | Chart of accounts, journals | ✅ Complete |

### 🔵 TO BUILD - Compliance

| Module | Description | Priority | Depends On |
|--------|-------------|----------|------------|
| **TN-EInvoice** | Tunisia El Fatoora e-invoicing | 🔴 P0 | Document |
| **TN-Withholding** | Tunisia TEJ withholding tax | 🟠 P1 | Treasury, Partner |
| FR-NF525 | France fiscal certification | 🟢 P3 | POS, Document |
| DE-TSE | Germany TSE integration | 🟢 P3 | POS |
| SA-ZATCA | Saudi Arabia Phase 2 | 🟢 P3 | Document |

### 🔵 TO BUILD - POS System

| Module | Description | Priority | Depends On |
|--------|-------------|----------|------------|
| **POS-Core** | Terminals, sessions, transactions, hash chain | 🔴 P0 | Document, Treasury |
| **POS-Cashiers** | Cashier management, shifts, permissions | 🟠 P1 | POS-Core, Identity |
| POS-Hardware | Printer, drawer, scanner integration | 🟡 P2 | POS-Core |
| **Batch-Expiry** | Lot tracking, FEFO, expiry alerts | 🔴 P0 | Inventory, Product |
| POS-Offline | Desktop app, SQLite, sync | 🟡 P2 | POS-Core |

### ✅ COMPLETE - Otospex Verticals

| Module | Description | Status |
|--------|-------------|--------|
| Vehicle | VIN, make/model, vehicle data | ✅ Complete |
| Workshop | Work orders, labor tracking | ✅ Complete |

### 🔵 TO BUILD - IziPOS Verticals

| Module | Description | Priority | Depends On |
|--------|-------------|----------|------------|
| Menu | F&B menu items, categories | 🟡 P2 | Product |
| Recipe | Ingredients, costing, prep | 🟡 P2 | Menu, Inventory |
| Tables | Floor plan, table management | 🟡 P2 | POS-Core |
| Reservations | Table bookings | 🟢 P3 | Tables, Partner |

### 🔵 FUTURE - Advanced

| Module | Description | Priority | Depends On |
|--------|-------------|----------|------------|
| E-Commerce | Online store, cart, checkout | 🟢 P3 | Product, Partner |
| B2B-Marketplace | Wholesale, group buying | 🟢 P3 | E-Commerce |
| Analytics | BI, dashboards, reporting | 🟢 P3 | All data modules |
| Import-Export | Bulk data operations | 🟡 P2 | Product, Partner |

---

## Priority Legend

| Priority | Meaning | Timeline |
|----------|---------|----------|
| 🔴 P0 | Critical - Start immediately | Now |
| 🟠 P1 | High - Start within 2 weeks | 2 weeks |
| 🟡 P2 | Medium - Start within 1 month | 1 month |
| 🟢 P3 | Low - Future planning | 2+ months |

---

## Parallel Execution Matrix

**Which modules can be built simultaneously?**

```
                    │ TN-E │ TN-W │ POS  │ Cash │ Batch│ Menu │
                    │Invoic│hold │ Core │ iers │Expiry│Recipe│
────────────────────┼──────┼──────┼──────┼──────┼──────┼──────┤
TN-EInvoice         │  -   │  ✅  │  ✅  │  ✅  │  ✅  │  ✅  │
TN-Withholding      │  ✅  │  -   │  ✅  │  ✅  │  ✅  │  ✅  │
POS-Core            │  ✅  │  ✅  │  -   │  ❌  │  ✅  │  ✅  │
POS-Cashiers        │  ✅  │  ✅  │  ❌  │  -   │  ✅  │  ✅  │
Batch-Expiry        │  ✅  │  ✅  │  ✅  │  ✅  │  -   │  ✅  │
Menu/Recipe         │  ✅  │  ✅  │  ✅  │  ✅  │  ✅  │  -   │

✅ = Can run in parallel (no conflicts)
❌ = Sequential dependency (must wait)
```

### Parallel Groups

**Group A - Can all run simultaneously:**
- TN-EInvoice
- TN-Withholding
- Batch-Expiry

**Group B - Sequential (POS chain):**
- POS-Core → POS-Cashiers → POS-Hardware → POS-Offline

**Group C - Sequential (F&B chain):**
- Menu → Recipe → Tables → Reservations

---

## Recommended Execution Plan

### Phase 1: Immediate Start (Week 1-4)

| Instance | Module | Why |
|----------|--------|-----|
| Claude Code #1 | Core improvements (pagination) | Already running |
| Claude Code #2 | **TN-EInvoice** | You have docs, critical |
| Claude Code #3 | **Batch-Expiry** | No dependencies, needed for pharmacy |
| Claude Code #4 | **POS-Core** (design only) | Spec refinement while others build |

### Phase 2: POS Build (Week 3-8)

| Instance | Module | Why |
|----------|--------|-----|
| Claude Code #1 | **POS-Core** implementation | After spec complete |
| Claude Code #2 | **TN-Withholding** | After e-invoice patterns established |
| Claude Code #3 | Continue Batch-Expiry or switch to POS-Cashiers | When Batch done |

### Phase 3: Polish (Week 6-12)

| Instance | Module | Why |
|----------|--------|-----|
| All | POS-Cashiers, POS-Hardware | Complete POS ecosystem |
| Future | Menu/Recipe | When F&B customers ready |

---

## File Conflict Zones

**Modules that touch the same files (CANNOT run in parallel without careful coordination):**

| Files | Touched By |
|-------|------------|
| `Document` model/service | TN-EInvoice, Document improvements |
| `Inventory` model/service | Batch-Expiry, POS-Core |
| `Treasury` model/service | TN-Withholding, POS-Core |
| Navigation/sidebar | ALL new modules |
| Routes | ALL new modules (but different files per module) |

**Mitigation:**
- Each module has its own routes.php
- Navigation changes are small, easy to merge
- Document/Inventory/Treasury extensions should use events, not modifications

---

## Module Specifications Status

| Module | Spec Document | Status |
|--------|---------------|--------|
| TN-EInvoice | TO CREATE | 🔵 Need from user docs |
| TN-Withholding | TAX-WITHHOLDING-SPEC.md | ✅ Created |
| POS-Core | POS-MODULE-SPEC.md | 🟡 Needs expansion |
| POS-Cashiers | TO CREATE | 🔵 Not started |
| Batch-Expiry | BATCH-EXPIRY-SPEC.md | ✅ Created |
| Menu/Recipe | TO CREATE | 🔵 Not started |

---

## Next Actions

1. [ ] User provides Tunisia e-invoice documentation
2. [ ] Create TN-EINVOICE-SPEC.md from that documentation
3. [ ] Expand POS-MODULE-SPEC.md with cashier management details
4. [ ] Start parallel instances on non-conflicting modules
5. [ ] Establish Codex verification workflow
