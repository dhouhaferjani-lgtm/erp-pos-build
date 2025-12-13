# Live Readiness Implementation Plan

> Comprehensive implementation plan covering all findings from the live-readiness audit.
> **Progress Tracking**: Update status after each task completion and push to GitHub

---

## Overall Status: 78% Ready

| Area | Score | Key Blockers |
|------|-------|--------------|
| Backend Business Flow | 85% | COGS automation missing |
| Frontend Features | 72% | i18n fixes, missing settings |
| Data Quality | 75% | Currency hardcoding |
| Translations | 70% | 50+ hardcoded strings |
| Import Capabilities | 80% | Core features complete |
| Treasury/Payments | 75% | Refund UI missing |
| Audit/Compliance | 85% | Lifecycle events partial |

---

## Phase 1: Critical Fixes (Week 1)

### 1.1 Fix Currency Hardcoding [HIGH PRIORITY]
**Status**: [ ] Not Started
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
**Status**: [ ] Not Started
**Effort**: 30min

**Issue**: MarginService.php uses 10.0 as minimum margin default, but CompanyFactory uses 15.0

**File**: `apps/api/app/Modules/Inventory/Application/Services/MarginService.php`
**Line**: 42
**Fix**: Change `?? 10.0` to `?? 15.0`

---

### 1.3 Add Missing French Translations [HIGH PRIORITY]
**Status**: [ ] Not Started
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
**Status**: [ ] Not Started
**Effort**: 4h

**File**: `apps/web/src/features/settings/SettingsPage.tsx`

Hardcoded strings to translate:
- "Manage your application settings and configuration"
- Section titles: "Users", "Roles & Permissions", "Company", "Data Import", "Opening Balances"
- Section descriptions: "Manage user accounts...", "Configure roles...", etc.
- "Application Info", "Version", "Environment", "API URL", "Build Date"

**Fix**: Create translation keys in `settings` namespace and use `t()` function.

---

### 1.5 Translate UsersPage Strings [HIGH PRIORITY]
**Status**: [ ] Not Started
**Effort**: 1h

**File**: `apps/web/src/features/settings/UsersPage.tsx`

Hardcoded strings:
- "User Management"
- "Add User"
- "Add New User"
- "An invitation email will be sent..."

---

### 1.6 Translate AuditLogsPage Strings [MEDIUM PRIORITY]
**Status**: [ ] Not Started
**Effort**: 30min

**File**: `apps/web/src/features/admin/AuditLogsPage.tsx`

Hardcoded strings:
- "Loading audit logs..."
- "Audit Logs"
- "Track all administrative actions"
- Table headers: "Date", "Admin", "Action", "Tenant", "Notes"
- "No audit logs yet"

---

## Phase 2: New Features (Week 2)

### 2.1 Document Attachments
**Status**: [ ] Not Started
**Effort**: 8h

Allow file uploads on PO, Goods Receipt, SO, Invoice.

#### Backend Tasks:
- [ ] Create migration `create_document_attachments_table.php`
- [ ] Create `DocumentAttachment` model in `Media` module
- [ ] Create `AttachmentService` with upload/download/delete
- [ ] Create `AttachmentController` with routes
- [ ] Add validation: 10MB max, PDF/images/docs only

**New Files**:
```
apps/api/database/migrations/2025_12_13_*_create_document_attachments_table.php
apps/api/app/Modules/Media/Domain/DocumentAttachment.php
apps/api/app/Modules/Media/Application/Services/AttachmentService.php
apps/api/app/Modules/Media/Presentation/Controllers/AttachmentController.php
apps/api/app/Modules/Media/Presentation/Requests/UploadAttachmentRequest.php
```

#### Frontend Tasks:
- [ ] Create `AttachmentUpload.tsx` drag & drop component
- [ ] Create `AttachmentList.tsx` display component
- [ ] Create `useAttachments.ts` hook
- [ ] Integrate into `DocumentDetailPage.tsx`

**New Files**:
```
apps/web/src/features/documents/components/DocumentAttachments.tsx
apps/web/src/features/documents/components/AttachmentUpload.tsx
apps/web/src/features/documents/components/AttachmentList.tsx
apps/web/src/features/documents/hooks/useAttachments.ts
apps/web/src/features/documents/api/attachments.ts
```

---

### 2.2 Related Documents Tab
**Status**: [ ] Not Started
**Effort**: 4h

Show document lifecycle chain (Quote -> Order -> DN -> Invoice -> Credit Note).

#### Backend Tasks:
- [ ] Add `childDocuments()` relationship to Document model
- [ ] Create `getDocumentChain()` method for full traversal
- [ ] Add `/documents/{id}/related` endpoint

**Files to modify**:
```
apps/api/app/Modules/Document/Domain/Document.php
apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php
```

#### Frontend Tasks:
- [ ] Create `RelatedDocumentsTab.tsx` component
- [ ] Add new tab to `DocumentDetailPage.tsx`
- [ ] Show source document (ancestor) and child documents (descendants)

**New Files**:
```
apps/web/src/features/documents/components/RelatedDocumentsTab.tsx
apps/web/src/features/documents/components/DocumentChainItem.tsx
```

---

### 2.3 Supplier Invoice Reference Field
**Status**: [ ] Not Started
**Effort**: 2h

Allow entering supplier invoice number on Purchase Orders.

#### Backend Tasks:
- [ ] Add migration for `external_reference` and `external_reference_date` columns
- [ ] Update `DocumentData` DTO

