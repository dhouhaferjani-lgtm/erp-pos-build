# P1-A: Automatic GL Posting on Document Post

## Objective

When a document (Invoice, Credit Note, Supplier Invoice) is posted, the corresponding General Ledger entries must be created **automatically** within the same database transaction.

## Current State

Based on the audit:
- Invoice posting creates fiscal hash ✅
- Invoice posting dispatches `InvoicePosted` event ✅
- GL entry creation requires **manual call** to `GeneralLedgerService::createFromInvoice()` ❌
- Tests manually call GL service separately ❌

## Target State

```
DocumentPostingService::post()
    │
    ├── BEGIN TRANSACTION
    │   ├── Validate document
    │   ├── Lock document (pessimistic)
    │   ├── Generate document number
    │   ├── Create fiscal hash chain
    │   ├── CREATE GL ENTRIES ← NEW (automatic)
    │   ├── Update status to POSTED
    │   └── Save
    │
    ├── COMMIT
    │
    └── dispatch(InvoicePosted) ← After commit
```

## Tasks

### Step 1: Audit Current Implementation

```bash
# Find DocumentPostingService
find app -name "*DocumentPosting*" -o -name "*PostingService*" | grep -v test
cat $(find app/Modules/Document -name "*PostingService*.php" | head -1)

# Find GeneralLedgerService
find app -name "*GeneralLedger*" -o -name "*GLService*" | grep -v test
cat $(find app/Modules/Accounting -name "*GeneralLedgerService*.php" | head -1)

# Check if GL service has createFromInvoice method
grep -n "createFromInvoice\|createFromDocument" app/Modules/Accounting --include="*.php" -r

# Check current posting flow
grep -n "function post\|function finalize" app/Modules/Document/Domain/Services --include="*.php" -r

# Find what the test does manually
cat tests/Feature/Accounting/DocumentGLIntegrationTest.php
```

**Document:**
- Where is the posting logic? (file, method)
- What does `createFromInvoice()` expect as parameters?
- What GL entries does it create?

### Step 2: Understand the GL Entry Structure

For a typical **Sales Invoice**, the GL entries should be:

```
Debit:  Accounts Receivable (1200)     $1,200.00
Credit: Revenue - Sales (4000)         $1,000.00
Credit: Tax Payable - VAT (2300)         $200.00
```

For a **Credit Note** (reversal):
```
Debit:  Revenue - Sales (4000)         $1,000.00
Debit:  Tax Payable - VAT (2300)         $200.00
Credit: Accounts Receivable (1200)     $1,200.00
```

For a **Supplier Invoice**:
```
Debit:  Expense/Inventory (5000/1400)  $1,000.00
Debit:  Tax Receivable - VAT (1350)      $200.00
Credit: Accounts Payable (2100)        $1,200.00
```

**Verify this matches your implementation:**

```bash
# Check SystemAccountPurpose enum
find app -name "*AccountPurpose*" -o -name "*SystemAccount*"
cat $(find app -name "SystemAccountPurpose.php" | head -1)

# Check how accounts are looked up
grep -rn "SystemAccountPurpose\|getAccountFor\|findByPurpose" app/Modules/Accounting --include="*.php"
```

### Step 3: Modify DocumentPostingService

Locate and modify the posting service to include GL creation:

```php
<?php
// app/Modules/Document/Domain/Services/DocumentPostingService.php

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Events\CreditNotePosted;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Compliance\Domain\Services\FiscalHashService;
use Illuminate\Support\Facades\DB;

class DocumentPostingService
{
    public function __construct(
        private readonly FiscalHashService $fiscalHashService,
        private readonly DocumentNumberingService $numberingService,
        private readonly GeneralLedgerService $glService,  // ← ADD THIS
    ) {}
    
    public function post(Document $document): void
    {
        // Validation
        $this->validateForPosting($document);
        
        DB::transaction(function () use ($document) {
            // 1. Lock document to prevent concurrent posting
            $document = Document::lockForUpdate()->findOrFail($document->id);
            
            // 2. Generate document number if not set
            if (empty($document->document_number)) {
                $document->document_number = $this->numberingService->generate(
                    $document->tenant_id,
                    $document->company_id,
                    $document->type
                );
            }
            
            // 3. Create fiscal hash chain
            $this->fiscalHashService->createHash($document);
            
            // 4. CREATE GL ENTRIES - NEW!
            if ($this->shouldCreateGLEntries()) {
                $this->createGLEntries($document);
            }
            
            // 5. Update status
            $document->status = DocumentStatus::POSTED;
            $document->posted_at = now();
            $document->save();
        });
        
        // 6. Dispatch event AFTER transaction commits
        $this->dispatchPostEvent($document);
    }
    
    private function shouldCreateGLEntries(): bool
    {
        return config('accounting.auto_post_gl', true);
    }
    
    private function createGLEntries(Document $document): void
    {
        match ($document->type) {
            DocumentType::INVOICE => $this->glService->createFromInvoice($document),
            DocumentType::CREDIT_NOTE => $this->glService->createFromCreditNote($document),
            DocumentType::SUPPLIER_INVOICE => $this->glService->createFromSupplierInvoice($document),
            default => null, // Quotes, Orders don't need GL entries
        };
    }
    
    private function dispatchPostEvent(Document $document): void
    {
        match ($document->type) {
            DocumentType::INVOICE => event(new InvoicePosted($document)),
            DocumentType::CREDIT_NOTE => event(new CreditNotePosted($document)),
            default => null,
        };
    }
    
    private function validateForPosting(Document $document): void
    {
        if ($document->status !== DocumentStatus::DRAFT) {
            throw new \DomainException("Document must be in draft status to post");
        }
        
        if ($document->lines->isEmpty()) {
            throw new \DomainException("Document must have at least one line item");
        }
        
        // Add more validations as needed
    }
}
```

