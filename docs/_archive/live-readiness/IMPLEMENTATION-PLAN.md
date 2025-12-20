# Live Readiness Implementation Plan

> Comprehensive implementation plan covering all findings from the live-readiness audit.
> **Progress Tracking**: Update status after each task completion and push to GitHub

---

## Overall Status: Phase 1 Complete - Pre-Fork Tasks Remaining

| Area | Score | Status |
|------|-------|--------|
| Backend Business Flow | 100% | COGS automation COMPLETE |
| Frontend Features | 100% | ALL COMPLETE |
| Data Quality | 100% | Currency hardcoding FIXED |
| Translations | 100% | All pages translated |
| Import Capabilities | 40% | CSV only - Excel/Bank imports required |
| Export Capabilities | 0% | PDF/CSV/Excel export NOT STARTED |
| PDF Generation | 0% | Document templates NOT STARTED |
| Email Functionality | 0% | Document emailing NOT STARTED |
| Treasury/Payments | 100% | Refund UI COMPLETE |
| Audit/Compliance | 100% | Lifecycle events COMPLETE |
| Settings Verification | 80% | Needs frontend audit |

**Last Updated**: 2025-12-14
**Status**: Phase 1 (audit tasks) complete. See [PRE-FORK-IMPLEMENTATION.md](./PRE-FORK-IMPLEMENTATION.md) for remaining tasks.

**IMPORTANT**: The following features must be completed BEFORE forking:
- PDF generation for documents (invoices, quotes, delivery notes)
- Document email functionality
- Excel import support (.xlsx)
- Bank statement import
- Export functionality (PDF, CSV, Excel)
- Settings verification

See **[PRE-FORK-IMPLEMENTATION.md](./PRE-FORK-IMPLEMENTATION.md)** for detailed implementation plan (~80h effort).

---

## Phase 1: Critical Fixes (Week 1)

### 1.1 Fix Currency Hardcoding [HIGH PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 4h

8 frontend components default to TND instead of using company context:

| File | Component | Line |
|------|-----------|------|
| `ProductPricingCard.tsx` | currency prop | 28 |
| `LandedCostBreakdown.tsx` | currency prop | 23 |
| `AdditionalCostsForm.tsx` | currency prop | 31 |
| `DocumentForm.tsx` | currency field | 416 |
| `DocumentDetailPage.tsx` | currency display | 837 |
| `PurchaseOrderAdditionalCosts.tsx` | currency prop | 23 |
| `PurchaseOrderLandedCostBreakdown.tsx` | currency prop | 19 |
| `PriceInputWithMargin.tsx` | currency prop | 47 |

**Fix**: Use company context currency via `useCompany()` hook or prop from parent.

**Files to modify**:
- `apps/web/src/features/inventory/components/ProductPricingCard.tsx`
- `apps/web/src/features/documents/components/LandedCostBreakdown.tsx`
- `apps/web/src/features/documents/components/AdditionalCostsForm.tsx`
- `apps/web/src/features/documents/DocumentForm.tsx`
- `apps/web/src/features/documents/DocumentDetailPage.tsx`
- `apps/web/src/features/purchases/components/PurchaseOrderAdditionalCosts.tsx`
- `apps/web/src/features/purchases/components/PurchaseOrderLandedCostBreakdown.tsx`
- `apps/web/src/features/inventory/components/PriceInputWithMargin.tsx`

---

### 1.2 Fix Margin Default Inconsistency [HIGH PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 30min

**Issue**: MarginService.php uses 10.0 as minimum margin default, but CompanyFactory uses 15.0

**File**: `apps/api/app/Modules/Product/Application/Services/MarginService.php`
**Line**: 42
**Fix**: Changed `?? 10.0` to `?? 15.0`

---

### 1.3 Add Missing French Translations [HIGH PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 2h

Add to `apps/web/src/locales/fr/common.json`:

