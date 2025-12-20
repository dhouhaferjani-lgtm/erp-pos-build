# Pre-Fork Implementation Plan

> Essential features required before forking the codebase.
> These features are generic (not automobile-specific) and apply to all verticals.

---

## Current Status Summary

| Feature Area | Status | Notes |
|--------------|--------|-------|
| PDF Generation | NOT STARTED | No dompdf or PDF library installed |
| Document Email | NOT STARTED | Mail config exists, no Mailable classes |
| Excel Import | NOT STARTED | Only CSV parsing exists |
| Bank Statement Import | NOT STARTED | No OFX/CSV bank import |
| Export (PDF/CSV/Excel) | NOT STARTED | No export services |
| Document Print Templates | NOT STARTED | No template system |
| Settings Verification | NEEDS REVIEW | Backend complete, frontend needs audit |

**Last Updated**: 2025-12-14

---

## Phase 1: PDF Generation & Document Templates (Priority: HIGH)

### 1.1 Install PDF Library

**Effort**: 1h

```bash
cd apps/api
composer require barryvdh/laravel-dompdf
```

**Files to create**:
- `config/dompdf.php` (publish config)

---

### 1.2 Create Document Template System

**Effort**: 8h

The template system should support:
- Company branding (logo, colors, address)
- Country-specific formatting (date format, currency, tax display)
- Document-type specific layouts (invoice vs. quote vs. delivery note)

#### Backend Tasks

**Create Template Service**:
```
apps/api/app/Modules/Document/Application/Services/DocumentPdfService.php
```

This service will:
- Load the appropriate Blade template based on document type
- Inject company branding and settings
- Generate PDF using dompdf
- Return PDF stream or file path

**Create Base Blade Templates**:
```
apps/api/resources/views/documents/
├── templates/
│   ├── invoice.blade.php
│   ├── quote.blade.php
│   ├── sales_order.blade.php
│   ├── delivery_note.blade.php
│   ├── purchase_order.blade.php
│   ├── credit_note.blade.php
│   └── receipt.blade.php
├── components/
│   ├── header.blade.php
│   ├── footer.blade.php
│   ├── line_items.blade.php
│   ├── totals.blade.php
│   └── payment_info.blade.php
└── layouts/
    └── document.blade.php
```

**Create PDF Controller**:
```
apps/api/app/Modules/Document/Presentation/Controllers/DocumentPdfController.php
```

Routes:
- `GET /documents/{id}/pdf` - Download PDF
- `GET /documents/{id}/pdf/preview` - Stream PDF for preview
- `POST /documents/{id}/pdf/email` - Email PDF to customer

---

### 1.3 Country-Specific Template Variations

**Effort**: 4h

Create country-specific template overrides for legal requirements:

```
apps/api/resources/views/documents/country/
├── TN/
│   ├── invoice.blade.php      # Tunisia-specific (Arabic/French, timbre fiscal)
│   └── delivery_note.blade.php
├── FR/
│   └── invoice.blade.php      # France-specific (Factur-X mention)
└── IT/
    └── delivery_note.blade.php # Italy-specific (DDT format)
```

**Template Selection Logic**:
1. Check if `resources/views/documents/country/{country_code}/{type}.blade.php` exists
2. Fall back to `resources/views/documents/templates/{type}.blade.php`

---

### 1.4 Frontend PDF Integration

**Effort**: 4h

**Add PDF buttons to DocumentDetailPage.tsx**:
- "Download PDF" button
- "Print" button (opens PDF in new tab)
- "Email" button (opens email modal)

**Create hooks**:
```
apps/web/src/features/documents/hooks/useDocumentPdf.ts
```

---

## Phase 2: Email Functionality (Priority: HIGH)

### 2.1 Create Document Mailable Classes

**Effort**: 4h

**Files to create**:
```
apps/api/app/Modules/Communication/
├── Domain/
│   └── Mail/
│       ├── DocumentMailable.php        # Base class
│       ├── InvoiceMailable.php
│       ├── QuoteMailable.php
│       ├── DeliveryNoteMailable.php
│       └── PaymentReceiptMailable.php
├── Application/
│   └── Services/
│       └── DocumentEmailService.php
└── Presentation/
    └── Controllers/
        └── DocumentEmailController.php
```

**Email Templates**:
```
apps/api/resources/views/emails/documents/
├── invoice.blade.php
├── quote.blade.php
├── delivery_note.blade.php
└── payment_receipt.blade.php
```

---

### 2.2 Email Service Implementation

**Effort**: 3h

```php
class DocumentEmailService
{
    public function sendDocument(
        Document $document,
        string $recipientEmail,
        ?string $subject = null,
        ?string $message = null,
        bool $attachPdf = true,
    ): void;

    public function getDefaultSubject(Document $document): string;
    public function getDefaultMessage(Document $document): string;
}
```

---

### 2.3 Frontend Email Modal

**Effort**: 3h