**Files**:
```
apps/api/database/migrations/2025_12_13_*_add_external_reference_to_documents.php
apps/api/app/Modules/Document/Application/DTOs/DocumentData.php
```

#### Frontend Tasks:
- [ ] Add fields to `DocumentForm.tsx` for PO type
- [ ] Add translations

---

### 2.4 Payment Refund UI
**Status**: [ ] Not Started
**Effort**: 8h

Backend exists (`PaymentRefundService`), need frontend.

#### Tasks:
- [ ] Create `RefundPaymentModal.tsx` component
- [ ] Add refund button to `PaymentDetailPage.tsx`
- [ ] Show refund history on payment detail
- [ ] Add translations

---

## Phase 3: Frontend Enhancements (Week 3)

### 3.1 Role Creation UI
**Status**: [ ] Not Started
**Effort**: 8h

Currently read-only. Need full CRUD.

**File**: `apps/web/src/features/settings/RolesPage.tsx`

Tasks:
- [ ] Create role creation modal
- [ ] Permission matrix editor
- [ ] Edit existing role
- [ ] Delete role (with confirmation)

---

### 3.2 Bank Reconciliation Page
**Status**: [ ] Not Started
**Effort**: 12h

**New File**: `apps/web/src/features/treasury/BankReconciliationPage.tsx`

Tasks:
- [ ] List unreconciled transactions
- [ ] Match with bank statement entries
- [ ] Mark as reconciled
- [ ] Update repository balance

---

### 3.3 Split Payment UI
**Status**: [ ] Not Started
**Effort**: 6h

Backend exists (`MultiPaymentService`), need frontend.

Tasks:
- [ ] Create split payment modal
- [ ] Allow multiple payment methods per transaction
- [ ] Show split breakdown

---

### 3.4 Translate ReportsPage Labels
**Status**: [ ] Not Started
**Effort**: 45min

**File**: `apps/web/src/features/reports/ReportsPage.tsx`

Hardcoded labels passed as props:
- "Quotes", "Sales Orders", "Invoices", "Total Documents"
- "Customers", "Suppliers", "Total Products", "Low Stock"
- "Total Invoiced", "Total Collected", "Outstanding", "Quotes Pending"

---

### 3.5 Translate Document Form Labels
**Status**: [ ] Not Started
**Effort**: 30min

**File**: `apps/web/src/features/documents/DocumentForm.tsx`

Select options to translate:
- "Select type", "Quote", "Sales Order", "Invoice", etc.

---

### 3.6 Translate Stock Action Titles
**Status**: [ ] Not Started
**Effort**: 15min

**File**: `apps/web/src/features/inventory/StockLevelsPage.tsx`

Action titles:
- "Receive stock", "Issue stock", "Adjust stock", "Transfer stock"

---

## Phase 4: Backend Improvements

### 4.1 COGS Posting Automation
**Status**: [ ] Not Started
**Effort**: 4h

**Issue**: COGS not automatically posted when invoice confirmed.

**Tasks**:
- [ ] Create event listener for `InvoicePosted` event
- [ ] Calculate COGS from WAC x quantity
- [ ] Create GL entry: Dr. COGS (601), Cr. Inventory (37)

**Files**:
```
apps/api/app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php
apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
```

---

### 4.2 Document Lifecycle Events
**Status**: [ ] Not Started
**Effort**: 4h

**Issue**: Quote->Order conversion not audited.

**Tasks**:
- [ ] Create `DocumentConverted` event
- [ ] Fire on all conversion operations
- [ ] Include source_type, target_type, user_id
- [ ] Persist to `audit_events` table

**New Files**:
```
apps/api/app/Modules/Document/Domain/Events/DocumentConverted.php
```

---

## Phase 5: Documentation (Post-Fork Spec)

### 5.1 Batch/Expiry Tracking Specification
**Status**: [ ] Not Started
**Effort**: 2h

Create detailed spec for post-fork implementation.

**New File**: `docs/features/BATCH-EXPIRY-TRACKING.md`

Contents:
- FEFO (First Expired First Out) algorithm
- Database schema: `stock_batches`, `batch_allocations`
- API endpoints specification
- UI mockup descriptions
- Pharmacy/auto parts requirements
- Estimated effort: 3-4 weeks

---

## Progress Tracking

### Completion Checklist

#### Phase 1 - Critical Fixes
- [ ] 1.1 Fix currency hardcoding (8 components)
- [ ] 1.2 Fix margin default inconsistency
- [ ] 1.3 Add missing French translations
- [ ] 1.4 Translate SettingsPage
- [ ] 1.5 Translate UsersPage
- [ ] 1.6 Translate AuditLogsPage

#### Phase 2 - New Features
- [ ] 2.1 Document attachments (backend)
- [ ] 2.1 Document attachments (frontend)
- [ ] 2.2 Related documents tab (backend)
- [ ] 2.2 Related documents tab (frontend)
- [ ] 2.3 Supplier invoice reference
- [ ] 2.4 Payment refund UI

#### Phase 3 - Frontend Enhancements
- [ ] 3.1 Role creation UI
- [ ] 3.2 Bank reconciliation page
- [ ] 3.3 Split payment UI
- [ ] 3.4 Translate ReportsPage
- [ ] 3.5 Translate DocumentForm
- [ ] 3.6 Translate stock actions

#### Phase 4 - Backend Improvements
- [ ] 4.1 COGS posting automation
- [ ] 4.2 Document lifecycle events

#### Phase 5 - Documentation
- [ ] 5.1 Batch/expiry tracking spec

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
**Last Updated**: 2025-12-13