```json
{
  "common": {
    "total": "total",
    "listView": "Vue en liste",
    "gridView": "Vue en grille"
  },
  "filters": {
    "all": "Tous",
    "active": "Actif",
    "inactive": "Inactif"
  },
  "tabs": {
    "overview": "Apercu",
    "documents": "Documents",
    "payments": "Paiements",
    "vehicles": "Vehicules"
  }
}
```

---

### 1.4 Translate Hardcoded Settings Page Strings [HIGH PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 4h

**File**: `apps/web/src/features/settings/SettingsPage.tsx`

Translated strings:
- Settings title and description
- Section titles: Users, Roles & Permissions, Company, Data Import, Opening Balances
- Section descriptions with full French translations
- Application Info section with all labels

---

### 1.5 Translate UsersPage Strings [HIGH PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 1h

**File**: `apps/web/src/features/settings/UsersPage.tsx`

Translated:
- All mutation success messages
- Confirmation dialogs for deactivate/delete
- AddUserModal title, labels, placeholders
- Validation error messages
- Action buttons

---

### 1.6 Translate AuditLogsPage Strings [MEDIUM PRIORITY]
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 30min

**File**: `apps/web/src/features/admin/pages/AuditLogsPage.tsx`

Translated:
- Loading state, title, description
- Table headers: Date, Admin, Action, Tenant, Notes
- Empty state message
- Full French translations in common.json

---

## Phase 2: New Features (Week 2)

### 2.1 Document Attachments
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 8h

Allow file uploads on PO, Goods Receipt, SO, Invoice.

#### Backend Tasks:
- [x] Create migration `create_document_attachments_table.php`
- [x] Create `DocumentAttachment` model in `Media` module
- [x] Create `AttachmentService` with upload/download/delete
- [x] Create `AttachmentController` with routes
- [x] Add validation: 10MB max, PDF/images/docs only

**Created Files**:
```
apps/api/database/migrations/2025_12_13_200000_create_document_attachments_table.php
apps/api/app/Modules/Media/Domain/DocumentAttachment.php
apps/api/app/Modules/Media/Application/Services/AttachmentService.php
apps/api/app/Modules/Media/Presentation/Controllers/AttachmentController.php
apps/api/app/Modules/Media/Presentation/Requests/UploadAttachmentRequest.php
apps/api/app/Modules/Media/Providers/MediaServiceProvider.php
```

#### Frontend Tasks:
- [x] Create `DocumentAttachments.tsx` component (combined drag & drop + list)
- [x] Create `useAttachments.ts` hook
- [x] Add translations (en/fr)
- [x] Integrate into `DocumentDetailPage.tsx`

**Created Files**:
```
apps/web/src/features/documents/components/DocumentAttachments.tsx
apps/web/src/features/documents/hooks/useAttachments.ts
apps/web/src/locales/en/documents.json
apps/web/src/locales/fr/documents.json
```

---

### 2.2 Related Documents Tab
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 4h

Show document lifecycle chain (Quote -> Order -> DN -> Invoice -> Credit Note).

#### Backend Tasks:
- [x] Add `childDocuments()` relationship to Document model
- [x] Create `getDocumentChain()` method for full traversal
- [x] Add `/documents/{id}/related` endpoint

**Files modified**:
```
apps/api/app/Modules/Document/Domain/Document.php
apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php
apps/api/app/Modules/Document/Presentation/routes.php
```

#### Frontend Tasks:
- [x] Create `RelatedDocumentsTab.tsx` component
- [x] Create `useRelatedDocuments.ts` hook
- [x] Add translations (en/fr)
- [x] Integrate into `DocumentDetailPage.tsx`

**Created Files**:
```
apps/web/src/features/documents/components/RelatedDocumentsTab.tsx
apps/web/src/features/documents/hooks/useRelatedDocuments.ts
```

---

### 2.3 Supplier Invoice Reference Field
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 2h

Allow entering supplier invoice number on Purchase Orders.