### Step 4: Verify/Create GL Service Methods

Ensure `GeneralLedgerService` has the required methods:

```bash
# Check existing methods
grep -n "public function" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
```

If methods are missing or incomplete, implement them:

```php
<?php
// In GeneralLedgerService

public function createFromInvoice(Document $invoice): JournalEntry
{
    $company = $invoice->company;
    
    // Build journal entry
    $entry = new JournalEntry([
        'company_id' => $company->id,
        'entry_date' => $invoice->document_date,
        'reference' => $invoice->document_number,
        'description' => "Sales Invoice {$invoice->document_number}",
        'source_type' => 'invoice',
        'source_id' => $invoice->id,
    ]);
    
    $lines = [];
    
    // Debit: Accounts Receivable
    $arAccount = $this->accountService->findByPurpose(
        $company, 
        SystemAccountPurpose::ACCOUNTS_RECEIVABLE
    );
    
    $lines[] = [
        'account_id' => $arAccount->id,
        'debit' => $invoice->total_amount,
        'credit' => 0,
        'description' => "Customer: {$invoice->partner->name}",
    ];
    
    // Credit: Revenue (may need to split by tax rate)
    $revenueAccount = $this->accountService->findByPurpose(
        $company,
        SystemAccountPurpose::SALES_REVENUE
    );
    
    $lines[] = [
        'account_id' => $revenueAccount->id,
        'debit' => 0,
        'credit' => $invoice->subtotal_amount,
        'description' => "Sales revenue",
    ];
    
    // Credit: Tax Payable (if applicable)
    if ($invoice->tax_amount > 0) {
        $taxAccount = $this->accountService->findByPurpose(
            $company,
            SystemAccountPurpose::TAX_PAYABLE
        );
        
        $lines[] = [
            'account_id' => $taxAccount->id,
            'debit' => 0,
            'credit' => $invoice->tax_amount,
            'description' => "VAT payable",
        ];
    }
    
    return $this->createEntry($entry, $lines);
}

public function createFromCreditNote(Document $creditNote): JournalEntry
{
    // Reverse of invoice - debits become credits, credits become debits
    $company = $creditNote->company;
    
    $entry = new JournalEntry([
        'company_id' => $company->id,
        'entry_date' => $creditNote->document_date,
        'reference' => $creditNote->document_number,
        'description' => "Credit Note {$creditNote->document_number}",
        'source_type' => 'credit_note',
        'source_id' => $creditNote->id,
    ]);
    
    $lines = [];
    
    // Credit: Accounts Receivable (reduce AR)
    $arAccount = $this->accountService->findByPurpose(
        $company, 
        SystemAccountPurpose::ACCOUNTS_RECEIVABLE
    );
    
    $lines[] = [
        'account_id' => $arAccount->id,
        'debit' => 0,
        'credit' => $creditNote->total_amount,
        'description' => "Customer: {$creditNote->partner->name}",
    ];
    
    // Debit: Revenue (reduce revenue)
    $revenueAccount = $this->accountService->findByPurpose(
        $company,
        SystemAccountPurpose::SALES_REVENUE
    );
    
    $lines[] = [
        'account_id' => $revenueAccount->id,
        'debit' => $creditNote->subtotal_amount,
        'credit' => 0,
        'description' => "Sales return",
    ];
    
    // Debit: Tax Payable (reduce tax liability)
    if ($creditNote->tax_amount > 0) {
        $taxAccount = $this->accountService->findByPurpose(
            $company,
            SystemAccountPurpose::TAX_PAYABLE
        );
        
        $lines[] = [
            'account_id' => $taxAccount->id,
            'debit' => $creditNote->tax_amount,
            'credit' => 0,
            'description' => "VAT adjustment",
        ];
    }
    
    return $this->createEntry($entry, $lines);
}

public function createFromSupplierInvoice(Document $supplierInvoice): JournalEntry
{
    $company = $supplierInvoice->company;
    
    $entry = new JournalEntry([
        'company_id' => $company->id,
        'entry_date' => $supplierInvoice->document_date,
        'reference' => $supplierInvoice->document_number,
        'description' => "Supplier Invoice {$supplierInvoice->document_number}",
        'source_type' => 'supplier_invoice',
        'source_id' => $supplierInvoice->id,
    ]);
    
    $lines = [];
    
    // Debit: Expense or Inventory (based on line items)
    // This is simplified - you may need line-by-line account mapping
    $expenseAccount = $this->accountService->findByPurpose(
        $company,
        SystemAccountPurpose::PURCHASES
    );
    
    $lines[] = [
        'account_id' => $expenseAccount->id,
        'debit' => $supplierInvoice->subtotal_amount,
        'credit' => 0,
        'description' => "Purchases",
    ];
    
    // Debit: Tax Receivable
    if ($supplierInvoice->tax_amount > 0) {
        $taxAccount = $this->accountService->findByPurpose(
            $company,
            SystemAccountPurpose::TAX_RECEIVABLE
        );
        
        $lines[] = [
            'account_id' => $taxAccount->id,
            'debit' => $supplierInvoice->tax_amount,
            'credit' => 0,
            'description' => "VAT receivable",
        ];
    }
    
    // Credit: Accounts Payable
    $apAccount = $this->accountService->findByPurpose(
        $company,
        SystemAccountPurpose::ACCOUNTS_PAYABLE
    );
    
    $lines[] = [
        'account_id' => $apAccount->id,
        'debit' => 0,
        'credit' => $supplierInvoice->total_amount,
        'description' => "Supplier: {$supplierInvoice->partner->name}",
    ];
    
    return $this->createEntry($entry, $lines);
}
```

