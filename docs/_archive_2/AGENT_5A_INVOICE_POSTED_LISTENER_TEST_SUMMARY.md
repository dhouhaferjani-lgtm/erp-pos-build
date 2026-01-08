# AGENT 5A - InvoicePosted Listener Tests (TDD RED Phase)

## Summary

Successfully created comprehensive test suite for the `InvoicePosted` event listener that will trigger GL entry creation when invoices are posted.

**Status**: RED Phase Complete ✅
- All tests WRITTEN
- Tests EXECUTED
- **7 tests FAIL** (expected - listener not yet implemented)
- **1 test PASSES** (listener is registered - found in DomainEventSubscriber)

---

## Test File Created

**Location**: `/Users/houssamr/Projects/mecanospex/apps/api/tests/Feature/Accounting/InvoicePostedListenerTest.php`

**File Size**: 700+ lines of comprehensive TDD tests

---

## Test Results Summary

```
Tests:    7 failed, 1 passed (10 assertions)
Duration: 6.52s
```

### Passing Tests (1)
✅ **test_invoice_posted_listener_is_registered**
- Verifies that the InvoicePosted event has at least one registered listener
- Confirms the DomainEventSubscriber is properly wired

### Failing Tests (7 - Expected Behavior)
⨯ **test_invoice_posted_event_creates_gl_entries**
- Expects GL entry to be created when InvoicePosted event is dispatched
- Failure: No GL entries created (listener logic not yet implemented)

⨯ **test_invoice_posted_event_creates_balanced_entries**
- Expects journal entries with balanced debits and credits
- Failure: GL entry doesn't exist yet

⨯ **test_multiple_invoice_posted_events_create_separate_gl_entries**
- Expects separate GL entries for different invoices
- Failure: GL entries not created from events

⨯ **test_invoice_posted_event_with_multiple_tax_rates**
- Expects correct handling of invoices with different tax rates
- Failure: GL entries not created

⨯ **test_invoice_posted_event_with_zero_tax**
- Expects correct GL handling for tax-exempt invoices
- Failure: GL entries not created

⨯ **test_invoice_posted_event_gl_entry_includes_invoice_number**
- Expects GL entry description to include invoice document number
- Failure: GL entries not created

⨯ **test_invoice_posted_event_distinguishes_product_and_service_revenue**
- Expects separate revenue accounts for products vs services
- Failure: GL entries not created

---

## How the Tests Work (Test Architecture)

### 1. Setup Phase (setUp method)
Each test creates a complete test context:
- Tenant (with Professional plan)
- Company (FR, EUR)
- User (with all accounting permissions)
- Customer (test partner)
- 3 Products (2 parts + 1 service)
- Chart of Accounts with SystemAccountPurpose:
  - 411: Customer Receivable
  - 707: Product Revenue
  - 706: Service Revenue
  - 44571: VAT Collected
- AccountingService instance

### 2. Invoice Creation (Helper Method)
```php
createInvoice(array $lines): Document
```
Creates test invoices with:
- Automatic total calculation
- Proper tax calculation per line
- Document lines with tax rates
- Realistic data

Example:
```php
$invoice = $this->createInvoice([
    ['product' => $product1, 'quantity' => '1', 'unit_price' => '500.00', 'tax_rate' => '20.00'],
    ['product' => $product2, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '5.50'],
]);
```

### 3. Event Dispatch Pattern
Each test dispatches the actual `InvoicePosted` event:
```php
$event = new InvoicePosted(
    invoiceId: $invoice->id,
    tenantId: $invoice->tenant_id,
    companyId: $invoice->company_id,
    documentNumber: $invoice->document_number,
    documentType: $invoice->type->value,
    partnerId: $invoice->partner_id,
    total: $invoice->total,
    currency: $invoice->currency,
    fiscalHash: 'test-hash-'.uniqid(),
    chainSequence: 1,
    postedAt: now()->toIso8601String(),
);

Event::dispatch($event);
```

### 4. Assertion Pattern
Tests verify:
1. GL entry was created
2. Entry has correct company/tenant IDs
3. Entry source is properly linked (source_type=Document, source_id=invoice_id)
4. Journal lines exist with correct accounts
5. Debits and credits balance
6. Amounts are correct (AR = total, Revenue = subtotal, Tax = tax_amount)
7. Description includes invoice number

---

## What the Tests Expect (from Agent 5B Implementation)

### Expected Listener Flow

When `InvoicePosted` event is dispatched, a listener should:

1. **Capture the event**
   - Listen for `InvoicePosted` class
   - Receive the event with all invoice details

2. **Load the invoice from database**
   - Fetch the full Document with lines
   - Get product types for revenue account selection

3. **Call AccountingService**
   - Invoke `$accountingService->createInvoiceGLEntries($invoice)`
   - This method already exists and works (verified in InvoiceGLIntegrationTest.php)

4. **Result**: GL entries are automatically created
   - Journal entry created
   - 3+ journal lines created (AR debit, Revenue credit(s), Tax credit(s))
   - Entry is balanced and posted

### Integration Points

**InvoicePosted Event**
- Location: `app/Modules/Document/Domain/Events/InvoicePosted.php`
- Already exists with all required properties
- Properties: invoiceId, tenantId, companyId, documentNumber, documentType, partnerId, total, currency, fiscalHash, chainSequence, postedAt

