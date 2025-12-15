# TASK: Implement GL Subledger for Customer Balance (Option C)

## CRITICAL: Pre-Check Protocol

Before implementing ANYTHING, you MUST run these checks and report findings:

### Check 1: Does partner_id exist on journal_lines?
```bash
grep -rn "partner_id" apps/api/app/Modules/Accounting/Domain/JournalLine.php
grep -rn "partner_id" apps/api/database/migrations/*journal* apps/api/database/migrations/*accounting*
```
If found, note what exists. If not found, it needs to be added.

### Check 2: Does GeneralLedgerService exist and what methods does it have?
```bash
cat apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php 2>/dev/null || echo "FILE NOT FOUND"
```
If exists, check if methods accept partner_id parameter.

### Check 3: Is GL integrated into DocumentController?
```bash
grep -n "GeneralLedgerService\|createFromInvoice\|journalEntry" apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php
```

### Check 4: Is GL integrated into PaymentController?
```bash
grep -n "GeneralLedgerService\|createPaymentEntry\|journal" apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php
```

### Check 5: Does Partner have balance fields?
```bash
grep -n "receivable_balance\|credit_balance" apps/api/app/Modules/Partner/Domain/Partner.php
grep -n "receivable_balance\|credit_balance" apps/api/database/migrations/*partner*
```

### Check 6: Does Payment have payment_type?
```bash
grep -n "payment_type" apps/api/app/Modules/Treasury/Domain/Payment.php
ls apps/api/app/Modules/Treasury/Domain/Enums/ 2>/dev/null
```

### Check 7: Is PaymentAllocation.document_id nullable?
```bash
grep -A5 "document_id" apps/api/database/migrations/*payment_allocation* apps/api/database/migrations/*treasury*
```

### Check 8: Does CustomerBalanceService exist?
```bash
find apps/api -name "*CustomerBalance*" -o -name "*PartnerBalance*" 2>/dev/null
```

### Check 9: Chart of Accounts seeder?
```bash
find apps/api/database/seeders -name "*Account*" -o -name "*Chart*"
```

---

## STOP AND REPORT

After running all checks above, create a file `docs/audits/GL-IMPLEMENTATION-PRECHECK.md` with your findings in this format:

```markdown
# GL Implementation Pre-Check Results
Date: [current date]

| Component | Status | Details |
|-----------|--------|---------|
| journal_lines.partner_id | ✅ EXISTS / ❌ MISSING | [details] |
| GeneralLedgerService | ✅ EXISTS / ❌ MISSING | [methods found] |
| GL in DocumentController | ✅ INTEGRATED / ❌ NOT INTEGRATED | |
| GL in PaymentController | ✅ INTEGRATED / ❌ NOT INTEGRATED | |
| Partner.balance fields | ✅ EXISTS / ❌ MISSING | |
| Payment.payment_type | ✅ EXISTS / ❌ MISSING | |
| PaymentAllocation nullable | ✅ NULLABLE / ❌ NOT NULLABLE | |
| CustomerBalanceService | ✅ EXISTS / ❌ MISSING | |
| Chart of Accounts seeder | ✅ EXISTS / ❌ MISSING | |

## What Needs Implementation

1. [List items that are MISSING]
2. ...
```

**STOP HERE and wait for user confirmation before proceeding with implementation.**

---

## Implementation Steps (Only After Pre-Check Approval)

### Step 1: Schema Migrations

Create migrations ONLY for missing components:

**1.1 If partner_id missing on journal_lines:**
```bash
php artisan make:migration add_partner_id_to_journal_lines
```

Migration content:
```php
public function up(): void
{
    Schema::table('journal_lines', function (Blueprint $table) {
        $table->foreignUuid('partner_id')
            ->nullable()
            ->after('account_id')
            ->constrained('partners')
            ->nullOnDelete();
        
        $table->index(['account_id', 'partner_id'], 'journal_lines_subledger_idx');
    });
}

public function down(): void
{
    Schema::table('journal_lines', function (Blueprint $table) {
        $table->dropIndex('journal_lines_subledger_idx');
        $table->dropConstrainedForeignId('partner_id');
    });
}
```

**1.2 If balance fields missing on partners:**
```bash
php artisan make:migration add_balance_fields_to_partners
```

Migration content:
```php
public function up(): void
{
    Schema::table('partners', function (Blueprint $table) {
        $table->decimal('receivable_balance', 15, 2)->default(0)->after('notes');
        $table->decimal('credit_balance', 15, 2)->default(0)->after('receivable_balance');
        $table->timestamp('balance_updated_at')->nullable()->after('credit_balance');
    });
}

public function down(): void
{
    Schema::table('partners', function (Blueprint $table) {
        $table->dropColumn(['receivable_balance', 'credit_balance', 'balance_updated_at']);
    });
}
```

