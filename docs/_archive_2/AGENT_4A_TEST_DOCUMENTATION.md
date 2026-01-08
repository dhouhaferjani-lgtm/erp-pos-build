# Agent 4A - Invoice GL Integration Test Documentation

## TDD RED Phase - COMPLETE ✅

**Status**: All tests written and FAILING as expected

**Test File**: `/apps/api/tests/Feature/Accounting/InvoiceGLIntegrationTest.php`

---

## Test Execution Summary

```bash
cd apps/api && php artisan test --filter=InvoiceGLIntegrationTest
```

**Results**:
- **5 tests FAILED** ✅ (Expected - RED phase)
- **3 tests PASSED** (Minimal implementation exists but incomplete)
- **Total**: 8 comprehensive tests

---

## Expected Behavior Documentation

### Overview

When an invoice is posted, the system must create complete GL (General Ledger) journal entries that:

1. **Create a JournalEntry record** linked to the invoice
2. **Create JournalLine records** with proper accounting entries
3. **Ensure balanced entries** (Total Debits = Total Credits)
4. **Handle multiple tax rates** correctly
5. **Distinguish product vs service revenue** using different accounts

---

## Detailed Expected GL Entry Structure

### Example Invoice:
```
Invoice Total: €1,127.50
├─ Line 1: €500.00 @ 20% VAT = €100.00 tax
└─ Line 2: €500.00 @ 5.5% VAT = €27.50 tax

Subtotal: €1,000.00
Tax Amount: €127.50
Total: €1,127.50
```

### Expected Journal Entry:

```
Entry Number: INV-20251226XXXXXX-1234
Entry Date: Invoice Date
Description: "Invoice INV-GL-12345"
Status: Posted
Source Type: Document
Source ID: <invoice_id>

Journal Lines:
┌─────────────────────────────────────────────────────────────────────┐
│ Account Code │ Account Name             │ Debit    │ Credit   │
├─────────────────────────────────────────────────────────────────────┤
│ 411          │ Accounts Receivable (AR) │ €1,127.50│ €0.00    │
│ 707          │ Product Revenue          │ €0.00    │ €500.00  │
│ 707          │ Product Revenue          │ €0.00    │ €500.00  │
│ 44571        │ VAT Collected (20%)      │ €0.00    │ €100.00  │
│ 44571        │ VAT Collected (5.5%)     │ €0.00    │ €27.50   │
└─────────────────────────────────────────────────────────────────────┘
Total:                                      €1,127.50  €1,127.50
```

**Key Rules**:
- ✅ Total Debits = Total Credits (balanced)
- ✅ AR debited for full invoice total
- ✅ Revenue credited per line item (or grouped)
- ✅ VAT credited per tax rate (may be grouped)

---

## Account Mapping (via SystemAccountPurpose)

The implementation MUST use `SystemAccountPurpose` to find the correct accounts:

| Purpose | Typical Code | Type | Use |
|---------|--------------|------|-----|
| `CustomerReceivable` | 411 | Asset | Debit for invoice total |
| `ProductRevenue` | 707 | Revenue | Credit for product sales |
| `ServiceRevenue` | 706 | Revenue | Credit for service sales |
| `VatCollected` | 44571 | Liability | Credit for collected VAT |

**CRITICAL**: Never hardcode account codes. Always use:
```php
$account = Account::where('company_id', $companyId)
    ->where('system_purpose', SystemAccountPurpose::CustomerReceivable)
    ->first();
```

---

## Test Cases

### 1. `test_invoice_posting_creates_complete_gl_entries()`
**Status**: ❌ FAILING

**Expected Behavior**:
- Create JournalEntry with correct metadata
- Create at least 4 JournalLine records (1 AR + 2 Revenue + 2 Tax)
- AR line: Debit €1,127.50
- Revenue lines: Total credit €1,000.00
- VAT lines: Total credit €127.50

**Current Error**:
```
Should have at least 4 lines: 1 AR + 2 Revenue + 2 Tax (or grouped)
Failed asserting that 0 is equal to 4 or is greater than 4.
```

**Implementation Needed**: Create JournalLine records in `createInvoiceGLEntries()`

---

