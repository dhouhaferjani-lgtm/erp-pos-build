# Fork Readiness Report

**Generated:** 2025-12-05
**Auditor:** Claude Code (Opus 4.5)
**Purpose:** Determine readiness for forking into AutoERP and BossCloud repositories

---

## Executive Summary

The AutoERP codebase demonstrates a well-architected foundation with strong modular design, comprehensive multi-tenancy, and solid business flow implementations. The core infrastructure (documents, treasury, partners, inventory) is production-ready and generic enough to support both automotive and general retail verticals. **The codebase is ready for forking** with a few items to address for clean separation.

## Readiness Score: 7.5/10

---

## Critical Blockers

None. The codebase can be forked immediately.

---

## High Priority Items

1. **Vehicle Module Coupling** - The `Document` model has optional `vehicle_id` foreign key. For BossCloud, this field should be ignored/hidden but doesn't block functionality.

2. **Pricing Service Cross-Reference** - Found reference to `App\Modules\Catalog\Domain\Product` in Pricing module (should be `App\Modules\Product`) - needs path correction.

3. **Workshop Module Empty** - The Workshop module exists but has minimal implementation. Should either be fully implemented or removed before fork.

---

## Core Infrastructure Status

| Component | Status | Notes |
|-----------|--------|-------|
| Multi-tenancy | ✅ Ready | Schema-based with `tenant_id` and `company_id` scoping. `CompanyContextMiddleware` handles tenant resolution. |
| Auth/Permissions | ✅ Ready | Spatie Permission package integrated. Frontend uses `RequirePermission` components. Granular permissions (sales.create, treasury.create, etc.) |
| Document Engine | ✅ Ready | Unified `Document` model with enums for type (Quote, SalesOrder, Invoice, etc.) and status (Draft, Confirmed, Posted). Full conversion flow implemented. |
| Partner/CRM | ✅ Ready | Unified Partner model for customers/suppliers. Located in `Modules/Partner`. |
| Treasury | ✅ Ready | Complete implementation: Payment, PaymentMethod, PaymentRepository, PaymentInstrument, PaymentAllocation models. Supports multi-payment and refunds. |
| i18n | ✅ Ready | Frontend: EN/FR/AR (AR ready for RTL). ~1200+ translation keys per language. Backend: EN/FR lang files. |
| Compliance/Audit | ✅ Ready | FiscalHashService with SHA256 chain. AuditService for event logging. AnomalyDetectionService implemented. |

---

## Module System Status

| Aspect | Status | Notes |
|--------|--------|-------|
| Module boundaries | ⚠️ Needs Work | Some cross-module imports exist (Document → Inventory, Treasury → Document). Should use interfaces/events for cleaner separation. |
| Enable/disable per tenant | ⚠️ Needs Work | No explicit module toggle system. Modules are always loaded. Would need feature flags for BossCloud to hide automotive modules. |
| Cross-module dependencies | ⚠️ Needs Work | Document imports Product, Inventory services directly. Treasury imports Document models. Consider adding Shared/Contracts for cross-module interfaces. |

### Backend Modules (19 total)

**Generic (Safe for both forks):**
- Identity (auth, users, roles)
- Tenant (multi-tenancy)
- Company (company management)
- Partner (customers/suppliers)
- Product (product catalog)
- Document (quotes, orders, invoices)
- Inventory (stock management)
- Treasury (payments)
- Accounting (GL, journal entries)
- Compliance (hash chains, audit)
- Import (data import)
- Dashboard
- Media
- Communication
- Pricing

**Automotive-Specific (AutoERP only):**
- Vehicle (VIN, license plates, makes/models)
- Workshop (job cards, labor - partially implemented)

**Empty/Stub Modules:**
- Catalog (referenced but points to Product)
- Sales (routes only, logic in Document)

---

## Business Flow Completeness

| Flow | Status | Notes |
|------|--------|-------|
| Quote creation | ✅ Ready | Full CRUD with line items, tax calculation, validation |
| Quote → Order | ✅ Ready | `DocumentConversionService.convertQuoteToOrder()` - validates status, copies lines, marks source |
| Order → Delivery | ✅ Ready | `convertOrderToDelivery()` implemented |
| Order → Invoice | ✅ Ready | `convertOrderToInvoice()` with partial invoicing support |
| Invoice posting | ✅ Ready | Status transition to Posted, creates GL entries via `GeneralLedgerService.createFromInvoice()` |
| Payment recording | ✅ Ready | PaymentController with allocation to documents |
| Payment allocation | ✅ Ready | `PaymentAllocation` model for partial payments |
| Credit notes | ✅ Ready | `RefundService`, Credit Note document type |
| Purchase flow | ✅ Ready | PurchaseOrder → Receipt flow implemented |

---

## Code Isolation Analysis

### Generic Code (Safe for both forks)

All modules except Vehicle and Workshop:
- Document (document_type enum includes Quote, SalesOrder, PurchaseOrder, Invoice, CreditNote, DeliveryNote)
- Treasury (universal payment methods, supports cash, bank, check, card, mobile money)
- Partner (unified customer/supplier)
- Inventory (locations, stock levels, landed cost)
- Accounting (chart of accounts, journal entries)
- Identity (users, roles, permissions)

### Automotive-Specific Code (AutoERP only)

