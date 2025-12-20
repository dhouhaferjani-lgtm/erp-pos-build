# Opening Balances - Implementation Progress

> Spec: `docs/opening-balance-dev/opening_balances_spec.md`
> Plan: `/Users/houssamr/.claude/plans/cuddly-swimming-pearl.md`

## Status: In Progress

Started: 2025-12-11

---

## Phase 1: Foundation (Backend)

### 1.1 Migrations
- [x] `2025_12_11_100000_create_opening_balance_tables.php` - New tables (batches, import_rows)
- [x] `2025_12_11_100001_add_is_historical_to_tables.php` - Add boolean fields to existing tables

### 1.2 Models
- [x] `OpeningBalanceBatch.php`
- [x] `OpeningBalanceImportRow.php`

### 1.3 Enums
- [x] `OpeningBatchType.php`
- [x] `OpeningBatchStatus.php`
- [x] `OpeningImportRowStatus.php`
- [x] Update `MovementType.php` - Add `Opening` case

### 1.4 Model Updates
- [x] `JournalEntry.php` - Added `is_historical` property
- [x] `StockMovement.php` - Added `is_historical` property
- [x] `Document.php` - Added `is_historical` and `external_document_number` properties

### 1.5 Services
- [x] `OpeningBalanceBatchService.php` - Batch lifecycle management

### 1.6 API Endpoints
- [x] GET `/api/v1/opening-batches/types` - List batch types
- [x] GET `/api/v1/companies/{companyId}/opening-batches` - List batches
- [x] GET `/api/v1/companies/{companyId}/opening-batches/status` - Get status for all types
- [x] POST `/api/v1/companies/{companyId}/opening-batches` - Create batch
- [x] GET `/api/v1/companies/{companyId}/opening-batches/{batchId}` - Get batch details
- [x] DELETE `/api/v1/companies/{companyId}/opening-batches/{batchId}` - Delete draft batch
- [x] GET `/api/v1/companies/{companyId}/opening-batches/{batchId}/rows` - Get import rows (paginated)
- [x] POST `/api/v1/companies/{companyId}/opening-batches/{batchId}/lock` - Lock batch

---

## Phase 2: GL Accounting Opening

### 2.1 Service
- [x] `AccountingOpeningService.php`

### 2.2 Import & Validation
- [x] CSV import handler for GL rows
- [x] Account code validation
- [x] Debit/credit balance validation

### 2.3 Posting Logic
- [x] Journal entry creation with `is_historical = true`
- [x] Opening Balance Equity offset
- [x] Skip fiscal hash chain for historical

### 2.4 API Endpoints
- [x] POST `/api/v1/companies/{companyId}/opening-batches/{batchId}/import` - Import CSV file
- [x] POST `/api/v1/companies/{companyId}/opening-batches/{batchId}/validate` - Validate batch
- [x] GET `/api/v1/companies/{companyId}/opening-batches/{batchId}/preview` - Preview posting
- [x] POST `/api/v1/companies/{companyId}/opening-batches/{batchId}/post` - Post batch

### 2.5 Tests
- [ ] `AccountingOpeningServiceTest.php`

---

## Phase 3: Inventory Opening

### 3.1 Service
- [x] `InventoryOpeningService.php`

### 3.2 Import & Validation
- [x] CSV import handler for inventory rows
- [x] Product SKU validation
- [x] Location code validation
- [x] Quantity/cost validation

### 3.3 Posting Logic
- [x] Stock movement creation with `movement_type = 'opening'`
- [x] Stock level creation/update
- [x] Product cost_price update
- [x] GL entry: Dr. Inventory, Cr. OBE

### 3.4 Controller Integration
- [x] Validate, preview, post routes integrated with InventoryOpeningService
- [x] Import validation rules for inventory rows

### 3.5 Sales Blocking
- [ ] Check unlocked opening batches before sale
- [ ] ProductNotReadyForSaleException

### 3.6 Tests
- [ ] `InventoryOpeningServiceTest.php`

---

## Phase 4: AR/AP Open Items

### 4.1 Service
- [x] `ArApOpeningService.php`