**Create component**:
```
apps/web/src/features/documents/components/EmailDocumentModal.tsx
```

Features:
- Pre-filled recipient (from partner email)
- Editable subject line (with default based on document type)
- Message body textarea
- Checkbox to attach PDF
- Send button with loading state

---

## Phase 3: Excel Import (Priority: HIGH)

### 3.1 Install PhpSpreadsheet

**Effort**: 1h

```bash
cd apps/api
composer require phpoffice/phpspreadsheet
```

Or use Laravel Excel wrapper:
```bash
composer require maatwebsite/excel
```

---

### 3.2 Create Excel Import Service

**Effort**: 6h

**Files to create**:
```
apps/api/app/Modules/Import/
├── Domain/
│   ├── Enums/
│   │   └── ImportFormat.php           # CSV, XLSX, XLS
│   └── Contracts/
│       └── ImportParserInterface.php
├── Application/
│   └── Services/
│       ├── ImportService.php
│       ├── CsvParser.php
│       └── ExcelParser.php
└── Presentation/
    └── Controllers/
        └── ImportController.php
```

**Import Service Features**:
- Auto-detect file format (CSV vs Excel)
- Handle multiple sheets in Excel
- Column mapping wizard support
- Validate row data
- Return structured errors per row

---

### 3.3 Update Opening Balance Import

**Effort**: 4h

Modify existing opening balance import to support Excel:

**Files to modify**:
- `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php`
- `apps/web/src/features/opening-balances/components/FileUpload.tsx`

Changes:
- Accept `.xlsx`, `.xls` in addition to `.csv`
- Use ImportService for parsing
- Show sheet selector for Excel files with multiple sheets

---

### 3.4 Create Product/Partner Import

**Effort**: 6h

**Backend**:
```
apps/api/app/Modules/Import/Application/Services/
├── ProductImportService.php
└── PartnerImportService.php
```

**Frontend**:
```
apps/web/src/features/import/
├── pages/
│   └── DataImportPage.tsx
├── components/
│   ├── ImportWizard.tsx
│   ├── ColumnMapper.tsx
│   ├── ImportPreview.tsx
│   └── ImportResults.tsx
└── hooks/
    └── useImport.ts
```

Import wizard flow:
1. Select data type (Products, Partners, Opening Balances)
2. Upload file (CSV or Excel)
3. Map columns to fields
4. Preview data with validation
5. Import with progress indicator
6. Show results (success/error counts)

---

## Phase 4: Bank Statement Import (Priority: MEDIUM)

### 4.1 Create Bank Statement Parser

**Effort**: 6h

**Files to create**:
```
apps/api/app/Modules/Treasury/Application/Services/
├── BankStatementImportService.php
├── Parsers/
│   ├── CsvBankStatementParser.php
│   └── OfxBankStatementParser.php
└── DTOs/
    └── BankStatementLineData.php
```

**Supported formats**:
- CSV (configurable column mapping)
- OFX (Open Financial Exchange - standard bank format)

---

### 4.2 Bank Statement Import UI

**Effort**: 4h

**Create pages**:
```
apps/web/src/features/treasury/
├── pages/
│   └── BankStatementImportPage.tsx
├── components/
│   └── BankStatementImportWizard.tsx
└── hooks/
    └── useBankStatementImport.ts
```

Import wizard:
1. Select bank account
2. Upload statement file
3. Map columns (for CSV)
4. Preview transactions
5. Auto-match with existing payments (optional)
6. Import

---

### 4.3 Bank Reconciliation Enhancement

**Effort**: 4h

Enhance existing `BankReconciliationPage.tsx` to:
- Show imported statement lines
- Auto-suggest matches
- Manual matching interface
- Create payments from unmatched statement lines

---

## Phase 5: Export Functionality (Priority: MEDIUM)

### 5.1 Create Export Service

**Effort**: 6h

**Files to create**:
```
apps/api/app/Modules/Export/
├── Application/
│   └── Services/
│       ├── ExportService.php
│       ├── Exporters/
│       │   ├── DocumentExporter.php
│       │   ├── ProductExporter.php
│       │   ├── PartnerExporter.php
│       │   ├── PaymentExporter.php
│       │   └── JournalEntryExporter.php
│       └── Formatters/
│           ├── CsvFormatter.php
│           ├── ExcelFormatter.php
│           └── PdfFormatter.php
└── Presentation/
    └── Controllers/
        └── ExportController.php
```

---

### 5.2 Export API Endpoints

**Effort**: 2h

```
GET /export/documents?format=csv&filters[type]=invoice&filters[date_from]=2024-01-01
GET /export/products?format=xlsx
GET /export/partners?format=csv&filters[type]=customer
GET /export/payments?format=xlsx&filters[date_from]=2024-01-01
GET /export/journal-entries?format=csv&filters[period]=2024-Q1
```

---

### 5.3 Frontend Export Buttons

**Effort**: 4h

