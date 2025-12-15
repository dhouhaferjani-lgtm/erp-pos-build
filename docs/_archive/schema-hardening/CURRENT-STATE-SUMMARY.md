# Current State Summary: Post Smart Payments

**Date:** December 10, 2025  
**Status:** Smart Payments COMPLETE ✅ | Ready for Schema Hardening  
**Last Audit:** SMART-PAYMENT-AUDIT-REPORT.md (All P1-P4 issues resolved)

---

## ✅ What's Working (MUST REMAIN FUNCTIONAL)

### **1. Payment Allocation System**

**Components:**
- `PaymentAllocationService` - Allocates payments to invoices, updates balance_due
- `PaymentToleranceService` - Handles under/over tolerance with GL writeoffs
- `PaymentController` - Orchestrates payment creation and allocation

**Features:**
- ✅ FIFO allocation (oldest invoices first)
- ✅ Due date priority allocation (overdue first)
- ✅ Manual allocation (user-specified)
- ✅ Document balance_due updates after payment
- ✅ Payment tolerance handling (configurable per country)
- ✅ Overpayment as customer advance
- ✅ Multi-invoice payment support

**API Endpoints:**
```
GET  /api/treasury/smart-payment/tolerance-settings
POST /api/treasury/smart-payment/preview-allocation
POST /api/treasury/smart-payment/apply-allocation
POST /api/treasury/payments (with allocations array)
```

**Database Tables:**
- `payments` table with `allocation_method` column
- `payment_allocations` table with `tolerance_writeoff` column
- `country_payment_settings` table
- `companies` with `payment_tolerance_*` columns

**Tests:**
- `tests/Feature/Treasury/SmartPaymentIntegrationTest.php` - 8 tests passing
- `tests/Unit/Treasury/PaymentToleranceServiceTest.php` - passing
- `tests/Unit/Treasury/PaymentAllocationServiceTest.php` - passing

---

### **2. General Ledger Integration**

**Methods Added to GeneralLedgerService:**

```php
public function createPaymentReceivedJournalEntry(
    Payment $payment,
    array $allocations
): void
{
    // Dr. Bank/Cash Account
    // Cr. Customer Receivable (per invoice)
    // Creates journal entry with sub-ledger links
}

public function createPaymentToleranceJournalEntry(
    Payment $payment,
    string $amount,
    string $type // 'expense' or 'income'
): void
{
    // Dr/Cr. Tolerance Expense/Income Account
    // Cr/Dr. Customer Receivable
}
```

**Account Purposes Added:**
- `PAYMENT_TOLERANCE_EXPENSE`
- `PAYMENT_TOLERANCE_INCOME`
- `CUSTOMER_ADVANCES` (for overpayments)

**Integration Points:**
- Payments create GL entries automatically
- Tolerance writeoffs create separate GL entries
- Customer balances refresh after GL entries
- Sub-ledger (partner_ledger_entries) linked to GL

---

### **3. Frontend Components**

**Working Components:**
- `PaymentForm.tsx` - Main payment entry form
- `PaymentAllocationForm.tsx` - Allocation method selection
- `OpenInvoicesList.tsx` - Shows unpaid invoices
- `AllocationPreview.tsx` - Preview before submission
- `ToleranceSettingsDisplay.tsx` - Shows tolerance settings

**Features:**
- ✅ Auto-generates allocation array from invoices
- ✅ Displays unpaid invoices with balances
- ✅ Shows allocation preview
- ✅ Handles errors gracefully
- ✅ Tolerance display per country

**API Integration:**
- Calls `/api/treasury/payments` with allocations
- Handles smart payment responses
- Displays validation errors

---

### **4. Enums**

**AllocationMethod:**
- `FIFO` - First in, first out
- `DUE_DATE_PRIORITY` - Overdue invoices first
- `MANUAL` - User-specified allocations

**AllocationType:**
- `PAYMENT_APPLICATION` - Standard payment allocation
- `CREDIT_NOTE_APPLICATION` - Credit note applied to invoice
- `TOLERANCE_WRITEOFF` - Tolerance writeoff adjustment

**CreditNoteReason:**
- `RETURN` - Goods returned
- `PRICE_ADJUSTMENT` - Price correction
- `DAMAGED_GOODS` - Damaged items
- `INCORRECT_INVOICE` - Invoice error
- `GOODWILL_DISCOUNT` - Customer goodwill
- `OTHER` - Other reasons

---

## 📊 Test Coverage

### **Passing Tests (MUST REMAIN PASSING):**

```bash
Tests\Feature\Treasury\SmartPaymentIntegrationTest
  ✓ it gets tolerance settings for company
  ✓ it previews fifo allocation for multiple invoices
  ✓ it previews due date allocation prioritizing overdue
  ✓ it previews manual allocation
  ✓ it handles overpayment within tolerance
  ✓ it handles underpayment within tolerance
  ✓ it applies allocation to payment
  ✓ it handles manual allocation with excess

Tests: 8 passed (46 assertions)
Duration: 2.34s
```