### 4.2 Import & Validation
- [x] CSV import handler for AR/AP rows
- [x] Customer/supplier validation
- [x] Date/amount validation

### 4.3 Posting Logic
- [x] Historical document creation with `HIST-INV-XXXX` numbering
- [x] `is_historical = true`, `external_document_number` optional
- [x] Balance_due set to open amount

### 4.4 Controller Integration
- [x] Validate, preview, post routes integrated with ArApOpeningService
- [x] Import validation rules for AR/AP rows

### 4.5 Payment Integration
- [ ] Verify payment allocation works against historical invoices

### 4.6 Tests
- [ ] `ArApOpeningServiceTest.php`

---

## Phase 5: Frontend Wizard

### 5.1 Components
- [ ] `OpeningBalanceWizard.tsx`
- [ ] `BatchTypeSelector.tsx`
- [ ] `FileUploader.tsx`
- [ ] `ValidationResults.tsx`
- [ ] `BatchPreview.tsx`
- [ ] `LockConfirmation.tsx`

### 5.2 API Integration
- [ ] Batch CRUD hooks
- [ ] File upload hook
- [ ] Validation/preview hooks

### 5.3 Translations
- [ ] Add keys to `en/common.json`
- [ ] Add keys to `fr/common.json`

---

## Files Created

| File | Status | Notes |
|------|--------|-------|
| `database/migrations/2025_12_11_100000_create_opening_balance_tables.php` | ✅ | New tables |
| `database/migrations/2025_12_11_100001_add_is_historical_to_tables.php` | ✅ | Add fields |
| `app/Modules/Accounting/Domain/OpeningBalanceBatch.php` | ✅ | Model |
| `app/Modules/Accounting/Domain/OpeningBalanceImportRow.php` | ✅ | Model |
| `app/Modules/Accounting/Domain/Enums/OpeningBatchType.php` | ✅ | Enum |
| `app/Modules/Accounting/Domain/Enums/OpeningBatchStatus.php` | ✅ | Enum |
| `app/Modules/Accounting/Domain/Enums/OpeningImportRowStatus.php` | ✅ | Enum |
| `app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php` | ✅ | Service |
| `app/Modules/Accounting/Application/Services/AccountingOpeningService.php` | ✅ | GL Opening Service |
| `app/Modules/Inventory/Application/Services/InventoryOpeningService.php` | ✅ | Inventory Opening Service |
| `app/Modules/Document/Application/Services/ArApOpeningService.php` | ✅ | AR/AP Opening Service |
| `app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php` | ✅ | API Controller |

---

## Files Modified

| File | Status | Notes |
|------|--------|-------|
| `app/Modules/Inventory/Domain/Enums/MovementType.php` | ✅ | Added `Opening` case |
| `app/Modules/Accounting/Domain/JournalEntry.php` | ✅ | Added `is_historical` |
| `app/Modules/Inventory/Domain/StockMovement.php` | ✅ | Added `is_historical` |
| `app/Modules/Document/Domain/Document.php` | ✅ | Added `is_historical`, `external_document_number` |
| `app/Modules/Accounting/Presentation/routes.php` | ✅ | Added opening balance routes |
| `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php` | ✅ | Added OBE to required purposes |

---

## Design Decisions

### Historical Document Numbering
- Historical documents get OUR system-generated number: `HIST-INV-2025-00001`
- `external_document_number` is optional metadata (reference to old system)
- `document_number` is ALWAYS required (no nullable)

### Audit Hardening
- No new nullable FK columns on core tables
- Use existing `source_type`/`source_id` pattern
- `is_historical` boolean with default false (not nullable)

### Sales Protection
- Products cannot be sold if their opening stock is from an unlocked batch
- Enforced at document line creation time

---

## Testing Checklist

- [ ] Migration runs without errors
- [ ] Models have proper relationships
- [ ] Batch lifecycle (create → validate → lock) works
- [ ] GL opening creates proper journal entries
- [ ] Inventory opening creates stock movements + GL entries
- [ ] AR/AP opening creates historical documents
- [ ] Payment allocation works against historical invoices
- [ ] Sales blocked for products with unlocked opening batches
- [ ] Fiscal hash chain excludes historical entries