Add export buttons to list pages:
- `ProductListPage.tsx` - Export products
- `PartnerListPage.tsx` - Export customers/suppliers
- `DocumentListPage.tsx` - Export documents
- `PaymentListPage.tsx` - Export payments
- `JournalEntriesPage.tsx` - Export journal entries

**Create shared component**:
```
apps/web/src/components/ui/ExportButton.tsx
```

Features:
- Format selector (CSV, Excel, PDF)
- Applies current filters
- Shows progress for large exports
- Downloads file on complete

---

## Phase 6: Settings Verification (Priority: MEDIUM)

### 6.1 Audit Company Settings

**Effort**: 4h

Review and test all company settings in `CompanyPage.tsx`:

| Setting | Backend | Frontend | Notes |
|---------|---------|----------|-------|
| Company name | | | |
| Legal name | | | |
| Tax ID | | | |
| Registration number | | | |
| Country code | | | |
| Currency | | | Verify it propagates correctly |
| Timezone | | | |
| Date format | | | |
| Locale | | | |
| Logo upload | | | |
| Primary color | | | |
| Address fields | | | |
| Invoice prefix | | | |
| Quote prefix | | | |
| Fiscal year start | | | |
| Target margin | | | |
| Minimum margin | | | |
| Payment tolerance | | | |

---

### 6.2 Add Missing Settings UI

**Effort**: 4h

Settings that exist in backend but may be missing from frontend:
- `inventory_costing_method` (should be read-only after first transaction)
- `default_target_margin` / `default_minimum_margin`
- `allow_below_cost_sales`
- `payment_tolerance_enabled` / `payment_tolerance_percentage`
- Document number prefixes (invoice, quote, SO, PO, DN)

---

### 6.3 Create Settings Validation

**Effort**: 2h

Ensure settings changes are validated:
- Cannot change country_code after documents exist
- Cannot change currency after transactions exist
- Margin settings must be valid percentages
- Payment tolerance must be 0-100%

---

## Implementation Order

### Sprint 1: Core PDF & Templates (Week 1)
1. [1.1] Install dompdf
2. [1.2] Create template system (base templates)
3. [1.4] Frontend PDF integration
4. [2.3] Email modal component

### Sprint 2: Email & Excel (Week 2)
5. [2.1] Document Mailable classes
6. [2.2] Email service
7. [3.1] Install PhpSpreadsheet
8. [3.2] Excel import service
9. [3.3] Update opening balance import for Excel

### Sprint 3: Data Import & Export (Week 3)
10. [3.4] Product/Partner import wizard
11. [5.1] Export service
12. [5.2] Export API endpoints
13. [5.3] Frontend export buttons

### Sprint 4: Bank & Settings (Week 4)
14. [4.1] Bank statement parser
15. [4.2] Bank statement import UI
16. [4.3] Bank reconciliation enhancement
17. [6.1] Settings audit
18. [6.2] Missing settings UI
19. [1.3] Country-specific templates (can be ongoing)

---

## Effort Summary

| Phase | Hours |
|-------|-------|
| Phase 1: PDF Generation & Templates | 17h |
| Phase 2: Email Functionality | 10h |
| Phase 3: Excel Import | 17h |
| Phase 4: Bank Statement Import | 14h |
| Phase 5: Export Functionality | 12h |
| Phase 6: Settings Verification | 10h |
| **Total** | **80h** |

---

## Dependencies & Prerequisites

### Composer Packages to Install
```bash
composer require barryvdh/laravel-dompdf
composer require maatwebsite/excel
```

### NPM Packages (if needed)
- `xlsx` for client-side Excel preview (optional)

---

## Testing Checklist

### PDF Generation
- [ ] Invoice PDF renders correctly with company logo
- [ ] All document types have working templates
- [ ] Country-specific templates load when applicable
- [ ] PDF download works from document detail page
- [ ] Print (open in new tab) works

### Email
- [ ] Invoice email sends with PDF attachment
- [ ] Quote email works
- [ ] Email uses partner's email as default recipient
- [ ] Custom message body included in email

### Excel Import
- [ ] CSV import still works (regression)
- [ ] Excel (.xlsx) import works
- [ ] Multi-sheet Excel shows sheet selector
- [ ] Column mapping works correctly
- [ ] Validation errors show per row
- [ ] Large file import (1000+ rows) completes

### Bank Statement Import
- [ ] CSV bank statement imports
- [ ] OFX file imports
- [ ] Auto-matching suggests correct payments
- [ ] Manual matching works
- [ ] Unmatched lines can create new payments

### Export
- [ ] Document list exports to CSV
- [ ] Document list exports to Excel
- [ ] Products export works
- [ ] Partners export works
- [ ] Filters are applied to export

### Settings
- [ ] All company settings save correctly
- [ ] Currency change blocked after transactions
- [ ] Margin settings apply to new products
- [ ] Document prefixes work on new documents

---

**Generated**: 2025-12-14