| Location | Description |
|----------|-------------|
| `Modules/Vehicle/` | Full module: Vehicle model, VIN handling, license plates |
| `Document.vehicle_id` | Optional FK to vehicles table |
| `DocumentData.vehicle_id` | DTO includes vehicle reference |
| `DocumentConversionService` | References vehicle_id in conversions |
| `Product.cross_references` | OEM cross-reference support |
| `apps/web/src/features/vehicles/` | Frontend vehicle management |
| `apps/web/src/components/organisms/AddVehicleModal/` | Vehicle modal component |

### Code Requiring Refactoring for Clean Fork

1. **Document → Vehicle coupling**: Make vehicle_id nullable and hide in BossCloud UI
2. **Module discovery**: Add config to enable/disable modules per tenant type
3. **Frontend routes**: Conditionally show Vehicle menu item based on tenant config
4. **Cross-references**: Make optional, only show in auto parts context

---

## Test Coverage

| Area | Files | Notes |
|------|-------|-------|
| Backend Unit | ~40 files | Located in `tests/Unit/` - covers services, DTOs |
| Backend Feature | ~47 files | Located in `tests/Feature/` - covers API endpoints |
| Frontend Unit | ~10 files | `.test.tsx` files in feature directories |
| E2E Tests | 13 files | Playwright specs: auth, company, documents, payments |

### Test Organization

```
apps/api/tests/
├── Feature/           # 20 subdirectories by module
│   ├── Accounting/
│   ├── Document/
│   ├── Inventory/
│   ├── Partner/
│   ├── Treasury/
│   ├── Vehicle/
│   └── ...
└── Unit/              # 12 subdirectories
    ├── Accounting/
    ├── Document/
    ├── Inventory/
    └── ...
```

**Total Backend Tests:** 87 test files
**Total Frontend Tests:** 23 test files

---

## Documentation Status

| Document | Status | Notes |
|----------|--------|-------|
| Architecture (CLAUDE.md) | ✅ Complete | Comprehensive 600+ line architecture doc |
| Database (DATABASE.md) | ✅ Complete | ~32K characters, full schema documentation |
| Treasury (TREASURY.md) | ✅ Complete | ~15K characters, payment method configuration |
| Frontend (FRONTEND.md) | ✅ Complete | ~20K characters, React patterns |
| Design System | ✅ Complete | Tailwind tokens documented |
| Permissions | ✅ Complete | Permission matrix documented |
| API docs | ⚠️ Needs Work | No OpenAPI/Swagger. Routes defined but not documented |

---

## Recommendations

### Before Fork

1. **Create module toggle system**
   ```php
   // config/modules.php
   return [
       'vehicle' => env('MODULE_VEHICLE_ENABLED', true),
       'workshop' => env('MODULE_WORKSHOP_ENABLED', true),
   ];
   ```

2. **Fix Pricing module import path**
   - Change `App\Modules\Catalog\Domain\Product` to `App\Modules\Product\Domain\Product`

3. **Clean up Workshop module**
   - Either implement fully or remove the empty structure

4. **Add shared interfaces**
   - Create `Shared/Contracts/ProductInterface.php` for cross-module product references

### Immediately After Fork

**For AutoERP:**
1. Enable all modules including Vehicle and Workshop
2. Add TecDoc integration endpoints
3. Implement work order/job card functionality
4. Add VIN decoding service

**For BossCloud:**
1. Disable Vehicle and Workshop modules via config
2. Remove vehicle-related frontend components
3. Add POS-specific modules (table management, kitchen display)
4. Add hospitality-specific document types (order ticket, bill)

### Technical Debt to Address

1. **Cross-module imports**: Some controllers import from other modules directly instead of through interfaces
2. **Missing API documentation**: OpenAPI spec should be generated
3. **Type generation**: TypeScript types not auto-generated from backend DTOs (manual sync)
4. **Event sourcing lite**: Events recorded but not used for replay/rebuild
5. **Hash chain verification**: CLI command exists but no automated verification in CI

---

## Fork Strategy Recommendation

### Option A: Copy + Diverge (Recommended)
1. Create two new repositories: `autoerp` and `bosscloud`
2. Copy current codebase to both
3. Delete automotive modules from BossCloud
4. Maintain separate codebases going forward

**Pros:** Clean separation, independent roadmaps
**Cons:** No shared updates, potential drift

### Option B: Shared Core + Feature Packages
1. Extract core to `@autoerp/core` package
2. Create `@autoerp/automotive` and `@autoerp/hospitality` packages
3. Each product imports core + relevant packages

**Pros:** Shared maintenance, consistent foundation
**Cons:** More complex build, package versioning overhead

### Recommended: Option A
Given the current architecture without package management for modules, Option A is simpler and faster to execute. The codebase is stable enough that divergence won't cause major issues.

---

## Appendix: File Counts by Module

| Module | PHP Files | Notes |
|--------|-----------|-------|
| Document | ~25 | Core business logic |
| Treasury | ~20 | Payment system |
| Inventory | ~15 | Stock management |
| Partner | ~12 | CRM |
| Accounting | ~12 | Financial ledger |
| Vehicle | ~10 | Automotive-specific |
| Identity | ~10 | Auth/users |
| Company | ~10 | Company settings |
| Compliance | ~8 | Audit/hash chains |
| Product | ~8 | Catalog |

---

*Report generated by Claude Code audit process*
*Based on codebase state as of 2025-12-05*