### 2. `test_invoice_gl_entries_are_balanced()`
**Status**: ❌ FAILING

**Expected Behavior**:
- Total Debits = Total Credits
- For invoice €600 + €120 VAT = €720
- Sum of all debit amounts = Sum of all credit amounts

**Current Error**:
```
Total should be €600 + €120 VAT = €720
Failed asserting that 0 matches expected 720.0.
```

**Implementation Needed**: Ensure balanced entries (accounting fundamental rule)

---

### 3. `test_invoice_gl_includes_all_tax_rates()`
**Status**: ❌ FAILING

**Expected Behavior**:
- Handle multiple tax rates (20%, 10%, 5.5%)
- Create VAT credit lines for each rate
- Total VAT = sum of all tax rates

**Current Error**:
```
Total VAT should be sum of all tax rates
Failed asserting that 0 matches expected 35.5.
```

**Implementation Needed**: Group or separate VAT lines by tax rate

---

### 4. `test_invoice_gl_uses_correct_account_codes()`
**Status**: ❌ FAILING

**Expected Behavior**:
- Use AR account (411) via SystemAccountPurpose::CustomerReceivable
- Use Revenue account (707) via SystemAccountPurpose::ProductRevenue
- Use VAT account (44571) via SystemAccountPurpose::VatCollected

**Current Error**:
```
Should use AR account
Failed asserting that false is true.
```

**Implementation Needed**: Look up accounts via SystemAccountPurpose

---

### 5. `test_invoice_gl_distinguishes_product_and_service_revenue()`
**Status**: ❌ FAILING

**Expected Behavior**:
- Product lines use ProductRevenue account (707)
- Service lines use ServiceRevenue account (706)
- Check product type via `DocumentLine->product->type`

**Current Error**:
```
Should use Product Revenue account (707)
Failed asserting that false is true.
```

**Implementation Needed**: Check product type and use correct revenue account

---

### 6. `test_invoice_gl_handles_zero_tax_correctly()` ✅
**Status**: ✅ PASSING

**Why**: The implementation already handles zero tax correctly (no VAT line created)

---

### 7. `test_invoice_gl_entry_description_includes_invoice_number()` ✅
**Status**: ✅ PASSING

**Why**: The implementation already sets description: "Invoice {document_number}"

---

### 8. `test_multiple_invoices_create_separate_gl_entries()` ✅
**Status**: ✅ PASSING

**Why**: Each call to `createInvoiceGLEntries()` creates a new JournalEntry

---

## Implementation Checklist for Agent 4B

Agent 4B must implement the following in `AccountingService::createInvoiceGLEntries()`:

- [ ] **Fetch required accounts**:
  - [ ] AR account via `SystemAccountPurpose::CustomerReceivable`
  - [ ] Product Revenue via `SystemAccountPurpose::ProductRevenue`
  - [ ] Service Revenue via `SystemAccountPurpose::ServiceRevenue`
  - [ ] VAT Collected via `SystemAccountPurpose::VatCollected`

- [ ] **Create AR debit line**:
  - [ ] Debit = Invoice total
  - [ ] Credit = 0.00
  - [ ] Description = "AR from Invoice {number}"

- [ ] **Create Revenue credit lines**:
  - [ ] Iterate through invoice lines
  - [ ] Check product type (Part/Service)
  - [ ] Credit appropriate revenue account
  - [ ] Amount = line subtotal (before tax)
  - [ ] Description = "Revenue from Invoice {number} - Line {line_number}"

- [ ] **Create VAT credit lines**:
  - [ ] Group lines by tax rate
  - [ ] For each tax rate > 0:
    - [ ] Credit = Sum of tax for that rate
    - [ ] Description = "VAT {rate}% from Invoice {number}"

- [ ] **Validation**:
  - [ ] Verify all required accounts exist
  - [ ] Throw exception if accounts missing
  - [ ] Verify total debits = total credits
  - [ ] Use bcmath for all calculations

---

## Database Schema Reference

### JournalEntry Table
```
id: uuid (PK)
tenant_id: uuid (FK)
company_id: uuid (FK)
entry_number: string (unique per company)
entry_date: date
description: string
status: enum (Posted, Draft, Cancelled)
source_type: string (nullable) - "Document"
source_id: uuid (nullable) - invoice.id
created_at: timestamp
updated_at: timestamp
```