**Critical Test to Monitor:**
```bash
php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
```

This MUST pass after Phase 1 (schema hardening).

---

## 🏗️ Current Schema (Relevant Tables)

### **documents Table:**
```sql
CREATE TABLE documents (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL,
    partner_id BIGINT NOT NULL,
    document_type VARCHAR(50) NOT NULL, -- 'invoice', 'credit_note', 'quote'
    document_number VARCHAR(50),
    document_date DATE,
    total_amount DECIMAL(15,2),
    balance_due DECIMAL(15,2), -- CRITICAL: Updated by payments
    payment_status VARCHAR(20), -- 'unpaid', 'partial', 'paid', 'overpaid'
    currency_code CHAR(3),
    hash TEXT,
    previous_hash TEXT,
    -- More columns...
);
```

**Critical Fields:**
- `balance_due` - Updated by PaymentAllocationService
- `payment_status` - Updated when balance changes
- `hash`, `previous_hash` - Exist but not yet used (Phase 4 will use)

---

### **payments Table:**
```sql
CREATE TABLE payments (
    id BIGSERIAL PRIMARY KEY,
    company_id BIGINT NOT NULL,
    partner_id BIGINT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(50),
    allocation_method VARCHAR(50), -- 'FIFO', 'DUE_DATE_PRIORITY', 'MANUAL'
    reference_number VARCHAR(100),
    -- More columns...
);
```

---

### **payment_allocations Table:**
```sql
CREATE TABLE payment_allocations (
    id BIGSERIAL PRIMARY KEY,
    payment_id BIGINT NOT NULL,
    document_id BIGINT NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    tolerance_writeoff DECIMAL(15,2), -- NULL or writeoff amount
    allocation_type VARCHAR(50), -- 'PAYMENT_APPLICATION', etc.
    -- More columns...
);
```

---

### **country_payment_settings Table:**
```sql
CREATE TABLE country_payment_settings (
    id BIGSERIAL PRIMARY KEY,
    country_code CHAR(2) UNIQUE NOT NULL,
    payment_tolerance_enabled BOOLEAN DEFAULT false,
    payment_tolerance_percentage DECIMAL(5,2),
    payment_tolerance_amount DECIMAL(15,2),
    -- More columns...
);
```

**Seeded Countries:**
- TN (Tunisia) - 2% tolerance
- FR (France) - 1% tolerance
- IT (Italy) - 1.5% tolerance
- UK (United Kingdom) - 2% tolerance

---

## 🔧 Services Architecture

### **PaymentAllocationService:**

```php
class PaymentAllocationService
{
    /**
     * Previews allocation without saving
     * Returns array of allocations
     */
    public function previewAllocation(
        string $companyId,
        string $partnerId,
        string $amount,
        string $method, // 'FIFO', 'DUE_DATE_PRIORITY', 'MANUAL'
        ?array $manualAllocations = null
    ): array;
    
    /**
     * Applies allocation to payment
     * Updates document.balance_due (CRITICAL)
     * Creates payment_allocations records
     * Calls PartnerBalanceService
     */
    public function applyAllocation(
        Payment $payment,
        array $allocationData
    ): array;
}
```

**Critical Behavior:**
- ✅ Updates `document.balance_due` after allocation
- ✅ Updates `document.payment_status` when fully paid
- ✅ Creates GL entries via GeneralLedgerService
- ✅ Refreshes partner balances

---

### **PaymentToleranceService:**

```php
class PaymentToleranceService
{
    /**
     * Gets tolerance settings for company
     */
    public function getToleranceSettings(string $companyId): array;
    
    /**
     * Checks if amount difference is within tolerance
     */
    public function checkTolerance(
        string $companyId,
        string $invoiceAmount,
        string $paidAmount
    ): array;
    
    /**
     * Applies tolerance writeoff
     * Creates GL entry for tolerance
     * Updates document balance to zero
     */
    public function applyTolerance(
        Payment $payment,
        Document $document,
        string $amount,
        string $type // 'underpayment' or 'overpayment'
    ): void;
}
```

---

## 🎯 What's NOT Yet Implemented (Phase 4 Work)

### **Document Posting System:**
- ❌ DocumentPostingService - Seals documents, creates GL entries
- ❌ Hash chain calculation and validation
- ❌ Fiscal metadata creation (NF525, ZATCA, TSE)
- ❌ Document status transitions (DRAFT → SEALED → VOIDED)
- ❌ Immutability enforcement (Phase 1 will add triggers)

**These are separate from payments** - Documents will be sealed when created/approved, not when paid.

---

