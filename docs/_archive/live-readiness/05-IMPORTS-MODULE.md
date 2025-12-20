# Imports Module Status

> Data import capabilities and gaps

---

## Executive Summary

**Module Completeness: 80%**

The system has a mature, modular import infrastructure with:
- General Import Framework (Partners, Products, Stock, Opening Balances)
- Specialized Opening Balance Batches (GL, Inventory, AR/AP)

---

## 1. Architecture Overview

### Import Module Structure

```
apps/api/app/Modules/Import/
├── Domain/
│   ├── Enums/
│   │   ├── ImportType.php        # Partners, Products, StockLevels, OpeningBalances
│   │   └── ImportStatus.php      # Pending → Validating → Validated → Completed
│   ├── ImportJob.php             # Master job model
│   └── ImportRow.php             # Individual row tracking
├── Services/
│   ├── ImportService.php         # Main orchestration
│   ├── ValidationEngine.php      # Rule-based validation
│   └── MigrationWizardService.php # Dependency checking
└── Presentation/
    └── Controllers/
        ├── ImportController.php
        └── MigrationWizardController.php
```

---

## 2. Supported Import Types

### General Imports

| Type | Required Columns | Optional Columns | Validation |
|------|-----------------|------------------|-----------|
| **Partners** | name, type | email, phone, vat_number, address | Type enum, email format |
| **Products** | name, sku, type | description, prices, barcode | Type enum, numeric prices |
| **StockLevels** | product_sku, location_code, qty | notes | SKU/location lookup, positive qty |
| **OpeningBalances** | account_code, debit, credit | description | Account lookup, balanced entries |

### Opening Balance Batches

| Type | Purpose | Creates |
|------|---------|---------|
| **ACCOUNTING** | GL opening balances | Historical journal entry |
| **INVENTORY** | Stock opening | Stock movements + GL entries |
| **AR_OPENING_ITEMS** | Customer invoices | Historical AR documents |
| **AP_OPENING_ITEMS** | Supplier invoices | Historical AP documents |

---

## 3. Import Workflow

### Five-Step Process

```
1. createJob()     → Create ImportJob record with file path
2. parseCsvFile()  → Parse CSV, create ImportRow entries
3. validateJob()   → Validate each row, mark valid/invalid
4. getValidRows()  → Retrieve rows that passed
5. executeImport() → Create entities transactionally
```

### Opening Balance Batch Lifecycle

```
DRAFT → VALIDATING → VALIDATED → (post) → LOCKED (immutable)
  ↓
ERROR/FAILED
```

---

## 4. API Endpoints

### General Import

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/v1/imports` | Upload CSV, create job |
| GET | `/api/v1/imports` | List jobs (paginated) |
| GET | `/api/v1/imports/{id}` | Job details + progress |
| GET | `/api/v1/imports/{id}/errors` | Validation errors |
| POST | `/api/v1/imports/{id}/execute` | Run import |

### Opening Balance Batches

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/opening-batches` | Create batch |
| GET | `/opening-batches` | List batches |
| GET | `/opening-batches/status` | Status per type |
| POST | `/{batchId}/import` | Add rows from CSV |
| POST | `/{batchId}/validate` | Validate rows |
| GET | `/{batchId}/preview` | Preview posting |
| POST | `/{batchId}/post` | Execute posting |
| POST | `/{batchId}/lock` | Lock (immutable) |

### Migration Wizard

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/migration-wizard/order` | Recommended import order |
| GET | `/migration-wizard/dependencies/{type}` | Check dependencies |
| POST | `/migration-wizard/suggest-mapping` | AI column mapping |
| GET | `/migration-wizard/template/{type}` | Download CSV template |

---

## 5. Opening Balance Services

### AccountingOpeningService

**File**: `Accounting/Application/Services/AccountingOpeningService.php`

- Validates account codes exist and are active
- Ensures debits = credits (balanced)
- Creates historical journal entries (`is_historical = true`)
- Adds Opening Balance Equity offset if needed

### InventoryOpeningService

**File**: `Inventory/Application/Services/InventoryOpeningService.php`

- Validates product SKUs and location codes
- Creates stock movements (`movement_type = 'opening'`)
- Creates GL entries: Dr. Inventory, Cr. Opening Balance Equity
- Updates stock levels to opening quantities

### ArApOpeningService

**File**: `Document/Application/Services/ArApOpeningService.php`

- Validates partner codes
- Creates historical documents with:
  - `is_historical = true`
  - `external_document_number` for original invoice
  - `balance_due` set to open amount

---

## 6. Frontend Features

### Import Dashboard

**Location**: `apps/web/src/features/import/`

```
import/
├── pages/
│   ├── ImportDashboardPage.tsx    # Type selector
│   ├── ImportWizardPage.tsx       # Upload + validation wizard
│   └── ImportHistoryPage.tsx      # Past imports
├── components/
│   ├── FileUpload.tsx
│   ├── ColumnMapper.tsx
│   ├── ValidationGrid.tsx
│   └── ImportProgress.tsx
└── api/
    ├── importApi.ts
    └── queries.ts
