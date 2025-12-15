# GeneralLedgerService Completion - Phase 5 Fix

## Context

GL Subledger implementation is mostly complete, but Codex audit identified gaps in GeneralLedgerService:

1. **Missing supplier methods** - No `createSupplierInvoiceJournalEntry()` or `createSupplierPaymentJournalEntry()`
2. **Missing advance method** - No `createCustomerAdvanceJournalEntry()`
3. **No auto-refresh** - Partner cached balances not refreshed after GL writes
4. **No PartnerBalanceService dependency** - Service not injected

## Current State

**File:** `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

Current methods (per Codex):
- `createInvoiceJournalEntry()` - exists but doesn't refresh cache
- `createCreditNoteJournalEntry()` - exists but doesn't refresh cache
- `createPaymentReceivedJournalEntry()` - exists but doesn't refresh cache

Missing methods:
- `createCustomerAdvanceJournalEntry()`
- `createSupplierInvoiceJournalEntry()`
- `createSupplierPaymentJournalEntry()`

## Task

Update `GeneralLedgerService` to:
1. Inject `PartnerBalanceService` in constructor
2. Add missing journal entry methods
3. Call `refreshPartnerBalance()` after every partner-affecting entry

---

## Implementation

### Step 1: Update Constructor

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use Illuminate\Support\Facades\DB;

class GeneralLedgerService
{
    public function __construct(
        private PartnerBalanceService $partnerBalanceService
    ) {}
    
    // ... existing methods
}
```

### Step 2: Update Existing Methods to Refresh Cache

For each existing method that affects a partner (`createInvoiceJournalEntry`, `createCreditNoteJournalEntry`, `createPaymentReceivedJournalEntry`), add cache refresh at the end of the transaction:

```php
/**
 * Create journal entry for a validated customer invoice
 */
public function createInvoiceJournalEntry(
    string $companyId,
    string $partnerId,
    string $invoiceId,
    string $totalAmount,
    string $netAmount,
    string $vatAmount,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    $revenueAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PRODUCT_REVENUE);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_COLLECTED);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $invoiceId, $totalAmount, $netAmount, $vatAmount,
        $date, $description, $receivableAccount, $revenueAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "INV-{$invoiceId}",
            'description' => $description ?? 'Customer invoice',
            'source_type' => 'invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);
        
        // Debit: Accounts Receivable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => $totalAmount,
            'credit' => '0',
            'description' => 'Customer receivable',
        ]);
        
        // Credit: Revenue
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $netAmount,
            'description' => 'Sales revenue',
        ]);
        
        // Credit: VAT Collected (if applicable)
        if (bccomp($vatAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => '0',
                'credit' => $vatAmount,
                'description' => 'VAT collected',
            ]);
        }
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}

/**
 * Create journal entry for a customer credit note
 */
public function createCreditNoteJournalEntry(
    string $companyId,
    string $partnerId,
    string $creditNoteId,
    string $totalAmount,
    string $netAmount,
    string $vatAmount,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    $revenueAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PRODUCT_REVENUE);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_COLLECTED);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $creditNoteId, $totalAmount, $netAmount, $vatAmount,
        $date, $description, $receivableAccount, $revenueAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "CN-{$creditNoteId}",
            'description' => $description ?? 'Customer credit note',
            'source_type' => 'credit_note',
            'source_id' => $creditNoteId,
            'status' => 'posted',
        ]);
        
        // Debit: Revenue (reverse the sale)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => $netAmount,
            'credit' => '0',
            'description' => 'Revenue reversal',
        ]);
        
        // Debit: VAT (if applicable)
        if (bccomp($vatAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => $vatAmount,
                'credit' => '0',
                'description' => 'VAT reversal',
            ]);
        }
        
        // Credit: Accounts Receivable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Receivable reduction',
        ]);
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}

/**
 * Create journal entry for payment received from customer
 */
public function createPaymentReceivedJournalEntry(
    string $companyId,
    string $partnerId,
    string $paymentId,
    string $amount,
    string $paymentMethodAccountId,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $paymentId, $amount, $paymentMethodAccountId,
        $date, $description, $receivableAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "PMT-{$paymentId}",
            'description' => $description ?? 'Customer payment received',
            'source_type' => 'payment',
            'source_id' => $paymentId,
            'status' => 'posted',
        ]);
        
        // Debit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentMethodAccountId,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Payment received',
        ]);
        
        // Credit: Accounts Receivable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Receivable cleared',
        ]);
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}
```