## 🚨 Critical Constraints for Schema Hardening

### **1. Balance Updates MUST Work on Sealed Documents**

When Phase 1 adds immutability triggers:
```sql
-- Trigger MUST allow this:
UPDATE documents 
SET balance_due = balance_due - payment_amount 
WHERE id = X AND status = 'SEALED';
```

**Why:** Payments need to update balance_due even after documents are sealed.

---

### **2. Smart Payment Tests MUST Pass After Phase 1**

```bash
# This MUST pass after all Phase 1 migrations:
php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
```

If this fails, schema hardening broke smart payments → STOP and fix.

---

### **3. API Contracts MUST Remain Backward Compatible**

Current API responses:
```json
{
  "id": 1,
  "document_number": "INV-001",
  "total_amount": "1000.00",
  "balance_due": "500.00",
  "payment_status": "partial"
}
```

After Phase 2, response ADDS fields but keeps existing:
```json
{
  "id": 1,
  "document_number": "INV-001",
  "total_amount": "1000.00",
  "balance_due": "500.00",
  "payment_status": "partial",
  "fiscal_category": "TAX_INVOICE",  // NEW
  "status": "SEALED"                 // NEW
}
```

Frontend must handle both old and new formats gracefully.

---

## 📁 Key Files to Preserve

**Do NOT break these:**

```
app/Modules/Treasury/
├── Application/Services/
│   ├── PaymentAllocationService.php ✅ WORKING
│   └── PaymentToleranceService.php ✅ WORKING
├── Presentation/Controllers/
│   └── PaymentController.php ✅ WORKING
└── Domain/Enums/
    ├── AllocationMethod.php ✅ WORKING
    └── AllocationType.php ✅ WORKING

app/Modules/Accounting/
├── Application/Services/
│   └── GeneralLedgerService.php ✅ HAS PAYMENT METHODS
└── Domain/
    └── Document.php ✅ WORKING (will be extended)

tests/Feature/Treasury/
└── SmartPaymentIntegrationTest.php ✅ MUST KEEP PASSING

apps/web/src/features/treasury/
├── PaymentForm.tsx ✅ WORKING
└── PaymentAllocationForm.tsx ✅ WORKING
```

---

## ✅ Pre-Phase-1 Verification

Before starting Phase 1, verify current state:

```bash
# 1. Smart payments tests pass
php artisan test tests/Feature/Treasury/SmartPaymentIntegrationTest.php
Expected: 8/8 passing

# 2. Payment allocation works
php artisan tinker --execute="
\$invoice = App\Modules\Accounting\Domain\Document::factory()->create([
    'document_type' => 'invoice',
    'total_amount' => '100.00',
    'balance_due' => '100.00'
]);

\$payment = App\Modules\Treasury\Domain\Payment::create([
    'company_id' => \$invoice->company_id,
    'partner_id' => \$invoice->partner_id,
    'amount' => '100.00',
    'payment_date' => today(),
    'allocation_method' => 'FIFO'
]);

\$service = app(App\Modules\Treasury\Application\Services\PaymentAllocationService::class);
\$service->applyAllocation(\$payment, [[
    'document_id' => \$invoice->id,
    'amount' => '100.00'
]]);

\$invoice = \$invoice->fresh();
echo 'Balance after payment: ' . \$invoice->balance_due . PHP_EOL;
"
Expected output: "Balance after payment: 0.00"

# 3. GL entries created
php artisan tinker --execute="
\$lastEntry = App\Modules\Accounting\Domain\JournalEntry::latest()->first();
echo 'Latest GL entry: ' . \$lastEntry->reference . PHP_EOL;
"
Expected: Should show recent payment GL entry

# 4. PHPStan clean
vendor/bin/phpstan analyse --level=8 app/Modules/Treasury/
Expected: No errors (or note existing baseline)

# 5. Schema dump
php artisan schema:dump > /tmp/schema-before-hardening.sql
Expected: Success
```

---

## 🎯 Success Criteria for Schema Hardening

After Phase 1-3 complete, verify:

✅ All baseline tests still pass  
✅ Balance_due updates work on sealed documents  
✅ Smart payments functional  
✅ API backward compatible  
✅ Frontend displays new fields  
✅ No breaking changes  
✅ PHPStan level 8 clean  

---

## 📞 Escalation Scenarios

**If after Phase 1:**

❌ Smart payment tests fail → STOP, investigate trigger logic  
❌ Balance_due updates blocked → STOP, fix trigger immediately  
❌ Breaking API changes detected → STOP, make additive only  

**Report immediately if:**
- Baseline tests fail
- Cannot update balance_due on sealed
- PHPStan errors block progress
- Unclear about constraint logic

---

*Current state documented for Claude Code Opus handoff*  
*Date: December 10, 2025*  
*Smart Payments: COMPLETE ✅*