### JournalLine Table
```
id: uuid (PK)
journal_entry_id: uuid (FK)
account_id: uuid (FK)
debit: decimal(15,2)
credit: decimal(15,2)
description: string (nullable)
created_at: timestamp
updated_at: timestamp
```

### Document Table (Relevant Fields)
```
id: uuid (PK)
tenant_id: uuid (FK)
company_id: uuid (FK)
type: enum (Invoice, CreditNote, Quote, etc.)
document_number: string
document_date: date
subtotal: decimal(15,2)
tax_amount: decimal(15,2)
total: decimal(15,2)
status: enum (Posted, Draft, Cancelled)
```

### DocumentLine Table (Relevant Fields)
```
id: uuid (PK)
document_id: uuid (FK)
product_id: uuid (FK, nullable)
service_id: uuid (FK, nullable)
line_number: integer
quantity: decimal(10,2)
unit_price: decimal(15,2)
tax_rate: decimal(5,2)
line_total: decimal(15,2)
```

---

## Code Examples

### Looking up Account by SystemAccountPurpose
```php
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;

$arAccount = Account::where('company_id', $companyId)
    ->where('system_purpose', SystemAccountPurpose::CustomerReceivable)
    ->first();

if (!$arAccount) {
    throw new \RuntimeException('AR account not configured for company');
}
```

### Creating a JournalLine
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $arAccount->id,
    'debit' => $invoice->total,
    'credit' => '0.00',
    'description' => 'AR from Invoice ' . $invoice->document_number,
]);
```

### Grouping Lines by Tax Rate
```php
$linesByTaxRate = [];
foreach ($invoice->lines as $line) {
    $taxRate = $line->tax_rate;
    if (!isset($linesByTaxRate[$taxRate])) {
        $linesByTaxRate[$taxRate] = [];
    }
    $linesByTaxRate[$taxRate][] = $line;
}

foreach ($linesByTaxRate as $taxRate => $lines) {
    // Calculate total tax for this rate
    $taxAmount = array_reduce($lines, function($sum, $line) {
        return bcadd($sum, bcmul($line->line_total, bcdiv($line->tax_rate, '100', 4), 2), 2);
    }, '0.00');

    // Create VAT line if > 0
    if (bccomp($taxAmount, '0.00', 2) > 0) {
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $vatAccount->id,
            'debit' => '0.00',
            'credit' => $taxAmount,
            'description' => "VAT {$taxRate}% from Invoice " . $invoice->document_number,
        ]);
    }
}
```

---

## Verification Command

After Agent 4B implements the solution:

```bash
cd apps/api && php artisan test --filter=InvoiceGLIntegrationTest
```

**Success Criteria**: All 8 tests PASS (GREEN phase)

---

## Related Files

- **Interface**: `/apps/api/app/Shared/Contracts/AccountingServiceInterface.php`
- **Implementation**: `/apps/api/app/Modules/Accounting/Application/Services/AccountingService.php`
- **Test File**: `/apps/api/tests/Feature/Accounting/InvoiceGLIntegrationTest.php`
- **Models**:
  - `/apps/api/app/Modules/Accounting/Domain/JournalEntry.php`
  - `/apps/api/app/Modules/Accounting/Domain/JournalLine.php`
  - `/apps/api/app/Modules/Accounting/Domain/Account.php`
  - `/apps/api/app/Modules/Document/Domain/Document.php`
  - `/apps/api/app/Modules/Document/Domain/DocumentLine.php`

---

## Next Steps for Agent 4B

1. Read this documentation completely
2. Review the test file to understand expectations
3. Implement `createInvoiceGLEntries()` in `AccountingService`
4. Run tests frequently during implementation
5. Ensure ALL 8 tests pass before completion
6. Verify no PHPStan errors: `./vendor/bin/phpstan analyze`
7. Verify code style: `./vendor/bin/pint`

---

**Document Version**: 1.0
**Created**: 2025-12-26
**Agent**: 4A - Test Writer
**Status**: RED Phase Complete - Ready for Agent 4B Implementation