**1.3 If payment_type missing on payments:**
```bash
php artisan make:migration add_payment_type_to_payments
```

Migration content:
```php
public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->string('payment_type', 30)
            ->default('document_payment')
            ->after('partner_id');
        
        $table->index('payment_type');
    });
}
```

Also create enum at `apps/api/app/Modules/Treasury/Domain/Enums/PaymentType.php`:
```php
<?php

namespace App\Modules\Treasury\Domain\Enums;

enum PaymentType: string
{
    case DOCUMENT_PAYMENT = 'document_payment';
    case ADVANCE = 'advance';
    case REFUND = 'refund';
    case CREDIT_APPLICATION = 'credit_application';
}
```

**1.4 If document_id not nullable on payment_allocations:**
```bash
php artisan make:migration make_payment_allocation_document_nullable
```

Migration content:
```php
public function up(): void
{
    Schema::table('payment_allocations', function (Blueprint $table) {
        $table->uuid('document_id')->nullable()->change();
    });
    
    Schema::table('payment_allocations', function (Blueprint $table) {
        $table->string('allocation_type', 30)
            ->default('invoice_payment')
            ->after('document_id');
    });
}
```

Also create enum at `apps/api/app/Modules/Treasury/Domain/Enums/AllocationType.php`:
```php
<?php

namespace App\Modules\Treasury\Domain\Enums;

enum AllocationType: string
{
    case INVOICE_PAYMENT = 'invoice_payment';
    case CREDIT_ADDITION = 'credit_addition';
    case CREDIT_APPLICATION = 'credit_application';
}
```

### Step 2: Run Migrations
```bash
php artisan migrate
```

### Step 3: Update Models

**3.1 Update JournalLine model** (if partner_id was added):

Add `'partner_id'` to `$fillable` array.

Add relationship:
```php
public function partner(): BelongsTo
{
    return $this->belongsTo(\App\Modules\Partner\Domain\Partner::class);
}
```

**3.2 Update Partner model** (if balance fields were added):

Add to `$fillable`: `'receivable_balance', 'credit_balance', 'balance_updated_at'`

Add to `$casts`:
```php
'receivable_balance' => 'decimal:2',
'credit_balance' => 'decimal:2',
'balance_updated_at' => 'datetime',
```

Add helper method:
```php
public function getNetBalance(): string
{
    return bcsub($this->receivable_balance ?? '0', $this->credit_balance ?? '0', 2);
}
```

**3.3 Update Payment model** (if payment_type was added):

Add `'payment_type'` to `$fillable`.

Add to `$casts`:
```php
'payment_type' => \App\Modules\Treasury\Domain\Enums\PaymentType::class,
```

**3.4 Update PaymentAllocation model** (if allocation_type was added):

Add `'allocation_type'` to `$fillable`.

Add to `$casts`:
```php
'allocation_type' => \App\Modules\Treasury\Domain\Enums\AllocationType::class,
```

### Step 4: Update GeneralLedgerService

If the service exists but doesn't set partner_id on journal lines, update the methods.

In `createFromInvoice()`, when creating the AR (receivables) journal line, add partner_id:
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $receivablesAccount->id,
    'partner_id' => $invoice->partner_id,  // ADD THIS LINE
    'debit' => $invoice->total_ttc,
    'credit' => '0.00',
    'description' => "AR - {$invoice->partner->name}",
    'line_order' => 1,
]);
```

In `createPaymentEntry()`, when creating the AR credit line, add partner_id:
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $receivablesAccount->id,
    'partner_id' => $payment->partner_id,  // ADD THIS LINE
    'debit' => '0.00',
    'credit' => $amount,
    'description' => "Payment from {$payment->partner->name}",
    'line_order' => 2,
]);
```

### Step 5: Create CustomerBalanceService (if missing)

Create file at `apps/api/app/Modules/Partner/Domain/Services/CustomerBalanceService.php`:

```php
<?php

namespace App\Modules\Partner\Domain\Services;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CustomerBalanceService
{
    /**
     * Calculate customer receivable balance from GL subledger.
     * Receivable = SUM(debit) - SUM(credit) on AR account (411xxx) for this partner
     */
    public function calculateReceivableFromGL(Partner $partner): string
    {
        $arAccountCodes = ['411000', '411100', '411200'];
        
        $result = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::POSTED)
            ->where('journal_entries.company_id', $partner->company_id)
            ->where('journal_lines.partner_id', $partner->id)
            ->whereIn('accounts.code', $arAccountCodes)
            ->selectRaw('COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) as balance')
            ->first();
        
        return number_format((float) ($result->balance ?? 0), 2, '.', '');
    }
    
    /**
     * Calculate customer credit balance from GL subledger.
     * Credit = SUM(credit) - SUM(debit) on Customer Advances account (419xxx)
     */
    public function calculateCreditFromGL(Partner $partner): string
    {
        $advanceAccountCodes = ['419000', '419100'];
        
        $result = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::POSTED)
            ->where('journal_entries.company_id', $partner->company_id)
            ->where('journal_lines.partner_id', $partner->id)
            ->whereIn('accounts.code', $advanceAccountCodes)
            ->selectRaw('COALESCE(SUM(journal_lines.credit) - SUM(journal_lines.debit), 0) as balance')
            ->first();
        
        return number_format((float) ($result->balance ?? 0), 2, '.', '');
    }
    
    /**
     * Get full customer ledger (statement) from GL.
     */
    public function getCustomerLedger(
        Partner $partner,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $arAccountCodes = ['411000', '411100', '411200', '419000', '419100'];
        
        $query = JournalLine::query()
            ->with(['journalEntry:id,date,reference_type,reference_id,description'])
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.status', JournalEntryStatus::POSTED)
            ->where('journal_entries.company_id', $partner->company_id)
            ->where('journal_lines.partner_id', $partner->id)
            ->whereIn('accounts.code', $arAccountCodes)
            ->select('journal_lines.*')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.id');
        
        if ($from) {
            $query->where('journal_entries.date', '>=', $from);
        }
        if ($to) {
            $query->where('journal_entries.date', '<=', $to);
        }
        
        $runningBalance = '0.00';
        
        return $query->get()->map(function ($line) use (&$runningBalance) {
            $runningBalance = bcadd(
                $runningBalance,
                bcsub($line->debit, $line->credit, 2),
                2
            );
            
            return [
                'date' => $line->journalEntry->date,
                'reference_type' => $line->journalEntry->reference_type,
                'reference_id' => $line->journalEntry->reference_id,
                'description' => $line->journalEntry->description,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'balance' => $runningBalance,
            ];
        });
    }
    
    /**
     * Recalculate and update cached balance on Partner.
     */
    public function refreshPartnerBalance(Partner $partner): void
    {
        $partner->update([
            'receivable_balance' => $this->calculateReceivableFromGL($partner),
            'credit_balance' => $this->calculateCreditFromGL($partner),
            'balance_updated_at' => now(),
        ]);
    }
}
```

### Step 6: Integrate GL into DocumentController

Find the `post()` method in DocumentController and add GL integration after updating the document status:

```php
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Partner\Domain\Services\CustomerBalanceService;

// Inside post() method, after setting status to Posted:
if ($document->isInvoiceType()) {
    $glService = app(GeneralLedgerService::class);
    $journalEntry = $glService->createFromInvoice($document, $request->user());
    $glService->postEntry($journalEntry, $request->user());
    
    $document->update(['journal_entry_id' => $journalEntry->id]);
    
    app(CustomerBalanceService::class)->refreshPartnerBalance($document->partner);
}
```

If `isInvoiceType()` method doesn't exist on Document, check what method is used to determine document type and adapt accordingly.

### Step 7: Integrate GL into PaymentController

Find the `store()` method in PaymentController and add GL integration after creating payment allocations:

```php
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Partner\Domain\Services\CustomerBalanceService;

// Inside store() method, after creating each allocation:
$glService = app(GeneralLedgerService::class);

foreach ($request->allocations as $allocation) {
    $document = Document::find($allocation['document_id']);
    $amount = $allocation['amount'];
    
    // ... existing allocation creation code ...
    
    // Create journal entry for this allocation
    $journalEntry = $glService->createPaymentEntry($payment, $document, $amount, $request->user());
    $glService->postEntry($journalEntry, $request->user());
}

// Update partner balances at the end
app(CustomerBalanceService::class)->refreshPartnerBalance($payment->partner);
```

### Step 8: Run Tests
```bash
php artisan test --filter=Payment
php artisan test --filter=Document
php artisan test --filter=Accounting
php artisan test
```

Fix any failing tests before proceeding.

---

## Commit Protocol

Make atomic commits after each step:

1. `feat(accounting): add partner_id to journal_lines`
2. `feat(partner): add balance fields to partners`
3. `feat(treasury): add payment_type enum to payments`
4. `feat(treasury): make payment_allocation.document_id nullable`
5. `feat(accounting): update GeneralLedgerService with partner subledger`
6. `feat(partner): create CustomerBalanceService`
7. `feat(document): integrate GL into document posting`
8. `feat(treasury): integrate GL into payment recording`
9. `test(accounting): add GL integration tests`

---

## IMPORTANT RULES

1. **DO NOT** duplicate existing functionality - always check first
2. **DO NOT** modify existing tests unless they fail due to schema changes
3. **DO** preserve existing API contracts (add fields, don't remove)
4. **DO** use database transactions for all GL operations
5. **DO** run `php artisan test` after each major step
6. **DO** create the pre-check report before any implementation
7. **DO** wrap controller GL calls in the existing DB::transaction if one exists