```

### Opening Balance Wizard

**Location**: `apps/web/src/features/opening-balances/`

7-step wizard:
1. Setup (name, cutover date)
2. Upload CSV
3. Validate rows
4. Preview posting
5. Post (create GL/docs)
6. Lock (immutable)
7. Complete

---

## 7. Import Capabilities Matrix

### What CAN Be Imported

| Entity | Format | Relationships | GL Impact |
|--------|--------|---------------|-----------|
| Partners | CSV | None | No |
| Products | CSV | None | No |
| Stock Levels | CSV | Product, Location | No |
| GL Opening | CSV/Batch | Account | Yes |
| Inventory Opening | CSV/Batch | Product, Location | Yes |
| AR/AP Opening | CSV/Batch | Partner | No (GL separate) |

### What CANNOT Be Imported

| Entity | Reason | Workaround |
|--------|--------|-----------|
| **Locations** | No service | Create via API/UI |
| **Chart of Accounts** | Seeder only | Tunisia seeder exists |
| **Payment Methods** | Seeder only | Database seed |
| **Document Lines** | No standalone | With full document |
| **Customer Addresses** | Not in partners | Manual |
| **Price Lists** | No bulk | Per product |
| **Services** | No service | Create via API/UI |
| **Vehicles** | No service | Create via API/UI |

### Bulk Operations NOT Supported

| Operation | Impact |
|-----------|--------|
| Bulk update existing | Can only create new |
| Rollback on partial fail | Failed rows don't rollback |
| File attachments | No image upload |
| Parallel imports | One at a time |
| Custom validation | Fixed rule set |

---

## 8. Database Schema

### import_jobs

```sql
id UUID PRIMARY KEY
tenant_id, user_id UUID
type ENUM (partners, products, stock_levels, opening_balances)
status ENUM (pending, validating, validated, importing, completed, failed)
original_filename VARCHAR
file_path VARCHAR
total_rows, processed_rows, successful_rows, failed_rows INT
column_mapping JSON
error_message TEXT
started_at, completed_at TIMESTAMP
```

### import_rows

```sql
id UUID PRIMARY KEY
import_job_id UUID REFERENCES import_jobs
row_number INT
data JSON                -- Raw CSV row
is_valid BOOLEAN
errors JSON              -- Per-field errors
is_imported BOOLEAN
imported_entity_id UUID
import_error TEXT
```

### opening_balance_batches

```sql
id UUID PRIMARY KEY
tenant_id, company_id UUID
type ENUM (ACCOUNTING, INVENTORY, AR_OPENING_ITEMS, AP_OPENING_ITEMS)
status ENUM (DRAFT, VALIDATING, VALIDATED, LOCKED)
name VARCHAR
cutover_date DATE
source_system VARCHAR   -- e.g., "SAP", "QuickBooks"
hash VARCHAR(64)        -- SHA-256 for immutability
previous_hash VARCHAR   -- Chain link
```

---

## 9. Migration Workflow

### Recommended Import Sequence

```
1. Create locations manually (no import)

2. Import partners (customers/suppliers)

3. Import products

4. Import stock levels

5. Create/seed chart of accounts

6. Import GL Opening Balances

7. Import Inventory Opening Balances

8. Import AR/AP Opening Items
```

### Dependency Checking

The MigrationWizardService checks:
- Products: Warns if no partners exist
- StockLevels: Requires products + locations
- OpeningBalances: Requires chart of accounts

---

## 10. Gaps & Recommendations

### High Priority

| Gap | Impact | Effort | Solution |
|-----|--------|--------|----------|
| Product bulk update | Can't update prices | 8h | UpdateProductImportService |
| Service import | Manual only | 4h | ServiceImportService |
| Locations import | Manual only | 4h | LocationImportService |
| Custom COA import | Tunisia only | 8h | CoAImportService |

### Medium Priority

| Gap | Impact | Effort |
|-----|--------|--------|
| Excel support | CSV only | 8h |
| Data preview | Limited visibility | 6h |
| Export functionality | No reverse | 12h |

### Enhancement Ideas

- AI-assisted data cleaning
- Duplicate detection
- Two-way sync via webhooks
- Scheduled recurring imports
- Email notifications on completion

---

## 11. File Locations

### Backend
```
apps/api/app/Modules/
├── Import/Services/ImportService.php
├── Import/Services/ValidationEngine.php
├── Import/Presentation/Controllers/ImportController.php
├── Accounting/Application/Services/AccountingOpeningService.php
├── Accounting/Application/Services/OpeningBalanceBatchService.php
├── Inventory/Application/Services/InventoryOpeningService.php
└── Document/Application/Services/ArApOpeningService.php
```

### Frontend
```
apps/web/src/features/
├── import/pages/ImportDashboardPage.tsx
├── import/pages/ImportWizardPage.tsx
└── opening-balances/pages/OpeningBalancesPage.tsx
```

### Database
```
apps/api/database/
├── migrations/2025_11_30_150000_create_import_tables.php
└── migrations/2025_12_11_100000_create_opening_balance_tables.php
```

---

**File**: `docs/live-readiness/05-IMPORTS-MODULE.md`
**Generated**: 2025-12-13