#### Backend Tasks:
- [x] Add migration for `external_document_date` column (external_document_number already existed)
- [x] Update Document model with fillable and cast for external_document_date
- [x] Update `DocumentData` DTO with external_document_number and external_document_date

**Created/Modified Files**:
```
apps/api/database/migrations/2025_12_14_100000_add_external_document_date_to_documents.php
apps/api/app/Modules/Document/Domain/Document.php
apps/api/app/Modules/Document/Application/DTOs/DocumentData.php
```

#### Frontend Tasks:
- [x] Add fields to `DocumentForm.tsx` for PO type (conditionally shown)
- [x] Add translations (en/fr common.json - purchases namespace)

---

### 2.4 Payment Refund UI
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

Frontend fully implemented in `PaymentDetailPage.tsx`:
- Full refund modal with reason field
- Partial refund modal with amount and reason
- Reverse payment modal
- Refund history display
- Can-refund API check
- All translations in treasury.json (en/fr)

**Files**: `apps/web/src/features/treasury/PaymentDetailPage.tsx`

---

## Phase 3: Frontend Enhancements (Week 3)

### 3.1 Role Creation UI
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

Full CRUD implemented in `RolesPage.tsx`:
- `createMutation` - Create new roles
- `updateMutation` - Edit existing roles
- `deleteMutation` - Delete roles (with user count check)
- Permission matrix editor
- Full modal UI

**File**: `apps/web/src/features/settings/RolesPage.tsx`

---

### 3.2 Bank Reconciliation Page
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

**File**: `apps/web/src/features/treasury/BankReconciliationPage.tsx`

Features:
- List unreconciled transactions
- Match with bank statement entries
- Mark as reconciled
- Update repository balance

---

### 3.3 Split Payment UI
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

Split payment UI exists:
- `apps/web/src/features/treasury/SplitPaymentForm.tsx`
- `apps/web/src/components/organisms/SplitPaymentModal/SplitPaymentModal.tsx`

Features:
- Multiple payment methods per transaction
- Split breakdown display
- Integration with DocumentDetailPage

---

### 3.4 Translate ReportsPage Labels
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 45min

**File**: `apps/web/src/features/reports/ReportsPage.tsx`

Translated labels:
- "Quotes", "Sales Orders", "Invoices", "Total Documents"
- "Customers", "Suppliers", "Total Products", "Low Stock"
- "Total Invoiced", "Total Collected", "Outstanding", "Quotes Pending"

Added translation keys to `common.json` (en/fr) under `reports` namespace.

---

### 3.5 Translate Document Form Labels
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 30min

**File**: `apps/web/src/features/documents/DocumentForm.tsx`

Translated select options:
- "Select type", "Quote", "Sales Order", "Invoice", etc.

Uses `t('sales:documents.types.quote')` etc. from sales.json namespace.

---

### 3.6 Translate Stock Action Titles
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: 15min

**File**: `apps/web/src/features/inventory/StockLevelsPage.tsx`

Translated action button titles:
- "Receive stock" -> `t('inventory:stock.receive')`
- "Issue stock" -> `t('inventory:stock.issue')`
- "Adjust stock" -> `t('inventory:stock.adjust')`
- "Transfer stock" -> `t('inventory:stock.transfer')`

Added "issue" translation key to `fr/inventory.json`.

---

## Phase 4: Backend Improvements

### 4.1 COGS Posting Automation
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

COGS automatically posted when invoice is confirmed.

**Implementation**:
- `PostCOGSOnInvoice` listener responds to `InvoicePosted` event
- Calculates COGS from WAC x quantity for physical products
- Creates GL entry: Dr. COGS (601), Cr. Inventory (37)

**Files**:
```
apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php
apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php
```

---

### 4.2 Document Lifecycle Events
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already implemented

Document conversions are fully audited.

