# AutoERP Modules

> Overview of all backend modules in AutoERP.

---

## Module Map

```
app/Modules/
├── Identity/        # Users, roles, permissions, devices
├── Tenant/          # Multi-tenancy, subscriptions
├── Company/         # Companies, locations, fiscal years
├── Partner/         # Customers, suppliers, contacts
├── Product/         # Products, categories
├── Catalog/         # Product catalogs, pricing rules
├── Vehicle/         # Vehicles, VIN decoding
├── Document/        # Quotes, orders, invoices, credit notes
├── Inventory/       # Stock, locations, movements, counting
├── Treasury/        # Payments, instruments, repositories
├── Accounting/      # Chart of accounts, journal entries, GL
├── Pricing/         # Price calculations, margins
├── Compliance/      # Fiscal hash chains, audit trails
├── Import/          # Data imports, staging
├── Communication/   # SMS, email, notifications
├── Media/           # Files, images, documents
├── Dashboard/       # Analytics, widgets
├── Sales/           # Sales-specific logic
└── Workshop/        # Work orders, labor, scheduling
```

---

## Core Modules

### [Identity](./identity.md)
User authentication, authorization, roles, and permissions.

### [Tenant](./tenant.md)
Multi-tenancy management and subscription handling.

### [Company](./company.md)
Company entities, locations, and fiscal configuration.

### [Partner](./partner.md)
Customers and suppliers with credit/receivable tracking.

### [Document](./document.md)
Unified document handling for all trade documents.

### [Inventory](./inventory.md)
Stock management, movements, and physical counting.

### [Treasury](./treasury.md)
Payment methods, instruments, and payment allocation.

### [Accounting](./accounting.md)
Chart of accounts, journal entries, and general ledger.

---

## Module Dependencies

```
Identity ──────────────────────────────────────────────┐
    │                                                  │
    ▼                                                  │
Tenant ────► Company ────► Partner                     │
                │              │                       │
                ▼              ▼                       │
            Document ◄──── Product ◄──── Catalog      │
                │              │                       │
                ▼              ▼                       │
           Treasury ◄───── Inventory                   │
                │              │                       │
                └──────► Accounting ◄──────────────────┘
                              │
                              ▼
                         Compliance
```

---

## Cross-Module Communication Rules

1. **Via Interfaces** - Import from `Shared/Contracts/`
2. **Via Events** - For async communication
3. **Via Public Service** - Each module's facade

**Forbidden:** Directly importing models across modules.

```php
// WRONG - Direct import
use App\Modules\Inventory\Domain\Stock;

// RIGHT - Via interface
use App\Shared\Contracts\Inventory\StockServiceInterface;
```