### Step 5: Add Configuration Option

```php
<?php
// config/accounting.php

return [
    /*
    |--------------------------------------------------------------------------
    | Automatic GL Posting
    |--------------------------------------------------------------------------
    |
    | When true, posting a document (invoice, credit note, etc.) will 
    | automatically create the corresponding GL entries in the same transaction.
    | When false, GL entries must be created manually (legacy behavior).
    |
    */
    'auto_post_gl' => env('AUTO_POST_GL', true),
    
    /*
    |--------------------------------------------------------------------------
    | Country-Specific Overrides
    |--------------------------------------------------------------------------
    |
    | Some countries may require manual GL posting or different workflows.
    | Add country codes here to override the default behavior.
    |
    */
    'country_overrides' => [
        // 'DE' => ['auto_post_gl' => false],
    ],
];
```

### Step 6: Update Tests

Find and update tests that manually call GL service:

```bash
# Find tests that call GL service after posting
grep -rn "createFromInvoice\|createFromDocument" tests --include="*.php"
```

Update the test to verify automatic GL creation:

```php
<?php
// tests/Feature/Accounting/DocumentGLIntegrationTest.php

public function test_posting_invoice_automatically_creates_gl_entries(): void
{
    // Arrange
    $company = $this->createCompanyWithChartOfAccounts();
    $partner = Partner::factory()->for($company)->create();
    
    $invoice = Document::factory()->for($company)->create([
        'type' => DocumentType::INVOICE,
        'status' => DocumentStatus::DRAFT,
        'partner_id' => $partner->id,
        'subtotal_amount' => 1000.00,
        'tax_amount' => 200.00,
        'total_amount' => 1200.00,
    ]);
    
    DocumentLine::factory()->for($invoice)->create([
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => 20.00,
    ]);
    
    // Act
    $this->postingService->post($invoice);
    
    // Assert - Invoice is posted
    $invoice->refresh();
    $this->assertEquals(DocumentStatus::POSTED, $invoice->status);
    $this->assertNotNull($invoice->posted_at);
    $this->assertNotNull($invoice->fiscal_hash);
    
    // Assert - GL entry was created automatically
    $journalEntry = JournalEntry::where('source_type', 'invoice')
        ->where('source_id', $invoice->id)
        ->first();
    
    $this->assertNotNull($journalEntry, 'GL entry should be created automatically');
    $this->assertEquals($invoice->document_number, $journalEntry->reference);
    
    // Assert - Entry is balanced
    $lines = $journalEntry->lines;
    $totalDebit = $lines->sum('debit');
    $totalCredit = $lines->sum('credit');
    $this->assertEquals($totalDebit, $totalCredit, 'GL entry must be balanced');
    
    // Assert - Correct amounts
    $arLine = $lines->where('account.purpose', SystemAccountPurpose::ACCOUNTS_RECEIVABLE)->first();
    $this->assertEquals(1200.00, $arLine->debit);
    
    $revenueLine = $lines->where('account.purpose', SystemAccountPurpose::SALES_REVENUE)->first();
    $this->assertEquals(1000.00, $revenueLine->credit);
    
    $taxLine = $lines->where('account.purpose', SystemAccountPurpose::TAX_PAYABLE)->first();
    $this->assertEquals(200.00, $taxLine->credit);
}

public function test_posting_invoice_rolls_back_on_gl_failure(): void
{
    // Arrange
    $company = $this->createCompanyWithChartOfAccounts();
    
    // Remove the AR account to force GL creation to fail
    Account::where('company_id', $company->id)
        ->where('purpose', SystemAccountPurpose::ACCOUNTS_RECEIVABLE)
        ->delete();
    
    $invoice = Document::factory()->for($company)->create([
        'type' => DocumentType::INVOICE,
        'status' => DocumentStatus::DRAFT,
    ]);
    
    DocumentLine::factory()->for($invoice)->create();
    
    // Act & Assert
    $this->expectException(\Exception::class);
    $this->postingService->post($invoice);
    
    // Invoice should still be draft (transaction rolled back)
    $invoice->refresh();
    $this->assertEquals(DocumentStatus::DRAFT, $invoice->status);
    $this->assertNull($invoice->posted_at);
}

public function test_credit_note_creates_reversing_gl_entries(): void
{
    // Arrange
    $company = $this->createCompanyWithChartOfAccounts();
    
    $creditNote = Document::factory()->for($company)->create([
        'type' => DocumentType::CREDIT_NOTE,
        'status' => DocumentStatus::DRAFT,
        'subtotal_amount' => 500.00,
        'tax_amount' => 100.00,
        'total_amount' => 600.00,
    ]);
    
    DocumentLine::factory()->for($creditNote)->create();
    
    // Act
    $this->postingService->post($creditNote);
    
    // Assert - GL entry has reversed debits/credits
    $journalEntry = JournalEntry::where('source_type', 'credit_note')
        ->where('source_id', $creditNote->id)
        ->first();
    
    $lines = $journalEntry->lines;
    
    // AR should be CREDITED (reducing receivable)
    $arLine = $lines->where('account.purpose', SystemAccountPurpose::ACCOUNTS_RECEIVABLE)->first();
    $this->assertEquals(600.00, $arLine->credit);
    $this->assertEquals(0, $arLine->debit);
    
    // Revenue should be DEBITED (reducing revenue)
    $revenueLine = $lines->where('account.purpose', SystemAccountPurpose::SALES_REVENUE)->first();
    $this->assertEquals(500.00, $revenueLine->debit);
}
```

