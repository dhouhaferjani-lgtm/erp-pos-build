# GL Subledger Integration - Implementation

## Context

We've completed the Country Adaptation foundation which enables country-agnostic account lookups via `SystemAccountPurpose`. Now we implement the GL subledger to track customer/supplier balances directly from journal entries.

**Problem We're Solving:**
Currently, partner balances are calculated from documents (invoices, payments), but this is disconnected from the General Ledger. The GL should be the single source of truth for all financial data, including partner balances.

**Solution:**
Add `partner_id` to `journal_lines` so we can:
1. Track which journal entries affect which partner
2. Calculate partner balance by summing their journal lines
3. Reconcile subledger (sum of partner balances) against control account (e.g., 411 Clients)
4. Cache balances on partners for performance
5. Track payment types for better reporting

## Prerequisites Verified ✅

- `accounts.company_id` column exists
- `accounts.system_purpose` column exists
- `SystemAccountPurpose` enum exists
- `GeneralLedgerService` uses purpose-based lookups
- `ChartOfAccountsService` exists

---

## Phase 1: Database Migrations

### 1.1 Add partner_id to journal_lines

### 1.1 Add partner_id to journal_lines

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_partner_id_to_journal_lines.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            // Partner reference - nullable because not all GL entries involve partners
            // (e.g., depreciation, payroll, bank fees)
            $table->uuid('partner_id')->nullable()->after('account_id');
            
            // Foreign key to partners table
            $table->foreign('partner_id')
                ->references('id')
                ->on('partners')
                ->onDelete('restrict'); // Don't allow deleting partners with GL history
            
            // Index for partner balance queries
            $table->index(['partner_id', 'account_id'], 'journal_lines_partner_account_idx');
            
            // Index for subledger queries (all lines for an account with partners)
            $table->index(['account_id', 'partner_id'], 'journal_lines_account_partner_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropIndex('journal_lines_partner_account_idx');
            $table->dropIndex('journal_lines_account_partner_idx');
            $table->dropColumn('partner_id');
        });
    }
};
```

### 1.2 Add cached balance fields to partners

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_balance_fields_to_partners.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            // Cached balance fields - source of truth is still GL
            // These are denormalized for performance (avoid summing GL on every request)
            
            // For customers: what they owe us (positive = they owe, negative = we owe them)
            $table->decimal('receivable_balance', 15, 4)->default(0)->after('type');
            
            // For customers: advance payments/credits (what we owe them before invoice)
            $table->decimal('credit_balance', 15, 4)->default(0)->after('receivable_balance');
            
            // For suppliers: what we owe them
            $table->decimal('payable_balance', 15, 4)->default(0)->after('credit_balance');
            
            // When balances were last recalculated from GL
            $table->timestamp('balance_updated_at')->nullable()->after('payable_balance');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn([
                'receivable_balance',
                'credit_balance', 
                'payable_balance',
                'balance_updated_at',
            ]);
        });
    }
};
```

### 1.3 Add payment_type to payments table

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_payment_type_to_payments.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Payment type for better tracking and reporting
            $table->string('payment_type', 30)->default('document_payment')->after('payment_method');
            
            $table->index('payment_type');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['payment_type']);
            $table->dropColumn('payment_type');
        });
    }
};
```

### Verification for Phase 1

```bash
php artisan migrate

php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'journal_lines.partner_id: ' . (Schema::hasColumn('journal_lines', 'partner_id') ? '✓' : '✗') . PHP_EOL;
echo 'partners.receivable_balance: ' . (Schema::hasColumn('partners', 'receivable_balance') ? '✓' : '✗') . PHP_EOL;
echo 'partners.credit_balance: ' . (Schema::hasColumn('partners', 'credit_balance') ? '✓' : '✗') . PHP_EOL;
echo 'partners.payable_balance: ' . (Schema::hasColumn('partners', 'payable_balance') ? '✓' : '✗') . PHP_EOL;
echo 'payments.payment_type: ' . (Schema::hasColumn('payments', 'payment_type') ? '✓' : '✗') . PHP_EOL;
"
```

---

## Phase 2: Enums

### 2.1 Create PaymentType Enum

**File:** `app/Modules/Treasury/Domain/Enums/PaymentType.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum PaymentType: string
{
    // Standard payment applied to one or more invoices
    case DOCUMENT_PAYMENT = 'document_payment';
    
    // Advance/prepayment before invoice exists (creates customer credit)
    case ADVANCE = 'advance';
    
    // Refund returned to customer
    case REFUND = 'refund';
    
    // Applying existing credit balance to pay an invoice
    case CREDIT_APPLICATION = 'credit_application';
    
    // Supplier payment (we pay them)
    case SUPPLIER_PAYMENT = 'supplier_payment';
    
    public function label(): string
    {
        return match($this) {
            self::DOCUMENT_PAYMENT => 'Invoice Payment',
            self::ADVANCE => 'Advance Payment',
            self::REFUND => 'Refund',
            self::CREDIT_APPLICATION => 'Credit Application',
            self::SUPPLIER_PAYMENT => 'Supplier Payment',
        };
    }
    
    /**
     * Does this payment type increase what the customer owes us?
     */
    public function increasesReceivable(): bool
    {
        return match($this) {
            self::REFUND => true,  // Refund re-creates receivable if from credit
            default => false,
        };
    }
    
    /**
     * Does this payment type decrease what the customer owes us?
     */
    public function decreasesReceivable(): bool
    {
        return match($this) {
            self::DOCUMENT_PAYMENT => true,
            self::CREDIT_APPLICATION => true,
            default => false,
        };
    }
    
    /**
     * Does this payment type create/increase customer credit?
     */
    public function createsCredit(): bool
    {
        return match($this) {
            self::ADVANCE => true,
            default => false,
        };
    }
    
    /**
     * Is this an incoming payment (money comes to us)?
     */
    public function isIncoming(): bool
    {
        return match($this) {
            self::DOCUMENT_PAYMENT => true,
            self::ADVANCE => true,
            self::CREDIT_APPLICATION => false, // No money moves, just accounting
            self::REFUND => false,
            self::SUPPLIER_PAYMENT => false,
        };
    }
    
    /**
     * Is this an outgoing payment (money leaves us)?
     */
    public function isOutgoing(): bool
    {
        return match($this) {
            self::REFUND => true,
            self::SUPPLIER_PAYMENT => true,
            default => false,
        };
    }
}
```

### Verification for Phase 2

```bash
php artisan tinker --execute="
use App\Modules\Treasury\Domain\Enums\PaymentType;