**Implementation**:
- `DocumentConverted` event exists with full audit trail
- Tracks: source_type, target_type, user_id, timestamps
- Fired on all conversion operations
- Used by DocumentConversionService

**Files**:
```
apps/api/app/Modules/Document/Domain/Events/DocumentConverted.php
apps/api/app/Modules/Document/Domain/Services/DocumentConversionService.php
apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php
```

---

## Phase 5: Documentation (Post-Fork Spec)

### 5.1 Batch/Expiry Tracking Specification
**Status**: [x] COMPLETED (2025-12-14)
**Effort**: Already created

Comprehensive spec exists at `docs/features/batch-expiry-tracking.md` (16KB)

**Contents**:
- FEFO (First Expired First Out) algorithm with code
- Database schema: `stock_batches`, `batch_allocations`
- API endpoints specification
- Frontend component designs (BatchSelector, ExpiringBatchesWidget, etc.)
- Integration points (goods receipt, sales, adjustments, transfers)
- Configuration options
- Testing scenarios
- Estimated implementation: 3-4 weeks

---

## Progress Tracking

### Completion Checklist

#### Phase 1 - Critical Fixes (6/6 COMPLETE)
- [x] 1.1 Fix currency hardcoding (8 components) - 2025-12-14
- [x] 1.2 Fix margin default inconsistency - 2025-12-14
- [x] 1.3 Add missing French translations - 2025-12-14
- [x] 1.4 Translate SettingsPage - 2025-12-14
- [x] 1.5 Translate UsersPage - 2025-12-14
- [x] 1.6 Translate AuditLogsPage - 2025-12-14

#### Phase 2 - New Features (4/4 COMPLETE)
- [x] 2.1 Document attachments - 2025-12-14
- [x] 2.2 Related documents tab - 2025-12-14
- [x] 2.3 Supplier invoice reference - 2025-12-14
- [x] 2.4 Payment refund UI - 2025-12-14

#### Phase 3 - Frontend Enhancements (6/6 COMPLETE)
- [x] 3.1 Role creation UI - 2025-12-14
- [x] 3.2 Bank reconciliation page - 2025-12-14
- [x] 3.3 Split payment UI - 2025-12-14
- [x] 3.4 Translate ReportsPage - 2025-12-14
- [x] 3.5 Translate DocumentForm - 2025-12-14
- [x] 3.6 Translate stock actions - 2025-12-14

#### Phase 4 - Backend Improvements (2/2 COMPLETE)
- [x] 4.1 COGS posting automation - 2025-12-14
- [x] 4.2 Document lifecycle events - 2025-12-14

#### Phase 5 - Documentation (1/1 COMPLETE)
- [x] 5.1 Batch/expiry tracking spec - 2025-12-14

---

## Git Workflow

After completing each task:
1. Run tests: `php artisan test` / `pnpm test`
2. Run checks: `./vendor/bin/phpstan`, `pnpm typecheck`
3. Update this file with `[x]` for completed items
4. Commit with descriptive message
5. Push to GitHub

---

## Estimated Total Effort

| Phase | Hours |
|-------|-------|
| Phase 1: Critical Fixes | 12h |
| Phase 2: New Features | 22h |
| Phase 3: Frontend Enhancements | 28h |
| Phase 4: Backend Improvements | 8h |
| Phase 5: Documentation | 2h |
| **Total** | **72h** |

---

## Testing Checklist

### Critical Path Tests
- [ ] Full flow: Quote -> Order -> DN -> Invoice -> Payment
- [ ] Currency displays correctly per company
- [ ] French translations display on all pages
- [ ] File attachment upload/download/delete
- [ ] Related documents chain display
- [ ] Payment refund process
- [ ] COGS posting on invoice confirmation

### Multi-Tenant Tests
- [ ] Data isolation verified
- [ ] Attachment files isolated per tenant

---

**Generated**: 2025-12-13
**Last Updated**: 2025-12-14 (100% Complete)