### Step 7: Remove Manual GL Calls from Controllers

If any controllers manually call GL service after posting, remove that:

```bash
# Find controllers that call GL service
grep -rn "GeneralLedgerService\|createFromInvoice" app/Modules/Document/Presentation --include="*.php"
```

### Step 8: Verification

```bash
# 1. Run the specific test
php artisan test --filter=DocumentGLIntegrationTest

# 2. Run all accounting tests
php artisan test --filter=Accounting

# 3. Run all document tests
php artisan test --filter=Document

# 4. Verify GL entries are created
php artisan tinker --execute="
    \$invoice = \App\Modules\Document\Domain\Document::where('status', 'posted')->first();
    \$entry = \App\Modules\Accounting\Domain\JournalEntry::where('source_id', \$invoice->id)->first();
    dump([
        'invoice' => \$invoice->document_number,
        'gl_entry' => \$entry?->reference,
        'balanced' => \$entry ? \$entry->lines->sum('debit') === \$entry->lines->sum('credit') : null,
    ]);
"
```

---

## Deliverables

1. **Modified DocumentPostingService**: Includes automatic GL creation
2. **Updated/Created GL Service methods**: `createFromInvoice()`, `createFromCreditNote()`, `createFromSupplierInvoice()`
3. **Configuration file**: `config/accounting.php` with `auto_post_gl` setting
4. **Updated tests**: Verify automatic GL creation, rollback on failure
5. **Removed manual calls**: No more manual GL calls in controllers

## Success Criteria

- [ ] Posting an invoice automatically creates balanced GL entries
- [ ] Posting a credit note creates reversed GL entries
- [ ] Transaction rolls back if GL creation fails (invoice stays draft)
- [ ] Configuration option exists to disable auto-GL (default: enabled)
- [ ] All existing tests pass
- [ ] New tests verify the automatic behavior