echo 'PaymentType enum exists: ✓' . PHP_EOL;
echo 'Cases: ' . count(PaymentType::cases()) . PHP_EOL;
foreach (PaymentType::cases() as \$type) {
    echo '  ' . \$type->value . ' => ' . \$type->label() . PHP_EOL;
}
"
```

---

## Phase 3: Update Models

### 3.1 Add partner relationship to JournalLine

**File:** `app/Modules/Accounting/Domain/JournalLine.php`

Add to the model:

```php
<?php

// Add to imports
use App\Modules\Partner\Domain\Partner;

// Add to $fillable array
protected $fillable = [
    // ... existing fields
    'partner_id',
];

// Add relationship
public function partner(): BelongsTo
{
    return $this->belongsTo(Partner::class);
}

// Add scope for partner lines
public function scopeForPartner(Builder $query, string $partnerId): Builder
{
    return $query->where('partner_id', $partnerId);
}

// Add scope for lines with any partner (subledger entries)
public function scopeWithPartner(Builder $query): Builder
{
    return $query->whereNotNull('partner_id');
}
```

### 3.2 Add balance fields and payment_type to Partner model

**File:** `app/Modules/Partner/Domain/Partner.php`

Add to the model:

```php
<?php

// Add to $fillable array
protected $fillable = [
    // ... existing fields
    'receivable_balance',
    'credit_balance',
    'payable_balance',
    'balance_updated_at',
];

// Add to $casts array
protected $casts = [
    // ... existing casts
    'receivable_balance' => 'decimal:4',
    'credit_balance' => 'decimal:4',
    'payable_balance' => 'decimal:4',
    'balance_updated_at' => 'datetime',
];

/**
 * Get the net balance for this partner
 * Positive = they owe us (receivable) or we owe them (payable)
 */
public function getNetBalanceAttribute(): string
{
    if ($this->type === 'customer') {
        // Customer: receivable minus any credit they have
        return bcsub($this->receivable_balance ?? '0', $this->credit_balance ?? '0', 4);
    }
    
    // Supplier: what we owe them
    return $this->payable_balance ?? '0';
}

/**
 * Check if partner has outstanding balance
 */
public function hasOutstandingBalance(): bool
{
    return bccomp($this->net_balance, '0', 4) !== 0;
}

/**
 * Check if balance cache is stale (older than threshold)
 */
public function isBalanceStale(int $minutes = 60): bool
{
    if (!$this->balance_updated_at) {
        return true;
    }
    
    return $this->balance_updated_at->diffInMinutes(now()) > $minutes;
}
```

### 3.3 Add payment_type to Payment model

**File:** `app/Modules/Treasury/Domain/Payment.php`

Add to the model:

```php
<?php

// Add to imports
use App\Modules\Treasury\Domain\Enums\PaymentType;

// Add to $fillable array
protected $fillable = [
    // ... existing fields
    'payment_type',
];

// Add to $casts array
protected $casts = [
    // ... existing casts
    'payment_type' => PaymentType::class,
];

/**
 * Scope to filter by payment type
 */
public function scopeOfType(Builder $query, PaymentType $type): Builder
{
    return $query->where('payment_type', $type->value);
}

/**
 * Scope for incoming payments only
 */
public function scopeIncoming(Builder $query): Builder
{
    return $query->whereIn('payment_type', [
        PaymentType::DOCUMENT_PAYMENT->value,
        PaymentType::ADVANCE->value,
    ]);
}

/**
 * Scope for outgoing payments only
 */
public function scopeOutgoing(Builder $query): Builder
{
    return $query->whereIn('payment_type', [
        PaymentType::REFUND->value,
        PaymentType::SUPPLIER_PAYMENT->value,
    ]);
}

/**
 * Check if this is an advance payment
 */