**AccountingService**
- Location: `app/Modules/Accounting/Application/Services/AccountingService.php`
- Method: `createInvoiceGLEntries(Document $invoice): string`
- Already fully implemented
- Returns journal entry ID
- Handles:
  - Multiple product types (product vs service revenue accounts)
  - Multiple tax rates (groups by tax rate)
  - Balances debits and credits
  - Uses SystemAccountPurpose to find accounts

**EventServiceProvider**
- Location: `app/Providers/EventServiceProvider.php`
- **Currently missing**: InvoicePostedListener registration
- Need to add to `$listen` array OR through subscriber pattern

**DomainEventSubscriber**
- Location: `app/Modules/Compliance/Listeners/DomainEventSubscriber.php`
- Uses subscriber pattern (registers in EventServiceProvider)
- Already subscribes to: InvoicePosted, InvoiceCancelled, InvoicePaid, etc.
- Already has `handleInvoicePosted()` method
- **Could be extended** to trigger GL creation OR separate listener created

---

## Test Coverage

The test suite covers:

### Basic Functionality
- ✅ Listener registration
- ✅ GL entry creation from event
- ✅ Entry source linking

### GL Entry Structure
- ✅ Correct account selection (AR, Product Revenue, Service Revenue, VAT)
- ✅ Correct debit/credit assignment
- ✅ Entry balance (debits = credits)
- ✅ Description includes invoice number

### Tax Handling
- ✅ Single tax rate invoices
- ✅ Multiple tax rates in single invoice
- ✅ Zero-tax (tax-exempt) invoices
- ✅ Tax grouping by rate

### Product Types
- ✅ Product revenue only
- ✅ Service revenue only
- ✅ Mixed product and service invoices

### Multiple Events
- ✅ Each invoice gets separate GL entry
- ✅ Correct amounts per invoice

---

## Implementation Notes for Agent 5B

### Option 1: Create New Listener Class (Recommended)

Create: `app/Modules/Accounting/Listeners/InvoicePostedListener.php`

```php
<?php
declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Events\InvoicePosted;

final class InvoicePostedListener
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function handle(InvoicePosted $event): void
    {
        // Load invoice from database
        $invoice = Document::find($event->invoiceId);

        if ($invoice === null) {
            return;
        }

        // Create GL entries
        $this->accountingService->createInvoiceGLEntries($invoice);
    }
}
```

Then register in `EventServiceProvider`:
```php
protected $listen = [
    InvoicePosted::class => [
        InvoicePostedListener::class,
    ],
];
```

### Option 2: Extend DomainEventSubscriber

Add to `app/Modules/Compliance/Listeners/DomainEventSubscriber`:

```php
public function handleInvoicePosted(InvoicePosted $event): void
{
    // Existing audit logging...
    $this->persistEvent(...);

    // NEW: Create GL entries
    $invoice = Document::find($event->invoiceId);
    if ($invoice !== null) {
        app(AccountingService::class)->createInvoiceGLEntries($invoice);
    }
}
```

**Why Option 1 is better:**
- Single Responsibility (listener only does GL creation)
- Easier to test in isolation
- Audit logging and GL creation are separate concerns
- Complies with module boundaries

---

## Running the Tests

All tests use RefreshDatabase and proper setup:

```bash
# Run all InvoicePostedListenerTest tests
cd apps/api
php artisan test --filter=InvoicePostedListenerTest

# Run specific test
php artisan test tests/Feature/Accounting/InvoicePostedListenerTest.php

# Run with verbose output
php artisan test --filter=InvoicePostedListenerTest -v

# Run without coverage (faster)
php artisan test --filter=InvoicePostedListenerTest --no-coverage
```

---

## Key Insights

### 1. InvoicePosted Event Already Exists
The event is properly structured with all required data:
- Document identifiers (ID, number, type)
- Financial data (total, currency)
- Compliance data (fiscalHash, chainSequence)

### 2. AccountingService is Production-Ready
The `createInvoiceGLEntries()` method:
- Is fully implemented
- Handles multiple tax rates
- Distinguishes product vs service revenue
- Creates balanced entries
- Uses SystemAccountPurpose for account selection
- Has extensive error handling

### 3. No Database Schema Changes Needed
All required tables exist:
- journal_entries
- journal_lines
- accounts

### 4. DomainEventSubscriber Already Listens to InvoicePosted
The subscription is already wired! (That's why test_invoice_posted_listener_is_registered PASSES)

The implementation just needs to call AccountingService from within the listener.

---

## Next Steps for Agent 5B

1. ✅ Read this document completely
2. ✅ Review InvoicePosted event structure
3. ✅ Review AccountingService::createInvoiceGLEntries() implementation
4. ✅ Create the listener (choose Option 1 or 2)
5. ✅ Wire listener in EventServiceProvider
6. ✅ Run tests - MUST ALL PASS (GREEN phase)
7. ✅ Run verification: `./scripts/preflight.sh`

---

## Test Execution Log

```
BEFORE IMPLEMENTATION:
Tests:    7 failed, 1 passed (10 assertions)
Duration: 6.52s

EXPECTED AFTER IMPLEMENTATION:
Tests:    8 passed (many assertions)
Duration: < 10s
```

The 1 passing test (listener registration) indicates the event dispatcher IS finding listeners for InvoicePosted, so the listener just needs to be created and do something useful.

---

**Document Status**: Complete ✅
**Created by**: Agent 5A (Test Writer)
**For**: Agent 5B (Implementation)
**Date**: 2025-12-26