### Step 3: Add Missing Methods

```php
/**
 * Create journal entry for customer advance/prepayment
 * 
 * Advance payments create a liability (we owe the customer until invoice issued)
 */
public function createCustomerAdvanceJournalEntry(
    string $companyId,
    string $partnerId,
    string $advanceId,
    string $amount,
    string $paymentMethodAccountId,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $advanceAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_ADVANCE);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $advanceId, $amount, $paymentMethodAccountId,
        $date, $description, $advanceAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "ADV-{$advanceId}",
            'description' => $description ?? 'Customer advance received',
            'source_type' => 'advance',
            'source_id' => $advanceId,
            'status' => 'posted',
        ]);
        
        // Debit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentMethodAccountId,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Advance payment received',
        ]);
        
        // Credit: Customer Advances (liability - with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $advanceAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Customer advance liability',
        ]);
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}

/**
 * Create journal entry for supplier invoice (purchase)
 */
public function createSupplierInvoiceJournalEntry(
    string $companyId,
    string $partnerId,
    string $invoiceId,
    string $totalAmount,
    string $netAmount,
    string $vatAmount,
    string $expenseAccountId,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SUPPLIER_PAYABLE);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_DEDUCTIBLE);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $invoiceId, $totalAmount, $netAmount, $vatAmount,
        $expenseAccountId, $date, $description, $payableAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "SINV-{$invoiceId}",
            'description' => $description ?? 'Supplier invoice',
            'source_type' => 'supplier_invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);
        
        // Debit: Expense/Asset account
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAccountId,
            'partner_id' => null,
            'debit' => $netAmount,
            'credit' => '0',
            'description' => 'Purchase expense/asset',
        ]);
        
        // Debit: VAT Deductible (if applicable)
        if (bccomp($vatAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => $vatAmount,
                'credit' => '0',
                'description' => 'VAT deductible',
            ]);
        }
        
        // Credit: Accounts Payable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Supplier payable',
        ]);
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}

/**
 * Create journal entry for payment to supplier
 */
public function createSupplierPaymentJournalEntry(
    string $companyId,
    string $partnerId,
    string $paymentId,
    string $amount,
    string $paymentMethodAccountId,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SUPPLIER_PAYABLE);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $paymentId, $amount, $paymentMethodAccountId,
        $date, $description, $payableAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "SPMT-{$paymentId}",
            'description' => $description ?? 'Supplier payment',
            'source_type' => 'supplier_payment',
            'source_id' => $paymentId,
            'status' => 'posted',
        ]);
        
        // Debit: Accounts Payable (with partner for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $partnerId,
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Payable cleared',
        ]);
        
        // Credit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentMethodAccountId,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Payment to supplier',
        ]);
        
        return $entry;
    });
    
    // Refresh partner cached balance after GL write
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}
```

---

## Step 4: Update Tests

**File:** `tests/Feature/Accounting/GLIntegrationTest.php`

Add tests for new methods:

```php
/** @test */
public function it_creates_customer_advance_journal_entry(): void
{
    $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::BANK);
    
    $entry = $this->glService->createCustomerAdvanceJournalEntry(
        companyId: $this->company->id,
        partnerId: $this->customer->id,
        advanceId: 'ADV-001',
        amount: '500.0000',
        paymentMethodAccountId: $bankAccount->id,
        date: now(),
        description: 'Advance for future order'
    );
    
    $this->assertNotNull($entry);
    $this->assertEquals('posted', $entry->status);
    $this->assertCount(2, $entry->lines);
    
    // Verify partner balance was refreshed (credit balance increased)
    $this->customer->refresh();
    $this->assertEquals('500.0000', $this->customer->credit_balance);
}

/** @test */
public function it_creates_supplier_invoice_journal_entry(): void
{
    $supplier = Partner::factory()->create([
        'company_id' => $this->company->id,
        'type' => 'supplier',
    ]);
    
    $expenseAccount = Account::factory()->create([
        'company_id' => $this->company->id,
        'code' => '6100',
        'name' => 'Purchases',
    ]);
    
    $entry = $this->glService->createSupplierInvoiceJournalEntry(
        companyId: $this->company->id,
        partnerId: $supplier->id,
        invoiceId: 'SINV-001',
        totalAmount: '1190.0000',
        netAmount: '1000.0000',
        vatAmount: '190.0000',
        expenseAccountId: $expenseAccount->id,
        date: now()
    );
    
    $this->assertNotNull($entry);
    $this->assertEquals('posted', $entry->status);
    $this->assertCount(3, $entry->lines); // expense + vat + payable
    
    // Verify partner balance was refreshed
    $supplier->refresh();
    $this->assertEquals('1190.0000', $supplier->payable_balance);
}

/** @test */
public function it_creates_supplier_payment_journal_entry(): void
{
    $supplier = Partner::factory()->create([
        'company_id' => $this->company->id,
        'type' => 'supplier',
        'payable_balance' => '1000.0000',
    ]);
    
    $bankAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::BANK);
    
    $entry = $this->glService->createSupplierPaymentJournalEntry(
        companyId: $this->company->id,
        partnerId: $supplier->id,
        paymentId: 'SPMT-001',
        amount: '500.0000',
        paymentMethodAccountId: $bankAccount->id,
        date: now()
    );
    
    $this->assertNotNull($entry);
    $this->assertEquals('posted', $entry->status);
    
    // Note: Balance refresh calculates from GL, not just subtracts
    // So we'd need actual GL entries to test the balance properly
}

/** @test */
public function it_refreshes_partner_balance_after_invoice(): void
{
    // Create invoice entry
    $this->glService->createInvoiceJournalEntry(
        companyId: $this->company->id,
        partnerId: $this->customer->id,
        invoiceId: 'INV-001',
        totalAmount: '1190.0000',
        netAmount: '1000.0000',
        vatAmount: '190.0000',
        date: now()
    );
    
    // Verify cached balance was automatically updated
    $this->customer->refresh();
    $this->assertEquals('1190.0000', $this->customer->receivable_balance);
    $this->assertNotNull($this->customer->balance_updated_at);
}
```

---

## Verification

```bash
echo "=== PHASE 5 COMPLETION VERIFICATION ==="

echo -e "\n1. Constructor has PartnerBalanceService:"
grep -n "PartnerBalanceService" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | head -5

echo -e "\n2. Methods exist:"
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use ReflectionClass;

\$reflection = new ReflectionClass(GeneralLedgerService::class);
\$methods = [
    'createInvoiceJournalEntry',
    'createCreditNoteJournalEntry',
    'createPaymentReceivedJournalEntry',
    'createCustomerAdvanceJournalEntry',
    'createSupplierInvoiceJournalEntry',
    'createSupplierPaymentJournalEntry',
];

foreach (\$methods as \$m) {
    echo \$m . '(): ' . (\$reflection->hasMethod(\$m) ? '✓' : '✗') . PHP_EOL;
}
"

echo -e "\n3. Auto-refresh in place:"
grep -c "refreshPartnerBalance" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php

echo -e "\n4. Tests pass:"
php artisan test --filter=GLIntegration

echo -e "\n5. PHPStan:"
./vendor/bin/phpstan analyse app/Modules/Accounting/Domain/Services/GeneralLedgerService.php --level=8
```

---

## Summary

| Task | Description |
|------|-------------|
| Inject dependency | Add `PartnerBalanceService` to constructor |
| Update existing | Add `refreshPartnerBalance()` call to 3 existing methods |
| Add new methods | `createCustomerAdvanceJournalEntry()` |
| Add new methods | `createSupplierInvoiceJournalEntry()` |
| Add new methods | `createSupplierPaymentJournalEntry()` |
| Tests | Add tests for new methods + auto-refresh behavior |

**Estimated time:** 1-2 hours

## Commit

```
fix(accounting): complete GeneralLedgerService with supplier methods and auto-refresh

- Inject PartnerBalanceService dependency
- Add createCustomerAdvanceJournalEntry()
- Add createSupplierInvoiceJournalEntry()
- Add createSupplierPaymentJournalEntry()
- Call refreshPartnerBalance() after all partner-affecting GL entries
- Add tests for new methods and auto-refresh behavior

Closes Phase 5 gaps identified by Codex audit
```