public function isAdvance(): bool
{
    return $this->payment_type === PaymentType::ADVANCE;
}
```

### Verification for Phase 3

```bash
php artisan tinker --execute="
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Payment;

echo '=== JournalLine ===' . PHP_EOL;
\$line = new JournalLine();
echo 'partner_id in fillable: ' . (in_array('partner_id', \$line->getFillable()) ? '✓' : '✗') . PHP_EOL;
echo 'partner() method exists: ' . (method_exists(\$line, 'partner') ? '✓' : '✗') . PHP_EOL;

echo PHP_EOL . '=== Partner ===' . PHP_EOL;
\$partner = new Partner();
echo 'receivable_balance in fillable: ' . (in_array('receivable_balance', \$partner->getFillable()) ? '✓' : '✗') . PHP_EOL;
echo 'credit_balance in fillable: ' . (in_array('credit_balance', \$partner->getFillable()) ? '✓' : '✗') . PHP_EOL;
echo 'payable_balance in fillable: ' . (in_array('payable_balance', \$partner->getFillable()) ? '✓' : '✗') . PHP_EOL;

echo PHP_EOL . '=== Payment ===' . PHP_EOL;
\$payment = new Payment();
echo 'payment_type in fillable: ' . (in_array('payment_type', \$payment->getFillable()) ? '✓' : '✗') . PHP_EOL;
\$casts = \$payment->getCasts();
echo 'payment_type cast: ' . (isset(\$casts['payment_type']) ? '✓' : '✗') . PHP_EOL;
"
```

---

## Phase 4: Create PartnerBalanceService

### 4.1 Create the service

**File:** `app/Modules/Accounting/Application/Services/PartnerBalanceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PartnerBalanceService
{
    /**
     * Get the current balance for a specific partner
     * 
     * For customers: positive = they owe us, negative = we owe them (credit balance)
     * For suppliers: positive = we owe them, negative = they owe us (debit balance)
     * 
     * @param string $companyId
     * @param string $partnerId
     * @param SystemAccountPurpose|null $purpose Filter to specific account purpose (e.g., only AR)
     * @return array{balance: string, debit_total: string, credit_total: string, transaction_count: int}
     */
    public function getPartnerBalance(
        string $companyId,
        string $partnerId,
        ?SystemAccountPurpose $purpose = null
    ): array {
        $query = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.partner_id', $partnerId)
            ->where('journal_entries.status', 'posted'); // Only posted entries
        
        if ($purpose) {
            $query->where('accounts.system_purpose', $purpose->value);
        }
        
        $result = $query->selectRaw('
            COALESCE(SUM(journal_lines.debit), 0) as debit_total,
            COALESCE(SUM(journal_lines.credit), 0) as credit_total,
            COUNT(*) as transaction_count
        ')->first();
        
        $debitTotal = $result->debit_total ?? '0';
        $creditTotal = $result->credit_total ?? '0';
        $balance = bcsub($debitTotal, $creditTotal, 4);
        
        return [
            'balance' => $balance,
            'debit_total' => $debitTotal,
            'credit_total' => $creditTotal,
            'transaction_count' => (int) ($result->transaction_count ?? 0),
        ];
    }
    
    /**
     * Get customer receivable balance (what they owe us)
     */
    public function getCustomerReceivableBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
        return $result['balance'];
    }
    
    /**
     * Get customer advance balance (prepayments/credits we owe them)
     */
    public function getCustomerAdvanceBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::CUSTOMER_ADVANCE);
        return $result['balance'];
    }
    
    /**
     * Get supplier payable balance (what we owe them)
     */
    public function getSupplierPayableBalance(string $companyId, string $partnerId): string
    {
        $result = $this->getPartnerBalance($companyId, $partnerId, SystemAccountPurpose::SUPPLIER_PAYABLE);
        return $result['balance'];
    }
    
    /**
     * Get all partner balances for a company (for listing/reporting)
     * 
     * @param string $companyId
     * @param SystemAccountPurpose $purpose Which subledger (AR, AP, etc.)
     * @param bool $excludeZeroBalances
     * @return Collection
     */
    public function getAllPartnerBalances(
        string $companyId,
        SystemAccountPurpose $purpose,
        bool $excludeZeroBalances = true
    ): Collection {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->join('partners', 'journal_lines.partner_id', '=', 'partners.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('accounts.system_purpose', $purpose->value)
            ->where('journal_entries.status', 'posted')
            ->whereNotNull('journal_lines.partner_id')
            ->groupBy('journal_lines.partner_id', 'partners.name', 'partners.code')
            ->selectRaw('
                journal_lines.partner_id,
                partners.name as partner_name,
                partners.code as partner_code,
                COALESCE(SUM(journal_lines.debit), 0) as debit_total,
                COALESCE(SUM(journal_lines.credit), 0) as credit_total,
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as balance,
                COUNT(*) as transaction_count
            ');
        
        if ($excludeZeroBalances) {
            $query->havingRaw('ABS(COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0)) > 0.0001');
        }
        
        return $query->orderBy('partners.name')->get();
    }
    
    /**
     * Get subledger total (sum of all partner balances for an account purpose)
     * This should match the control account balance
     */
    public function getSubledgerTotal(string $companyId, SystemAccountPurpose $purpose): string
    {
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('accounts.system_purpose', $purpose->value)
            ->where('journal_entries.status', 'posted')
            ->whereNotNull('journal_lines.partner_id')
            ->selectRaw('
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as total
            ')
            ->first();
        
        return $result->total ?? '0';
    }
    
    /**
     * Get control account balance (total balance of the account itself)
     */
    public function getControlAccountBalance(string $companyId, SystemAccountPurpose $purpose): string
    {
        $account = Account::findByPurposeOrFail($companyId, $purpose);
        
        $result = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('
                COALESCE(SUM(journal_lines.debit), 0) - COALESCE(SUM(journal_lines.credit), 0) as total
            ')
            ->first();
        
        return $result->total ?? '0';
    }
    
    /**
     * Reconcile subledger against control account
     * 
     * Returns reconciliation status:
     * - If balanced: difference = 0
     * - If not balanced: shows the discrepancy
     * 
     * Discrepancies can occur if:
     * - Journal entries posted to control account without partner_id
     * - Data migration issues
     * - Manual journal entries
     */
    public function reconcileSubledger(string $companyId, SystemAccountPurpose $purpose): array
    {
        $subledgerTotal = $this->getSubledgerTotal($companyId, $purpose);
        $controlBalance = $this->getControlAccountBalance($companyId, $purpose);
        $difference = bcsub($controlBalance, $subledgerTotal, 4);
        
        // Find entries without partner_id (potential cause of discrepancy)
        $account = Account::findByPurposeOrFail($companyId, $purpose);
        $entriesWithoutPartner = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.account_id', $account->id)
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_lines.partner_id')
            ->count();
        
        return [
            'is_balanced' => bccomp($difference, '0', 4) === 0,
            'control_account_balance' => $controlBalance,
            'subledger_total' => $subledgerTotal,
            'difference' => $difference,
            'entries_without_partner' => $entriesWithoutPartner,
            'account_code' => $account->code,
            'account_name' => $account->name,
        ];
    }
    
    /**
     * Get partner statement (list of transactions for a partner)
     */
    public function getPartnerStatement(
        string $companyId,
        string $partnerId,
        ?SystemAccountPurpose $purpose = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): Collection {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->join('accounts', 'journal_lines.account_id', '=', 'accounts.id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_lines.partner_id', $partnerId)
            ->where('journal_entries.status', 'posted')
            ->select([
                'journal_entries.id as entry_id',
                'journal_entries.entry_date',
                'journal_entries.reference',
                'journal_entries.description',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.description as line_description',
            ])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id');
        
        if ($purpose) {
            $query->where('accounts.system_purpose', $purpose->value);
        }
        
        if ($fromDate) {
            $query->where('journal_entries.entry_date', '>=', $fromDate);
        }
        
        if ($toDate) {
            $query->where('journal_entries.entry_date', '<=', $toDate);
        }
        
        // Add running balance
        $transactions = $query->get();
        $runningBalance = '0';
        
        return $transactions->map(function ($tx) use (&$runningBalance) {
            $runningBalance = bcadd(
                bcsub($runningBalance, $tx->credit ?? '0', 4),
                $tx->debit ?? '0',
                4
            );
            $tx->running_balance = $runningBalance;
            return $tx;
        });
    }
    
    /**
     * Refresh cached balance for a specific partner
     * Call this after any GL entry affecting the partner
     */
    public function refreshPartnerBalance(string $companyId, string $partnerId): void
    {
        $partner = Partner::findOrFail($partnerId);
        
        // Calculate receivable balance (customer: what they owe us)
        $receivableResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        
        // Calculate credit balance (customer advances: what we owe them)
        $creditResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::CUSTOMER_ADVANCE
        );
        
        // Calculate payable balance (supplier: what we owe them)
        $payableResult = $this->getPartnerBalance(
            $companyId,
            $partnerId,
            SystemAccountPurpose::SUPPLIER_PAYABLE
        );
        
        $partner->update([
            'receivable_balance' => $receivableResult['balance'],
            'credit_balance' => $creditResult['balance'],
            'payable_balance' => $payableResult['balance'],
            'balance_updated_at' => now(),
        ]);
    }
    
    /**
     * Refresh cached balances for all partners in a company
     * Useful for batch reconciliation or initial setup
     */
    public function refreshAllPartnerBalances(string $companyId): int
    {
        $partners = Partner::where('company_id', $companyId)->get();
        $count = 0;
        
        foreach ($partners as $partner) {
            $this->refreshPartnerBalance($companyId, $partner->id);
            $count++;
        }
        
        return $count;
    }
    
    /**
     * Get cached balance from partner (fast, for display)
     * Falls back to GL calculation if cache is stale
     */
    public function getCachedOrCalculateBalance(
        string $companyId,
        string $partnerId,
        bool $refreshIfStale = true,
        int $staleMinutes = 60
    ): array {
        $partner = Partner::findOrFail($partnerId);
        
        if ($refreshIfStale && $partner->isBalanceStale($staleMinutes)) {
            $this->refreshPartnerBalance($companyId, $partnerId);
            $partner->refresh();
        }
        
        return [
            'receivable_balance' => $partner->receivable_balance ?? '0',
            'credit_balance' => $partner->credit_balance ?? '0',
            'payable_balance' => $partner->payable_balance ?? '0',
            'net_balance' => $partner->net_balance,
            'balance_updated_at' => $partner->balance_updated_at,
            'is_from_cache' => true,
        ];
    }
}
```

### Verification for Phase 4

```bash
php artisan tinker --execute="
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use ReflectionClass;

\$service = app(PartnerBalanceService::class);
echo 'Service instantiates: ✓' . PHP_EOL;

\$reflection = new ReflectionClass(\$service);
\$methods = [
    'getPartnerBalance',
    'getCustomerReceivableBalance',
    'getSupplierPayableBalance',
    'getAllPartnerBalances',
    'getSubledgerTotal',
    'getControlAccountBalance',
    'reconcileSubledger',
    'getPartnerStatement',
    'refreshPartnerBalance',
    'refreshAllPartnerBalances',
    'getCachedOrCalculateBalance',
];

echo PHP_EOL . 'Methods:' . PHP_EOL;
foreach (\$methods as \$method) {
    echo '  ' . \$method . '(): ' . (\$reflection->hasMethod(\$method) ? '✓' : '✗') . PHP_EOL;
}
"
```

---

## Phase 5: Update GeneralLedgerService

### 5.1 Add partner_id to journal entry creation methods

The `GeneralLedgerService` must now accept and pass `partner_id` when creating journal entries for AR/AP transactions. It should also call `PartnerBalanceService::refreshPartnerBalance()` after creating entries.

**File:** `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

Update the methods that create AR/AP entries:

```php
<?php

// Add to imports
use App\Modules\Accounting\Application\Services\PartnerBalanceService;

// Add to constructor
public function __construct(
    private PartnerBalanceService $partnerBalanceService
) {}

/**
 * Create journal entry for a validated invoice
 * 
 * @param string $companyId
 * @param string $partnerId Customer ID for this invoice
 * @param string $invoiceId Reference to the invoice
 * @param string $totalAmount Invoice total including tax
 * @param string $netAmount Invoice amount before tax
 * @param string $vatAmount VAT amount
 * @param \DateTimeInterface $date Entry date
 * @param string|null $description
 */
public function createInvoiceJournalEntry(
    string $companyId,
    string $partnerId,  // ADDED
    string $invoiceId,
    string $totalAmount,
    string $netAmount,
    string $vatAmount,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    // Get accounts by purpose (multi-country safe)
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    $revenueAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::PRODUCT_REVENUE);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_COLLECTED);
    
    return DB::transaction(function () use (
        $companyId, $partnerId, $invoiceId, $totalAmount, $netAmount, $vatAmount,
        $date, $description, $receivableAccount, $revenueAccount, $vatAccount
    ) {
        // Create journal entry
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "INV-{$invoiceId}",
            'description' => $description ?? 'Sales invoice',
            'source_type' => 'invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);
        
        // Debit: Accounts Receivable (with partner_id for subledger)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => $totalAmount,
            'credit' => '0',
            'description' => 'Customer receivable',
        ]);
        
        // Credit: Revenue (no partner_id - P&L account)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,  // Revenue doesn't need partner tracking
            'debit' => '0',
            'credit' => $netAmount,
            'description' => 'Sales revenue',
        ]);
        
        // Credit: VAT Collected (no partner_id - tax account)
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
}

/**
 * Create journal entry for a credit note (reverses invoice)
 */
public function createCreditNoteJournalEntry(
    string $companyId,
    string $partnerId,  // ADDED
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
    
    return DB::transaction(function () use (
        $companyId, $partnerId, $creditNoteId, $totalAmount, $netAmount, $vatAmount,
        $date, $description, $receivableAccount, $revenueAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "CN-{$creditNoteId}",
            'description' => $description ?? 'Credit note',
            'source_type' => 'credit_note',
            'source_id' => $creditNoteId,
            'status' => 'posted',
        ]);
        
        // Credit: Accounts Receivable (reduces what customer owes)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Credit note - reduce receivable',
        ]);
        
        // Debit: Revenue (reduces revenue)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => $netAmount,
            'credit' => '0',
            'description' => 'Credit note - reduce revenue',
        ]);
        
        // Debit: VAT (reduces VAT liability)
        if (bccomp($vatAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => null,
                'debit' => $vatAmount,
                'credit' => '0',
                'description' => 'Credit note - reduce VAT',
            ]);
        }
        
        return $entry;
    });
}

/**
 * Create journal entry for customer payment received
 */
public function createPaymentReceivedJournalEntry(
    string $companyId,
    string $partnerId,  // ADDED
    string $paymentId,
    string $amount,
    string $paymentMethodAccountId,  // Bank or Cash account ID
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    
    return DB::transaction(function () use (
        $companyId, $partnerId, $paymentId, $amount, $paymentMethodAccountId,
        $date, $description, $receivableAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "PMT-{$paymentId}",
            'description' => $description ?? 'Payment received',
            'source_type' => 'payment',
            'source_id' => $paymentId,
            'status' => 'posted',
        ]);
        
        // Debit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentMethodAccountId,
            'partner_id' => null,  // Bank account doesn't need partner
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Payment received',
        ]);
        
        // Credit: Accounts Receivable (reduces what customer owes)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Payment applied to receivable',
        ]);
        
        return $entry;
    });
}

/**
 * Create journal entry for customer advance/prepayment
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
    
    return DB::transaction(function () use (
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
            'description' => 'Advance received',
        ]);
        
        // Credit: Customer Advances (liability - we owe them)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $advanceAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Customer advance liability',
        ]);
        
        return $entry;
    });
}

// SUPPLIER SIDE

/**
 * Create journal entry for supplier invoice (purchase)
 */
public function createSupplierInvoiceJournalEntry(
    string $companyId,
    string $partnerId,  // Supplier ID
    string $invoiceId,
    string $totalAmount,
    string $netAmount,
    string $vatAmount,
    string $expenseAccountId,  // Which expense account to use
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $payableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SUPPLIER_PAYABLE);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_DEDUCTIBLE);
    
    return DB::transaction(function () use (
        $companyId, $partnerId, $invoiceId, $totalAmount, $netAmount, $vatAmount,
        $expenseAccountId, $date, $description, $payableAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "PINV-{$invoiceId}",
            'description' => $description ?? 'Supplier invoice',
            'source_type' => 'supplier_invoice',
            'source_id' => $invoiceId,
            'status' => 'posted',
        ]);
        
        // Debit: Expense/Inventory account
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $expenseAccountId,
            'partner_id' => null,  // Expense accounts don't need partner
            'debit' => $netAmount,
            'credit' => '0',
            'description' => 'Purchase expense',
        ]);
        
        // Debit: VAT Deductible
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
        
        // Credit: Accounts Payable
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Supplier payable',
        ]);
        
        return $entry;
    });
}

/**
 * Create journal entry for supplier payment
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
    
    return DB::transaction(function () use (
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
        
        // Debit: Accounts Payable (reduces what we owe)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $payableAccount->id,
            'partner_id' => $partnerId,  // SUBLEDGER LINK
            'debit' => $amount,
            'credit' => '0',
            'description' => 'Payment to supplier',
        ]);
        
        // Credit: Bank/Cash
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $paymentMethodAccountId,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
            'description' => 'Payment from bank',
        ]);
        
        return $entry;
    });
}
```

### Verification for Phase 5

```bash
# Check GeneralLedgerService has partner_id in method signatures
grep -n "partnerId" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | head -20

# Check it creates journal lines with partner_id
grep -A 5 "JournalLine::create" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | grep "partner_id"

# Check it has PartnerBalanceService dependency
grep "PartnerBalanceService" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
```

---

## Phase 6: API Endpoints for Partner Balances

### 6.1 Create PartnerBalanceController

**File:** `app/Modules/Accounting/Presentation/Controllers/PartnerBalanceController.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerBalanceController extends Controller
{
    public function __construct(
        private PartnerBalanceService $balanceService
    ) {}
    
    /**
     * GET /api/v1/companies/{companyId}/partners/{partnerId}/balance
     * Get balance for a specific partner
     */
    public function show(Request $request, string $companyId, string $partnerId): JsonResponse
    {
        $purpose = $request->query('purpose')
            ? SystemAccountPurpose::from($request->query('purpose'))
            : null;
        
        $balance = $this->balanceService->getPartnerBalance($companyId, $partnerId, $purpose);
        
        return response()->json($balance);
    }
    
    /**
     * GET /api/v1/companies/{companyId}/partners/{partnerId}/statement
     * Get transaction statement for a partner
     */
    public function statement(Request $request, string $companyId, string $partnerId): JsonResponse
    {
        $purpose = $request->query('purpose')
            ? SystemAccountPurpose::from($request->query('purpose'))
            : null;
        
        $statement = $this->balanceService->getPartnerStatement(
            $companyId,
            $partnerId,
            $purpose,
            $request->query('from_date'),
            $request->query('to_date')
        );
        
        return response()->json([
            'transactions' => $statement,
            'count' => $statement->count(),
        ]);
    }
    
    /**
     * GET /api/v1/companies/{companyId}/subledger/receivables
     * Get all customer receivable balances
     */
    public function receivables(string $companyId): JsonResponse
    {
        $balances = $this->balanceService->getAllPartnerBalances(
            $companyId,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        
        return response()->json([
            'partners' => $balances,
            'total' => $balances->sum('balance'),
        ]);
    }
    
    /**
     * GET /api/v1/companies/{companyId}/subledger/payables
     * Get all supplier payable balances
     */
    public function payables(string $companyId): JsonResponse
    {
        $balances = $this->balanceService->getAllPartnerBalances(
            $companyId,
            SystemAccountPurpose::SUPPLIER_PAYABLE
        );
        
        return response()->json([
            'partners' => $balances,
            'total' => $balances->sum('balance'),
        ]);
    }
    
    /**
     * GET /api/v1/companies/{companyId}/subledger/reconcile/{purpose}
     * Reconcile subledger against control account
     */
    public function reconcile(string $companyId, string $purpose): JsonResponse
    {
        $purposeEnum = SystemAccountPurpose::from($purpose);
        $result = $this->balanceService->reconcileSubledger($companyId, $purposeEnum);
        
        return response()->json($result);
    }
    
    /**
     * POST /api/v1/companies/{companyId}/partners/{partnerId}/balance/refresh
     * Refresh cached balance for a partner (recalculate from GL)
     */
    public function refresh(string $companyId, string $partnerId): JsonResponse
    {
        $this->balanceService->refreshPartnerBalance($companyId, $partnerId);
        
        $balance = $this->balanceService->getCachedOrCalculateBalance(
            $companyId,
            $partnerId,
            refreshIfStale: false  // Just refreshed, no need to check
        );
        
        return response()->json([
            'message' => 'Balance refreshed successfully',
            'balance' => $balance,
        ]);
    }
}
```

### 6.2 Add Routes

**File:** `app/Modules/Accounting/Presentation/routes.php` (or routes/api.php)

```php
// Partner balance routes
Route::middleware(['auth:sanctum'])->prefix('v1/companies/{companyId}')->group(function () {
    // Partner-specific
    Route::get('partners/{partnerId}/balance', [PartnerBalanceController::class, 'show']);
    Route::get('partners/{partnerId}/statement', [PartnerBalanceController::class, 'statement']);
    
    // Subledger reports
    Route::get('subledger/receivables', [PartnerBalanceController::class, 'receivables']);
    Route::get('subledger/payables', [PartnerBalanceController::class, 'payables']);
    Route::get('subledger/reconcile/{purpose}', [PartnerBalanceController::class, 'reconcile']);
    
    // Balance refresh
    Route::post('partners/{partnerId}/balance/refresh', [PartnerBalanceController::class, 'refresh']);
});
```

### Verification for Phase 6

```bash
php artisan route:list | grep -E "balance|statement|subledger"
```

---

## Phase 7: Tests

### 7.1 Feature Tests

**File:** `tests/Feature/Accounting/PartnerBalanceServiceTest.php`

```php
<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBalanceServiceTest extends TestCase
{
    use RefreshDatabase;
    
    private PartnerBalanceService $service;
    private Company $company;
    private Partner $customer;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(PartnerBalanceService::class);
        
        // Create company with chart of accounts
        $this->company = Company::factory()->create(['country_code' => 'TN']);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        
        // Create a customer
        $this->customer = Partner::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);
    }
    
    public function test_partner_balance_is_zero_with_no_transactions(): void
    {
        $result = $this->service->getPartnerBalance(
            $this->company->id,
            $this->customer->id
        );
        
        $this->assertEquals('0', $result['balance']);
        $this->assertEquals(0, $result['transaction_count']);
    }
    
    public function test_invoice_increases_customer_receivable(): void
    {
        $this->createInvoiceEntry('1000.00');
        
        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );
        
        $this->assertEquals('1000.0000', $balance);
    }
    
    public function test_payment_reduces_customer_receivable(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');
        
        $balance = $this->service->getCustomerReceivableBalance(
            $this->company->id,
            $this->customer->id
        );
        
        $this->assertEquals('600.0000', $balance);
    }
    
    public function test_subledger_matches_control_account(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');
        
        $result = $this->service->reconcileSubledger(
            $this->company->id,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        
        $this->assertTrue($result['is_balanced']);
        $this->assertEquals('0', $result['difference']);
    }
    
    public function test_get_all_partner_balances(): void
    {
        // Create second customer
        $customer2 = Partner::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);
        
        $this->createInvoiceEntry('1000.00'); // customer 1
        $this->createInvoiceEntryForPartner($customer2->id, '500.00'); // customer 2
        
        $balances = $this->service->getAllPartnerBalances(
            $this->company->id,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        
        $this->assertCount(2, $balances);
        $this->assertEquals('1500.0000', $balances->sum('balance'));
    }
    
    public function test_partner_statement_shows_transactions(): void
    {
        $this->createInvoiceEntry('1000.00');
        $this->createPaymentEntry('400.00');
        
        $statement = $this->service->getPartnerStatement(
            $this->company->id,
            $this->customer->id,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        
        $this->assertCount(2, $statement);
        $this->assertEquals('600.0000', $statement->last()->running_balance);
    }
    
    // Helper methods
    
    private function createInvoiceEntry(string $amount): void
    {
        $this->createInvoiceEntryForPartner($this->customer->id, $amount);
    }
    
    private function createInvoiceEntryForPartner(string $partnerId, string $amount): void
    {
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        $revenueAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::PRODUCT_REVENUE
        );
        
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'entry_date' => now(),
            'reference' => 'INV-TEST',
            'description' => 'Test invoice',
            'status' => 'posted',
        ]);
        
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => $amount,
            'credit' => '0',
        ]);
        
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $revenueAccount->id,
            'partner_id' => null,
            'debit' => '0',
            'credit' => $amount,
        ]);
    }
    
    private function createPaymentEntry(string $amount): void
    {
        $receivableAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::CUSTOMER_RECEIVABLE
        );
        $bankAccount = Account::findByPurposeOrFail(
            $this->company->id,
            SystemAccountPurpose::BANK
        );
        
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'entry_date' => now(),
            'reference' => 'PMT-TEST',
            'description' => 'Test payment',
            'status' => 'posted',
        ]);
        
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $bankAccount->id,
            'partner_id' => null,
            'debit' => $amount,
            'credit' => '0',
        ]);
        
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $this->customer->id,
            'debit' => '0',
            'credit' => $amount,
        ]);
    }
}
```

### Verification for Phase 7

```bash
php artisan test --filter=PartnerBalanceService
```

---

## Phase 8: Update Callers (Document Actions)

**IMPORTANT:** The actions that validate invoices, record payments, etc. must now pass `partner_id` to `GeneralLedgerService`. They should also call `refreshPartnerBalance()` after GL entry creation.

Search for and update all callers:

```bash
# Find all places that call GeneralLedgerService methods
grep -rn "generalLedgerService\|GeneralLedgerService" app/ --include="*.php" | grep -v "use\|class\|namespace"
```

Each caller must be updated to pass the `partner_id` parameter.

Example update in `ValidateInvoiceAction`:

```php
// Before
$this->generalLedgerService->createInvoiceJournalEntry(
    $invoice->company_id,
    $invoice->id,
    $invoice->total_amount,
    // ...
);

// After
$this->generalLedgerService->createInvoiceJournalEntry(
    $invoice->company_id,
    $invoice->partner_id,  // ADDED
    $invoice->id,
    $invoice->total_amount,
    // ...
);
```

---

## Final Verification

```bash
echo "=== GL SUBLEDGER VERIFICATION ==="

echo -e "\n1. Migrations applied:"
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'journal_lines.partner_id: ' . (Schema::hasColumn('journal_lines', 'partner_id') ? '✓' : '✗') . PHP_EOL;
echo 'partners.receivable_balance: ' . (Schema::hasColumn('partners', 'receivable_balance') ? '✓' : '✗') . PHP_EOL;
echo 'partners.credit_balance: ' . (Schema::hasColumn('partners', 'credit_balance') ? '✓' : '✗') . PHP_EOL;
echo 'partners.payable_balance: ' . (Schema::hasColumn('partners', 'payable_balance') ? '✓' : '✗') . PHP_EOL;
echo 'payments.payment_type: ' . (Schema::hasColumn('payments', 'payment_type') ? '✓' : '✗') . PHP_EOL;
"

echo -e "\n2. PaymentType enum:"
php artisan tinker --execute="
use App\Modules\Treasury\Domain\Enums\PaymentType;
echo 'PaymentType enum: ' . (enum_exists(PaymentType::class) ? '✓ (' . count(PaymentType::cases()) . ' cases)' : '✗') . PHP_EOL;
"

echo -e "\n3. Models updated:"
php artisan tinker --execute="
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Payment;

\$line = new JournalLine();
echo 'JournalLine.partner_id: ' . (in_array('partner_id', \$line->getFillable()) ? '✓' : '✗') . PHP_EOL;

\$partner = new Partner();
echo 'Partner.receivable_balance: ' . (in_array('receivable_balance', \$partner->getFillable()) ? '✓' : '✗') . PHP_EOL;

\$payment = new Payment();
echo 'Payment.payment_type: ' . (in_array('payment_type', \$payment->getFillable()) ? '✓' : '✗') . PHP_EOL;
"

echo -e "\n4. PartnerBalanceService methods:"
php artisan tinker --execute="
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use ReflectionClass;

\$service = app(PartnerBalanceService::class);
\$reflection = new ReflectionClass(\$service);
\$methods = ['getPartnerBalance', 'refreshPartnerBalance', 'getCachedOrCalculateBalance', 'reconcileSubledger'];

foreach (\$methods as \$m) {
    echo \$m . '(): ' . (\$reflection->hasMethod(\$m) ? '✓' : '✗') . PHP_EOL;
}
"

echo -e "\n5. API routes:"
php artisan route:list | grep -E "balance|statement|subledger|reconcile"

echo -e "\n6. Tests:"
php artisan test --filter=PartnerBalance

echo -e "\n7. PHPStan:"
./vendor/bin/phpstan analyse app/Modules/Accounting app/Modules/Treasury app/Modules/Partner --level=8 --no-progress

echo -e "\n8. All tests:"
php artisan test
```

---

## Summary

| Phase | Description | Estimated Time |
|-------|-------------|----------------|
| 1 | Database migrations (3 tables) | 45 min |
| 2 | PaymentType enum | 20 min |
| 3 | Update models (JournalLine, Partner, Payment) | 30 min |
| 4 | Create PartnerBalanceService (with refresh) | 2 hours |
| 5 | Update GeneralLedgerService | 1 hour |
| 6 | API endpoints | 45 min |
| 7 | Tests | 1 hour |
| 8 | Update callers | 1 hour |
| **Total** | | **~7.5 hours** |

## Commit Strategy

```
feat(treasury): add PaymentType enum
feat(accounting): add partner_id to journal_lines
feat(partner): add cached balance fields to partners
feat(accounting): add PartnerBalanceService for subledger
feat(accounting): update GeneralLedgerService with partner tracking
feat(accounting): add partner balance API endpoints
test(accounting): add PartnerBalanceService tests
refactor(documents): pass partner_id to GL service calls
```
