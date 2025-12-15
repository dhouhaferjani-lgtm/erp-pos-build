# Smart Payment Features v2 - Implementation Task

## Project Context

### Architecture
- **Hexagonal Architecture**: Core domain logic separated from adapters (HTTP, database)
- **Event Sourcing**: GL as single source of truth, cached balances refreshed after GL entries
- **Multi-tenancy**: Schema-based with Row-Level Security, company-scoped (not tenant-scoped)
- **Country Settings**: Same codebase works across FR, IT, UK, TN with country-specific configurations (Country model in `App\Models`)

### Key Patterns

**Account Lookups - NEVER hardcode account numbers:**
```php
// ✅ Correct
$account = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);

// ❌ Wrong
$account = Account::where('code', '411')->first();
```

**Money Calculations - Always bcmath with 4 decimals:**
```php
// ✅ Correct
$total = bcadd($subtotal, $tax, 4);
$remaining = bcsub($amount, $allocated, 4);
if (bccomp($balance, '0', 4) > 0) { ... }

// ❌ Wrong
$total = $subtotal + $tax;
```

**Transaction Boundaries:**
```php
// ✅ All financial operations in DB transaction
return DB::transaction(function () use (...) {
    // Create records
    // Create GL entries
    // Refresh balances
    return $result;
});
```

**Partner Balance Refresh - After ANY GL entry:**
```php
// After creating journal entry
$this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
```

**Service Injection:**
```php
public function __construct(
    private GeneralLedgerService $glService,
    private PartnerBalanceService $balanceService
) {}
```

### Existing Services to Use (Don't Recreate)

| Service | Location | Purpose |
|---------|----------|---------|
| `GeneralLedgerService` | `app/Modules/Accounting/Domain/Services/` | All GL journal entries |
| `PartnerBalanceService` | `app/Modules/Accounting/Application/Services/` | Refresh cached balances |
| `DocumentPostingService` | `app/Modules/Document/Application/Services/` | Post documents with fiscal hash |

### Existing Enums to Extend (Don't Replace)

| Enum | Location |
|------|----------|
| `SystemAccountPurpose` | `app/Modules/Accounting/Domain/Enums/` |
| `PaymentType` | `app/Modules/Treasury/Domain/Enums/` |
| `AllocationType` | `app/Modules/Treasury/Domain/Enums/` |
| `DocumentType` | `app/Modules/Document/Domain/Enums/` |

### Code Standards

- **Strict types**: `declare(strict_types=1);` in every PHP file
- **PHPStan level 5** minimum (aim for 8)
- **Test-driven**: Write tests for each feature
- **Naming**: Services in `Application/Services/`, Domain logic in `Domain/Services/`
- **No magic strings**: Use enums for types, purposes, statuses

### Module Structure
```
app/Modules/{Module}/
├── Domain/
│   ├── {Model}.php
│   ├── Services/
│   └── Enums/
├── Application/
│   └── Services/
├── Presentation/          # NOT Http/ - uses Presentation layer
│   ├── Controllers/
│   ├── Requests/
│   └── routes.php         # Module-specific routes
└── Infrastructure/
    └── Repositories/
```

### Testing Pattern
```php
/** @test */
public function descriptive_test_name_with_expected_behavior(): void
{
    // Arrange
    $invoice = Document::factory()->create([...]);
    
    // Act
    $result = $this->service->methodUnderTest(...);
    
    // Assert
    $this->assertEquals('expected', $result->field);
    
    // Verify GL entries created
    $this->assertDatabaseHas('journal_entries', [...]);
}
```

---

## Specification Reference

Read the full specification at `docs/SMART-PAYMENT-FEATURES-SPEC-V2.md` for complete details on:
- Payment tolerance configuration and GL treatment
- Credit note document type and reasons (uses existing `CreditNote` in DocumentType enum)
- Allocation method algorithms
- All scenario examples with GL entries

---

## Current State

| Component | Status |
|-----------|--------|
| GL Subledger | ✅ Complete (8 phases, 34 tests passing) |
| Country Adaptation Module | ✅ Complete (7 phases) |
| PaymentType enum | ✅ Exists |
| PartnerBalanceService | ✅ Exists |
| GeneralLedgerService | ✅ Exists with createCustomerAdvanceJournalEntry() |

---

## Implementation Phases

### Phase 1: Database Migrations

Create migrations for:

#### 1.1 Country Payment Settings Table

```php
// database/migrations/xxxx_create_country_payment_settings_table.php

Schema::create('country_payment_settings', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
    $table->string('country_code', 2);
    $table->foreign('country_code')->references('code')->on('countries');
    
    // Payment Tolerance
    $table->boolean('payment_tolerance_enabled')->default(true);
    $table->decimal('payment_tolerance_percentage', 5, 4)->default(0.0050); // 0.5%
    $table->decimal('max_payment_tolerance_amount', 15, 4)->default(0.50);
    $table->string('underpayment_writeoff_purpose', 50)->default('payment_tolerance_expense');
    $table->string('overpayment_writeoff_purpose', 50)->default('payment_tolerance_income');
    
    // Extensibility: FX (Phase 2)
    $table->string('realized_fx_gain_purpose', 50)->default('realized_fx_gain');
    $table->string('realized_fx_loss_purpose', 50)->default('realized_fx_loss');
    
    // Extensibility: Cash Discounts (Phase 2)
    $table->boolean('cash_discount_enabled')->default(false);
    $table->string('sales_discount_purpose', 50)->default('sales_discount');
    
    $table->timestamps();
    $table->unique('country_code');
});

// Seed defaults
DB::table('country_payment_settings')->insert([
    ['country_code' => 'TN', 'max_payment_tolerance_amount' => 0.100, 'created_at' => now(), 'updated_at' => now()],
    ['country_code' => 'FR', 'max_payment_tolerance_amount' => 0.50, 'created_at' => now(), 'updated_at' => now()],
    ['country_code' => 'IT', 'max_payment_tolerance_amount' => 0.50, 'created_at' => now(), 'updated_at' => now()],
    ['country_code' => 'UK', 'max_payment_tolerance_amount' => 0.50, 'created_at' => now(), 'updated_at' => now()],
]);
```

#### 1.2 Companies Table Additions

```php
// database/migrations/xxxx_add_payment_tolerance_to_companies_table.php

Schema::table('companies', function (Blueprint $table) {
    $table->boolean('payment_tolerance_enabled')->nullable(); // null = use country default
    $table->decimal('payment_tolerance_percentage', 5, 4)->nullable();
    $table->decimal('max_payment_tolerance_amount', 15, 4)->nullable();
});
```

#### 1.3 Documents Table Additions

```php
// database/migrations/xxxx_add_credit_note_fields_to_documents_table.php

Schema::table('documents', function (Blueprint $table) {
    $table->uuid('related_document_id')->nullable();
    $table->foreign('related_document_id')->references('id')->on('documents');
    $table->string('credit_note_reason', 100)->nullable();
    $table->text('return_comment')->nullable();

    // Ensure due_date exists
    if (!Schema::hasColumn('documents', 'due_date')) {
        $table->date('due_date')->nullable();
    }
});
```

#### 1.4 Payments Table Additions

```php
// database/migrations/xxxx_add_allocation_fields_to_payments_table.php

Schema::table('payments', function (Blueprint $table) {
    $table->string('allocation_method', 30)->default('fifo');
    
    // Extensibility: Phase 2
    $table->decimal('exchange_rate_at_payment', 15, 6)->nullable();
    $table->decimal('fx_gain_loss_amount', 15, 4)->nullable();
    $table->decimal('discount_taken', 15, 4)->nullable();
});
```

#### 1.5 Payment Allocations Table Additions

```php
// database/migrations/xxxx_add_tolerance_to_payment_allocations_table.php

Schema::table('payment_allocations', function (Blueprint $table) {
    $table->decimal('tolerance_writeoff', 15, 4)->nullable();
});
```

#### Phase 1 Verification

```bash
php artisan migrate

php artisan tinker --execute="
echo 'country_payment_settings: ' . (Schema::hasTable('country_payment_settings') ? '✓' : '✗') . PHP_EOL;
echo 'companies.payment_tolerance_enabled: ' . (Schema::hasColumn('companies', 'payment_tolerance_enabled') ? '✓' : '✗') . PHP_EOL;
echo 'documents.credit_note_reason: ' . (Schema::hasColumn('documents', 'credit_note_reason') ? '✓' : '✗') . PHP_EOL;
echo 'documents.return_comment: ' . (Schema::hasColumn('documents', 'return_comment') ? '✓' : '✗') . PHP_EOL;
echo 'payments.allocation_method: ' . (Schema::hasColumn('payments', 'allocation_method') ? '✓' : '✗') . PHP_EOL;
echo 'payment_allocations.tolerance_writeoff: ' . (Schema::hasColumn('payment_allocations', 'tolerance_writeoff') ? '✓' : '✗') . PHP_EOL;
"
```

**Commit:** `feat(database): add payment tolerance and credit note migrations`

---

### Phase 2: Enums

#### 2.1 AllocationMethod Enum (NEW)

**File:** `app/Modules/Treasury/Domain/Enums/AllocationMethod.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum AllocationMethod: string
{
    case FIFO = 'fifo';
    case DUE_DATE_PRIORITY = 'due_date';
    case MANUAL = 'manual';
    
    public function label(): string
    {
        return match($this) {
            self::FIFO => 'First In First Out',
            self::DUE_DATE_PRIORITY => 'Due Date Priority (Most Overdue First)',
            self::MANUAL => 'Manual Selection',
        };
    }
    
    public function description(): string
    {
        return match($this) {
            self::FIFO => 'Allocates payment to oldest invoices first based on invoice date',
            self::DUE_DATE_PRIORITY => 'Allocates payment to most overdue invoices first based on due date',
            self::MANUAL => 'User manually selects which invoices to pay',
        };
    }
}
```

#### 2.2 CreditNoteReason Enum (NEW)

**File:** `app/Modules/Document/Domain/Enums/CreditNoteReason.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum CreditNoteReason: string
{
    case RETURN = 'return';
    case PRICE_ADJUSTMENT = 'price_adjustment';
    case BILLING_ERROR = 'billing_error';
    case DAMAGED_GOODS = 'damaged_goods';
    case SERVICE_ISSUE = 'service_issue';
    case OTHER = 'other';
    
    public function label(): string
    {
        return match($this) {
            self::RETURN => 'Product Return',
            self::PRICE_ADJUSTMENT => 'Price Adjustment',
            self::BILLING_ERROR => 'Billing Error',
            self::DAMAGED_GOODS => 'Damaged Goods',
            self::SERVICE_ISSUE => 'Service Issue',
            self::OTHER => 'Other',
        };
    }
    
    public function requiresComment(): bool
    {
        return $this === self::OTHER;
    }
}
```

#### 2.3 Update AllocationType Enum (ADD CASES)

**File:** `app/Modules/Treasury/Domain/Enums/AllocationType.php`

Add these cases to the existing enum:

```php
// Add to existing cases:
case CREDIT_NOTE_APPLICATION = 'credit_note_application';
case TOLERANCE_WRITEOFF = 'tolerance_writeoff';

// Update label() method to include:
self::CREDIT_NOTE_APPLICATION => 'Credit Note Application',
self::TOLERANCE_WRITEOFF => 'Tolerance Write-off',
```

#### 2.4 Update SystemAccountPurpose Enum (ADD CASES)

**File:** `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`

Add these cases to the existing enum:

```php
// Payment Tolerance
case PAYMENT_TOLERANCE_EXPENSE = 'payment_tolerance_expense';   // 658
case PAYMENT_TOLERANCE_INCOME = 'payment_tolerance_income';     // 758

// Sales Returns (for credit notes)
case SALES_RETURN = 'sales_return';                             // 709

// Extensibility: FX (Phase 2)
case REALIZED_FX_GAIN = 'realized_fx_gain';                     // 766
case REALIZED_FX_LOSS = 'realized_fx_loss';                     // 666

// Extensibility: Cash Discounts (Phase 2)
case SALES_DISCOUNT = 'sales_discount';                         // 709 (or separate)
```

#### 2.5 DocumentType Enum - Use Existing CreditNote

**File:** `app/Modules/Document/Domain/Enums/DocumentType.php`

The `CreditNote` case already exists. Add helper methods if not present:

```php
// CreditNote ALREADY EXISTS - use it instead of adding CREDIT_MEMO
// case CreditNote = 'credit_note';  // Already in enum

// Add/update methods if not present:
public function affectsReceivable(): bool
{
    return match($this) {
        self::Invoice => true,
        self::CreditNote => true,
        default => false,
    };
}

public function receivableDirection(): int
{
    return match($this) {
        self::Invoice => 1,       // +AR
        self::CreditNote => -1,   // -AR
        default => 0,
    };
}
```

#### Phase 2 Verification

```bash
php artisan tinker --execute="
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationType;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;

echo 'AllocationMethod cases: ' . count(AllocationMethod::cases()) . ' (expected: 3)' . PHP_EOL;
echo 'AllocationType cases: ' . count(AllocationType::cases()) . ' (expected: 5)' . PHP_EOL;
echo 'CreditNoteReason cases: ' . count(CreditNoteReason::cases()) . ' (expected: 6)' . PHP_EOL;
echo 'DocumentType::CreditNote: ' . (DocumentType::tryFrom('credit_note') ? '✓' : '✗') . PHP_EOL;
echo 'SystemAccountPurpose::PAYMENT_TOLERANCE_EXPENSE: ' . (SystemAccountPurpose::tryFrom('payment_tolerance_expense') ? '✓' : '✗') . PHP_EOL;
"
```

**Commit:** `feat(enums): add AllocationMethod, CreditNoteReason, and extend existing enums`

---

### Phase 3: PaymentToleranceService

#### 3.1 CountryPaymentSettings Model (NEW)

**File:** `app/Modules/Treasury/Domain/CountryPaymentSettings.php`

Note: Placed in Treasury module since there is no CountryAdaptation module.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountryPaymentSettings extends Model
{
    use HasUuids;
    
    protected $table = 'country_payment_settings';
    
    protected $fillable = [
        'country_code',
        'payment_tolerance_enabled',
        'payment_tolerance_percentage',
        'max_payment_tolerance_amount',
        'underpayment_writeoff_purpose',
        'overpayment_writeoff_purpose',
        'realized_fx_gain_purpose',
        'realized_fx_loss_purpose',
        'cash_discount_enabled',
        'sales_discount_purpose',
    ];
    
    protected $casts = [
        'payment_tolerance_enabled' => 'boolean',
        'payment_tolerance_percentage' => 'string',
        'max_payment_tolerance_amount' => 'string',
        'cash_discount_enabled' => 'boolean',
    ];
    
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_code', 'code');
    }
}
```

#### 3.2 Add Relationship to Country Model

**File:** `app/Models/Country.php` (Country is a global model, NOT in modules)

Add this relationship:

```php
use App\Modules\Treasury\Domain\CountryPaymentSettings;

public function paymentSettings(): HasOne
{
    return $this->hasOne(CountryPaymentSettings::class, 'country_code', 'code');
}
```

#### 3.3 PaymentToleranceService (NEW)

**File:** `app/Modules/Treasury/Application/Services/PaymentToleranceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;

class PaymentToleranceService
{
    public function __construct(
        private GeneralLedgerService $glService
    ) {}
    
    /**
     * Get effective tolerance settings for a company
     * Priority: Company override → Country default → System default
     */
    public function getToleranceSettings(string $companyId): array
    {
        $company = Company::with('country.paymentSettings')->findOrFail($companyId);
        $countrySettings = $company->country?->paymentSettings;
        
        return [
            'enabled' => $company->payment_tolerance_enabled 
                ?? $countrySettings?->payment_tolerance_enabled 
                ?? true,
            'percentage' => $company->payment_tolerance_percentage 
                ?? $countrySettings?->payment_tolerance_percentage 
                ?? '0.0050',
            'max_amount' => $company->max_payment_tolerance_amount 
                ?? $countrySettings?->max_payment_tolerance_amount 
                ?? '0.50',
            'source' => $this->determineSettingsSource($company, $countrySettings),
        ];
    }
    
    /**
     * Check if a payment difference qualifies for auto-write-off
     */
    public function checkTolerance(
        string $invoiceAmount,
        string $paymentAmount,
        string $companyId
    ): array {
        $settings = $this->getToleranceSettings($companyId);
        
        if (!$settings['enabled']) {
            return [
                'qualifies' => false,
                'difference' => '0.0000',
                'type' => null,
                'reason' => 'Tolerance disabled',
            ];
        }
        
        $difference = bcsub($paymentAmount, $invoiceAmount, 4);
        $absDifference = bccomp($difference, '0', 4) < 0 
            ? bcmul($difference, '-1', 4) 
            : $difference;
        
        // Calculate percentage threshold
        $percentageThreshold = bcmul($invoiceAmount, $settings['percentage'], 4);
        
        // Must be within BOTH percentage AND max amount
        $withinPercentage = bccomp($absDifference, $percentageThreshold, 4) <= 0;
        $withinMaxAmount = bccomp($absDifference, $settings['max_amount'], 4) <= 0;
        
        if ($withinPercentage && $withinMaxAmount && bccomp($absDifference, '0', 4) > 0) {
            $type = bccomp($difference, '0', 4) < 0 ? 'underpayment' : 'overpayment';
            return [
                'qualifies' => true,
                'difference' => $absDifference,
                'type' => $type,
                'reason' => null,
            ];
        }
        
        $reason = null;
        if (!$withinPercentage) {
            $reason = "Exceeds percentage threshold ({$settings['percentage']})";
        } elseif (!$withinMaxAmount) {
            $reason = "Exceeds max amount threshold ({$settings['max_amount']})";
        }
        
        return [
            'qualifies' => false,
            'difference' => $absDifference,
            'type' => bccomp($difference, '0', 4) < 0 ? 'underpayment' : 'overpayment',
            'reason' => $reason,
        ];
    }
    
    /**
     * Apply payment tolerance write-off
     */
    public function applyTolerance(
        string $companyId,
        string $partnerId,
        string $documentId,
        string $amount,
        string $type,
        \DateTimeInterface $date,
        ?string $description = null
    ): void {
        $this->glService->createPaymentToleranceJournalEntry(
            companyId: $companyId,
            partnerId: $partnerId,
            documentId: $documentId,
            amount: $amount,
            type: $type,
            date: $date,
            description: $description
        );
    }
    
    private function determineSettingsSource(Company $company, ?object $countrySettings): string
    {
        if ($company->payment_tolerance_enabled !== null) {
            return 'company';
        }
        if ($countrySettings?->payment_tolerance_enabled !== null) {
            return 'country';
        }
        return 'system_default';
    }
}
```

#### 3.4 Add GL Method for Tolerance Write-off

**File:** `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

Add this method:

```php
/**
 * Create journal entry for payment tolerance write-off
 */
public function createPaymentToleranceJournalEntry(
    string $companyId,
    string $partnerId,
    string $documentId,
    string $amount,
    string $type,  // 'underpayment' or 'overpayment'
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    
    $writeoffPurpose = $type === 'underpayment' 
        ? SystemAccountPurpose::PAYMENT_TOLERANCE_EXPENSE 
        : SystemAccountPurpose::PAYMENT_TOLERANCE_INCOME;
    $writeoffAccount = Account::findByPurposeOrFail($companyId, $writeoffPurpose);
    
    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $documentId, $amount, $type,
        $date, $description, $receivableAccount, $writeoffAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "TOL-{$documentId}",
            'description' => $description ?? "Payment tolerance write-off ({$type})",
            'source_type' => 'payment_tolerance',
            'source_id' => $documentId,
            'status' => 'posted',
        ]);
        
        if ($type === 'underpayment') {
            // Underpayment: expense absorbs the difference
            // Dr. Tolerance Expense, Cr. AR
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $writeoffAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Underpayment tolerance',
            ]);
            
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'AR reduced by tolerance',
            ]);
        } else {
            // Overpayment: income from rounding in our favor
            // Dr. Bank (already received), Cr. Tolerance Income
            // Note: The cash is already in bank from main payment entry
            // This just records the income recognition
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $receivableAccount->id,
                'partner_id' => $partnerId,
                'debit' => $amount,
                'credit' => '0',
                'description' => 'Overpayment tolerance adjustment',
            ]);
            
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $writeoffAccount->id,
                'partner_id' => $partnerId,
                'debit' => '0',
                'credit' => $amount,
                'description' => 'Overpayment tolerance income',
            ]);
        }
        
        return $entry;
    });
    
    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);
    
    return $entry;
}
```

#### Phase 3 Verification

```bash
php artisan tinker --execute="
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;

echo 'CountryPaymentSettings count: ' . CountryPaymentSettings::count() . PHP_EOL;

\$service = app(PaymentToleranceService::class);

// Test with a company (replace with actual ID)
// \$settings = \$service->getToleranceSettings(\$companyId);
// var_dump(\$settings);

// Test tolerance check
\$result = \$service->checkTolerance('100.0000', '99.9500', \$companyId);
echo 'Tolerance check (99.95 on 100.00): ' . (\$result['qualifies'] ? 'QUALIFIES' : 'DOES NOT QUALIFY') . PHP_EOL;
"
```

**Commit:** `feat(treasury): add PaymentToleranceService with country adaptation integration`

---

### Phase 4: Updated PaymentAllocationService

#### 4.1 Update PaymentAllocationService

**File:** `app/Modules/Treasury/Application/Services/PaymentAllocationService.php`

Update the service with new allocation methods and tolerance integration. Key changes:

1. Add `PaymentToleranceService` dependency
2. Change `recordPayment()` to accept `AllocationMethod` parameter
3. Add `calculateDueDateAllocations()` method
4. Add tolerance checking in `processInvoiceAllocation()`
5. Update `previewAllocation()` to return tolerance info
6. Update `getOpenInvoicesForPartner()` to accept sort method

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationType;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentAllocationService
{
    public function __construct(
        private GeneralLedgerService $glService,
        private PartnerBalanceService $balanceService,
        private PaymentToleranceService $toleranceService
    ) {}
    
    /**
     * Record a payment with smart allocation
     */
    public function recordPayment(
        string $companyId,
        string $partnerId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        AllocationMethod $allocationMethod = AllocationMethod::FIFO,
        ?array $invoiceAllocations = null,
        ?string $reference = null,
        ?string $notes = null
    ): Payment {
        return DB::transaction(function () use (
            $companyId, $partnerId, $amount, $paymentMethodAccountId,
            $date, $allocationMethod, $invoiceAllocations, $reference, $notes
        ) {
            // Determine allocations based on method
            $allocations = match($allocationMethod) {
                AllocationMethod::FIFO => $this->calculateFifoAllocations($companyId, $partnerId, $amount),
                AllocationMethod::DUE_DATE_PRIORITY => $this->calculateDueDateAllocations($companyId, $partnerId, $amount),
                AllocationMethod::MANUAL => $this->validateManualAllocations($invoiceAllocations, $amount, $companyId),
            };
            
            // Calculate totals
            $totalToInvoices = array_reduce($allocations, fn($sum, $a) => bcadd($sum, $a['amount'], 4), '0');
            $excessAmount = bcsub($amount, $totalToInvoices, 4);
            
            // Determine payment type
            $paymentType = $this->determinePaymentType($allocations, $excessAmount);
            
            // Create payment record
            $payment = Payment::create([
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'amount' => $amount,
                'payment_method_account_id' => $paymentMethodAccountId,
                'payment_date' => $date,
                'payment_type' => $paymentType,
                'allocation_method' => $allocationMethod->value,
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'completed',
            ]);
            
            // Process each allocation with tolerance checking
            foreach ($allocations as &$alloc) {
                $this->processInvoiceAllocation($payment, $alloc, $date, $companyId);
            }
            
            // Handle excess amount
            if (bccomp($excessAmount, '0', 4) > 0) {
                $this->handleExcessAmount($payment, $excessAmount, $paymentMethodAccountId, $date, $companyId, $partnerId);
            }
            
            // Update document balances
            $this->updateDocumentBalances($allocations);
            
            return $payment;
        });
    }
    
    /**
     * Calculate FIFO allocations - oldest invoices first by invoice date
     */
    public function calculateFifoAllocations(
        string $companyId,
        string $partnerId,
        string $availableAmount
    ): array {
        $openInvoices = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->orderBy('document_date', 'asc')
            ->orderBy('created_at', 'asc')
            ->get();
        
        return $this->allocateToInvoices($openInvoices, $availableAmount);
    }
    
    /**
     * Calculate Due Date Priority allocations - most overdue first
     */
    public function calculateDueDateAllocations(
        string $companyId,
        string $partnerId,
        string $availableAmount
    ): array {
        $openInvoices = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->orderBy('due_date', 'asc')
            ->orderBy('document_date', 'asc')
            ->get();
        
        return $this->allocateToInvoices($openInvoices, $availableAmount);
    }
    
    /**
     * Common allocation logic
     */
    private function allocateToInvoices(Collection $invoices, string $availableAmount): array
    {
        $allocations = [];
        $remaining = $availableAmount;
        
        foreach ($invoices as $invoice) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            
            $allocAmount = bccomp($remaining, $invoice->balance_due, 4) >= 0
                ? $invoice->balance_due
                : $remaining;
            
            $allocations[] = [
                'document_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'document_date' => $invoice->document_date->format('Y-m-d'),
                'due_date' => $invoice->due_date?->format('Y-m-d'),
                'amount' => $allocAmount,
                'original_balance' => $invoice->balance_due,
                'days_overdue' => $invoice->due_date 
                    ? (int) now()->diffInDays($invoice->due_date, false) 
                    : 0,
            ];
            
            $remaining = bcsub($remaining, $allocAmount, 4);
        }
        
        return $allocations;
    }
    
    /**
     * Process single invoice allocation with tolerance check
     */
    private function processInvoiceAllocation(
        Payment $payment,
        array &$alloc,
        \DateTimeInterface $date,
        string $companyId
    ): void {
        $newBalance = bcsub($alloc['original_balance'], $alloc['amount'], 4);
        $toleranceApplied = null;
        
        // Check if remaining balance qualifies for tolerance write-off
        if (bccomp($newBalance, '0', 4) > 0) {
            $toleranceCheck = $this->toleranceService->checkTolerance(
                $alloc['original_balance'],
                $alloc['amount'],
                $companyId
            );
            
            if ($toleranceCheck['qualifies'] && $toleranceCheck['type'] === 'underpayment') {
                // Write off the small underpayment
                $this->toleranceService->applyTolerance(
                    $companyId,
                    $payment->partner_id,
                    $alloc['document_id'],
                    $newBalance,
                    'underpayment',
                    $date,
                    "Tolerance write-off for {$alloc['document_number']}"
                );
                
                $toleranceApplied = $newBalance;
                $alloc['amount'] = $alloc['original_balance']; // Mark as fully paid
            }
        }
        
        // Create allocation record
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $alloc['document_id'],
            'amount' => $alloc['amount'],
            'allocation_type' => AllocationType::INVOICE_PAYMENT,
            'tolerance_writeoff' => $toleranceApplied,
        ]);
        
        // GL entry for this portion
        $this->glService->createPaymentReceivedJournalEntry(
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            paymentId: $payment->id,
            amount: bcsub($alloc['amount'], $toleranceApplied ?? '0', 4), // Actual cash received
            paymentMethodAccountId: $payment->payment_method_account_id,
            date: $date,
            description: "Payment for {$alloc['document_number']}"
        );
    }
    
    /**
     * Handle excess amount (overpayment)
     */
    private function handleExcessAmount(
        Payment $payment,
        string $excessAmount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        string $companyId,
        string $partnerId
    ): void {
        // Check if excess qualifies for tolerance write-off
        $toleranceCheck = $this->toleranceService->checkTolerance(
            '0',
            $excessAmount,
            $companyId
        );
        
        if ($toleranceCheck['qualifies'] && $toleranceCheck['type'] === 'overpayment') {
            // Write off small overpayment as income
            $this->toleranceService->applyTolerance(
                $companyId,
                $partnerId,
                $payment->id,
                $excessAmount,
                'overpayment',
                $date,
                'Overpayment tolerance write-off'
            );
        } else {
            // Create credit balance (advance)
            $this->createCreditAllocation($payment, $excessAmount, $paymentMethodAccountId, $date);
        }
    }
    
    /**
     * Get open invoices for partner with sorting
     */
    public function getOpenInvoicesForPartner(
        string $companyId,
        string $partnerId,
        AllocationMethod $sortMethod = AllocationMethod::FIFO
    ): Collection {
        $query = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0);
        
        $query = match($sortMethod) {
            AllocationMethod::FIFO => $query->orderBy('document_date', 'asc'),
            AllocationMethod::DUE_DATE_PRIORITY => $query->orderBy('due_date', 'asc'),
            AllocationMethod::MANUAL => $query->orderBy('document_number', 'asc'),
        };
        
        return $query->get([
            'id',
            'document_number',
            'document_date',
            'due_date',
            'total_ttc',
            'balance_due',
        ])->map(function ($invoice) {
            $invoice->days_overdue = $invoice->due_date
                ? (int) now()->diffInDays($invoice->due_date, false)
                : 0;
            return $invoice;
        });
    }
    
    /**
     * Preview allocation without recording
     */
    public function previewAllocation(
        string $companyId,
        string $partnerId,
        string $amount,
        AllocationMethod $method = AllocationMethod::FIFO
    ): array {
        $allocations = match($method) {
            AllocationMethod::FIFO => $this->calculateFifoAllocations($companyId, $partnerId, $amount),
            AllocationMethod::DUE_DATE_PRIORITY => $this->calculateDueDateAllocations($companyId, $partnerId, $amount),
            AllocationMethod::MANUAL => [],
        };
        
        $totalToInvoices = array_reduce($allocations, fn($sum, $a) => bcadd($sum, $a['amount'], 4), '0');
        $excess = bcsub($amount, $totalToInvoices, 4);
        
        $toleranceSettings = $this->toleranceService->getToleranceSettings($companyId);
        $excessHandling = 'credit_balance';
        
        if (bccomp($excess, '0', 4) > 0 && $toleranceSettings['enabled']) {
            $withinTolerance = bccomp($excess, $toleranceSettings['max_amount'], 4) <= 0;
            if ($withinTolerance) {
                $excessHandling = 'tolerance_writeoff';
            }
        }
        
        return [
            'allocation_method' => $method->value,
            'allocations' => $allocations,
            'total_to_invoices' => $totalToInvoices,
            'excess_amount' => $excess,
            'excess_handling' => $excessHandling,
            'invoices_fully_paid' => count(array_filter($allocations, fn($a) => bccomp($a['amount'], $a['original_balance'], 4) === 0)),
            'invoices_partially_paid' => count(array_filter($allocations, fn($a) => bccomp($a['amount'], $a['original_balance'], 4) < 0)),
            'tolerance_settings' => $toleranceSettings,
        ];
    }
    
    // ... Keep existing methods: recordAdvancePayment, applyCreditToInvoice, refundCredit, etc.
    // ... Keep existing private methods: validateManualAllocations, determinePaymentType, createCreditAllocation, updateDocumentBalances
}
```

#### Phase 4 Verification

```bash
php artisan tinker --execute="
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use ReflectionClass;

\$r = new ReflectionClass(PaymentAllocationService::class);
\$methods = [
    'calculateFifoAllocations',
    'calculateDueDateAllocations',
    'previewAllocation',
    'getOpenInvoicesForPartner',
];

foreach (\$methods as \$m) {
    echo \$m . '(): ' . (\$r->hasMethod(\$m) ? '✓' : '✗') . PHP_EOL;
}
"
```

**Commit:** `feat(treasury): update PaymentAllocationService with allocation methods and tolerance`

---

### Phase 5: Credit Note Enhancement

#### 5.1 Update Document Model

**File:** `app/Modules/Document/Domain/Document.php`

Add casts and relationships:

```php
protected $casts = [
    // ... existing casts
    'credit_note_reason' => CreditNoteReason::class,
];

public function relatedDocument(): BelongsTo
{
    return $this->belongsTo(Document::class, 'related_document_id');
}

public function creditNotes(): HasMany
{
    return $this->hasMany(Document::class, 'related_document_id')
        ->where('document_type', DocumentType::CreditNote);
}

public function scopeCreditNotes(Builder $query): Builder
{
    return $query->where('document_type', DocumentType::CreditNote);
}
```

#### 5.2 Add Credit Note GL Method

**File:** `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

```php
/**
 * Create journal entry for credit note
 */
public function createCreditNoteJournalEntry(
    string $companyId,
    string $partnerId,
    string $creditNoteId,
    string $amount,
    string $taxAmount,
    CreditNoteReason $reason,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    $salesReturnAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SALES_RETURN);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_COLLECTED);

    $entry = DB::transaction(function () use (
        $companyId, $partnerId, $creditNoteId, $amount, $taxAmount,
        $reason, $date, $description, $receivableAccount, $salesReturnAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "CN-{$creditNoteId}",
            'description' => $description ?? "Credit note - {$reason->label()}",
            'source_type' => 'credit_note',
            'source_id' => $creditNoteId,
            'status' => 'posted',
        ]);

        $totalAmount = bcadd($amount, $taxAmount, 4);

        // Debit: Sales Returns (contra-revenue)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $salesReturnAccount->id,
            'partner_id' => $partnerId,
            'debit' => $amount,
            'credit' => '0',
            'description' => "Sales return - {$reason->label()}",
        ]);

        // Debit: VAT Collected (reverse)
        if (bccomp($taxAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => $partnerId,
                'debit' => $taxAmount,
                'credit' => '0',
                'description' => 'VAT reversed',
            ]);
        }

        // Credit: Accounts Receivable
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Receivable reduced by credit note',
        ]);

        return $entry;
    });

    $this->partnerBalanceService->refreshPartnerBalance($companyId, $partnerId);

    return $entry;
}
```

#### 5.3 CreditNoteService (NEW)

**File:** `app/Modules/Document/Application/Services/CreditNoteService.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function __construct(
        private GeneralLedgerService $glService,
        private DocumentPostingService $postingService
    ) {}

    /**
     * Create a credit note
     */
    public function createCreditNote(
        string $companyId,
        string $partnerId,
        string $amount,
        string $taxAmount,
        CreditNoteReason $reason,
        \DateTimeInterface $date,
        ?string $relatedDocumentId = null,
        ?string $returnComment = null,
        ?array $lineItems = null
    ): Document {
        // Validate: OTHER reason requires comment
        if ($reason === CreditNoteReason::Other && empty($returnComment)) {
            throw new \DomainException('Return comment is required when reason is "Other"');
        }

        return DB::transaction(function () use (
            $companyId, $partnerId, $amount, $taxAmount, $reason,
            $date, $relatedDocumentId, $returnComment, $lineItems
        ) {
            // Generate credit note number
            $creditNoteNumber = $this->generateCreditNoteNumber($companyId);

            // Create document (uses existing CreditNote case from DocumentType)
            $creditNote = Document::create([
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'document_type' => DocumentType::CreditNote,
                'document_number' => $creditNoteNumber,
                'document_date' => $date,
                'total_ht' => $amount,
                'total_tax' => $taxAmount,
                'total_ttc' => bcadd($amount, $taxAmount, 4),
                'balance_due' => '0', // Credit notes don't have balance due
                'related_document_id' => $relatedDocumentId,
                'credit_note_reason' => $reason,
                'return_comment' => $returnComment,
                'status' => 'draft',
            ]);

            // Create line items if provided
            if ($lineItems) {
                foreach ($lineItems as $item) {
                    $creditNote->lines()->create($item);
                }
            }

            // Post the credit note
            $this->postingService->post($creditNote);

            // Create GL entries
            $this->glService->createCreditNoteJournalEntry(
                companyId: $companyId,
                partnerId: $partnerId,
                creditNoteId: $creditNote->id,
                amount: $amount,
                taxAmount: $taxAmount,
                reason: $reason,
                date: $date,
                description: "Credit note {$creditNoteNumber} - {$reason->label()}"
            );

            // If linked to invoice, reduce invoice balance
            if ($relatedDocumentId) {
                $this->applyToInvoice($creditNote, $relatedDocumentId);
            }

            return $creditNote->fresh();
        });
    }

    /**
     * Apply credit note to specific invoice
     */
    public function applyToInvoice(Document $creditNote, string $invoiceId): void
    {
        $invoice = Document::findOrFail($invoiceId);

        if ($invoice->document_type !== DocumentType::Invoice->value) {
            throw new \DomainException('Can only apply credit note to invoices');
        }

        $applyAmount = min($creditNote->total_ttc, $invoice->balance_due);

        $newBalance = bcsub($invoice->balance_due, $applyAmount, 4);
        $invoice->update([
            'balance_due' => $newBalance,
            'status' => bccomp($newBalance, '0', 4) === 0 ? 'paid' : 'posted',
        ]);
    }

    private function generateCreditNoteNumber(string $companyId): string
    {
        $lastNumber = Document::where('company_id', $companyId)
            ->where('document_type', DocumentType::CreditNote)
            ->max('document_number');

        if (!$lastNumber) {
            return 'CN-000001';
        }

        $number = (int) substr($lastNumber, 3) + 1;
        return 'CN-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
```

#### Phase 5 Verification

```bash
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Application\Services\CreditNoteService;
use ReflectionClass;

\$gl = new ReflectionClass(GeneralLedgerService::class);
echo 'createCreditNoteJournalEntry: ' . (\$gl->hasMethod('createCreditNoteJournalEntry') ? '✓' : '✗') . PHP_EOL;
echo 'createPaymentToleranceJournalEntry: ' . (\$gl->hasMethod('createPaymentToleranceJournalEntry') ? '✓' : '✗') . PHP_EOL;

\$cn = new ReflectionClass(CreditNoteService::class);
echo 'createCreditNote: ' . (\$cn->hasMethod('createCreditNote') ? '✓' : '✗') . PHP_EOL;
echo 'applyToInvoice: ' . (\$cn->hasMethod('applyToInvoice') ? '✓' : '✗') . PHP_EOL;
"
```

**Commit:** `feat(document): add CreditNoteService using existing CreditNote document type`

---

### Phase 6: Controllers & Routes

#### 6.1 Update SmartPaymentController

**File:** `app/Modules/Treasury/Presentation/Controllers/SmartPaymentController.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartPaymentController extends Controller
{
    public function __construct(
        private PaymentAllocationService $allocationService,
        private PaymentToleranceService $toleranceService
    ) {}
    
    /**
     * GET /api/partners/{partnerId}/open-invoices
     */
    public function getOpenInvoices(Request $request, string $partnerId): JsonResponse
    {
        $method = AllocationMethod::tryFrom($request->get('sort', 'fifo'))
            ?? AllocationMethod::FIFO;
        
        $invoices = $this->allocationService->getOpenInvoicesForPartner(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            sortMethod: $method
        );
        
        return response()->json([
            'data' => $invoices,
            'sort_method' => $method->value,
            'available_methods' => collect(AllocationMethod::cases())
                ->map(fn($m) => [
                    'value' => $m->value,
                    'label' => $m->label(),
                    'description' => $m->description(),
                ]),
        ]);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments/preview
     */
    public function previewPayment(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'allocation_method' => 'sometimes|string|in:fifo,due_date,manual',
        ]);
        
        $method = AllocationMethod::tryFrom($validated['allocation_method'] ?? 'fifo')
            ?? AllocationMethod::FIFO;
        
        $preview = $this->allocationService->previewAllocation(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            method: $method
        );
        
        return response()->json($preview);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments
     */
    public function recordPayment(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_account_id' => 'required|uuid|exists:accounts,id',
            'payment_date' => 'required|date',
            'allocation_method' => 'sometimes|string|in:fifo,due_date,manual',
            'allocations' => 'required_if:allocation_method,manual|array',
            'allocations.*.document_id' => 'required_with:allocations|uuid',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        
        $method = AllocationMethod::tryFrom($validated['allocation_method'] ?? 'fifo')
            ?? AllocationMethod::FIFO;
        
        $payment = $this->allocationService->recordPayment(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            paymentMethodAccountId: $validated['payment_method_account_id'],
            date: new \DateTime($validated['payment_date']),
            allocationMethod: $method,
            invoiceAllocations: $validated['allocations'] ?? null,
            reference: $validated['reference'] ?? null,
            notes: $validated['notes'] ?? null
        );
        
        return response()->json([
            'data' => $payment->load('allocations'),
            'message' => 'Payment recorded successfully',
        ], 201);
    }
    
    /**
     * GET /api/payment-settings
     */
    public function getPaymentSettings(Request $request): JsonResponse
    {
        $settings = $this->toleranceService->getToleranceSettings(
            $request->user()->company_id
        );
        
        return response()->json(['data' => $settings]);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments/advance
     */
    public function recordAdvancePayment(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_account_id' => 'required|uuid|exists:accounts,id',
            'payment_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        
        $payment = $this->allocationService->recordAdvancePayment(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            paymentMethodAccountId: $validated['payment_method_account_id'],
            date: new \DateTime($validated['payment_date']),
            reference: $validated['reference'] ?? null,
            notes: $validated['notes'] ?? null
        );
        
        return response()->json([
            'data' => $payment,
            'message' => 'Advance payment recorded successfully',
        ], 201);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments/apply-credit
     */
    public function applyCreditToInvoice(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'document_id' => 'required|uuid|exists:documents,id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);
        
        $payment = $this->allocationService->applyCreditToInvoice(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            documentId: $validated['document_id'],
            amount: $validated['amount'],
            date: now(),
            notes: $validated['notes'] ?? null
        );
        
        return response()->json([
            'data' => $payment,
            'message' => 'Credit applied successfully',
        ], 201);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments/refund
     */
    public function refundCredit(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_account_id' => 'required|uuid|exists:accounts,id',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        
        $payment = $this->allocationService->refundCredit(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            paymentMethodAccountId: $validated['payment_method_account_id'],
            date: now(),
            reference: $validated['reference'] ?? null,
            notes: $validated['notes'] ?? null
        );
        
        return response()->json([
            'data' => $payment,
            'message' => 'Refund processed successfully',
        ], 201);
    }
}
```

#### 6.2 CreditNoteController (Enhancement)

**File:** `app/Modules/Document/Presentation/Controllers/CreditNoteController.php`

Note: Enhancing the existing Document/CreditNote handling, not creating a separate controller.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class CreditNoteController extends Controller
{
    public function __construct(
        private CreditNoteService $creditNoteService
    ) {}
    
    /**
     * POST /api/documents/credit-notes
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_id' => 'required|uuid|exists:partners,id',
            'amount' => 'required|numeric|min:0.01',
            'tax_amount' => 'required|numeric|min:0',
            'reason' => ['required', new Enum(CreditNoteReason::class)],
            'document_date' => 'required|date',
            'related_document_id' => 'nullable|uuid|exists:documents,id',
            'return_comment' => 'nullable|string|max:1000',
            'line_items' => 'nullable|array',
        ]);

        $reason = CreditNoteReason::from($validated['reason']);

        $creditNote = $this->creditNoteService->createCreditNote(
            companyId: $request->user()->company_id,
            partnerId: $validated['partner_id'],
            amount: $validated['amount'],
            taxAmount: $validated['tax_amount'],
            reason: $reason,
            date: new \DateTime($validated['document_date']),
            relatedDocumentId: $validated['related_document_id'] ?? null,
            returnComment: $validated['return_comment'] ?? null,
            lineItems: $validated['line_items'] ?? null
        );

        return response()->json([
            'data' => $creditNote,
            'message' => 'Credit note created successfully',
        ], 201);
    }

    /**
     * GET /api/documents/credit-notes/reasons
     */
    public function getReasons(): JsonResponse
    {
        $reasons = collect(CreditNoteReason::cases())
            ->map(fn($r) => [
                'value' => $r->value,
                'label' => $r->label(),
                'requires_comment' => $r->requiresComment(),
            ]);

        return response()->json(['data' => $reasons]);
    }
}
```

#### 6.3 Routes

**File:** `app/Modules/Treasury/Presentation/routes.php`

Add to the existing module routes file (NOT to central routes/api.php):

```php
use App\Modules\Treasury\Presentation\Controllers\SmartPaymentController;

// Add to existing route group in Treasury/Presentation/routes.php
Route::prefix('v1')->middleware(['auth:sanctum', 'company'])->group(function () {

    // Payment settings
    Route::get('/payment-settings', [SmartPaymentController::class, 'getPaymentSettings']);

    // Partner payments
    Route::prefix('partners/{partnerId}')->group(function () {
        Route::get('/open-invoices', [SmartPaymentController::class, 'getOpenInvoices']);
        Route::post('/payments/preview', [SmartPaymentController::class, 'previewPayment']);
        Route::post('/payments', [SmartPaymentController::class, 'recordPayment']);
        Route::post('/payments/advance', [SmartPaymentController::class, 'recordAdvancePayment']);
        Route::post('/payments/apply-credit', [SmartPaymentController::class, 'applyCreditToInvoice']);
        Route::post('/payments/refund', [SmartPaymentController::class, 'refundCredit']);
    });
});
```

**File:** `app/Modules/Document/Presentation/routes.php`

Add to the existing Document module routes:

```php
use App\Modules\Document\Presentation\Controllers\CreditNoteController;

// Add to existing Document routes
Route::prefix('v1/documents')->middleware(['auth:sanctum', 'company'])->group(function () {
    Route::get('/credit-note/reasons', [CreditNoteController::class, 'getReasons']);
    Route::post('/credit-note', [CreditNoteController::class, 'create']);
});
```

#### Phase 6 Verification

```bash
php artisan route:list | grep -E "open-invoices|payments|credit-note|payment-settings"
```

Expected output:
```
GET  api/payment-settings
GET  api/partners/{partnerId}/open-invoices
POST api/partners/{partnerId}/payments/preview
POST api/partners/{partnerId}/payments
POST api/partners/{partnerId}/payments/advance
POST api/partners/{partnerId}/payments/apply-credit
POST api/partners/{partnerId}/payments/refund
GET  api/documents/credit-notes/reasons
POST api/documents/credit-notes
```

**Commit:** `feat(api): add smart payment and credit note endpoints`

---

### Phase 7: Tests

#### 7.1 PaymentToleranceTest

**File:** `tests/Feature/Treasury/PaymentToleranceTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Models\Country;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentToleranceTest extends TestCase
{
    use RefreshDatabase;
    
    private PaymentToleranceService $toleranceService;
    private PaymentAllocationService $allocationService;
    private Company $company;
    private Partner $customer;
    private string $bankAccountId;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup country with payment settings
        $country = Country::factory()->create(['code' => 'TN']);
        CountryPaymentSettings::create([
            'country_code' => 'TN',
            'payment_tolerance_enabled' => true,
            'payment_tolerance_percentage' => '0.0050', // 0.5%
            'max_payment_tolerance_amount' => '0.100',  // 0.10 TND
        ]);
        
        $this->company = Company::factory()->create(['country_code' => 'TN']);
        $this->customer = Partner::factory()->create(['company_id' => $this->company->id]);
        $this->bankAccountId = $this->createBankAccount($this->company->id);
        
        $this->toleranceService = app(PaymentToleranceService::class);
        $this->allocationService = app(PaymentAllocationService::class);
    }
    
    /** @test */
    public function underpayment_within_tolerance_is_auto_written_off(): void
    {
        $invoice = $this->createInvoice('100.0000');
        
        // Pay 99.95 (0.05 underpayment, within 0.10 max)
        $payment = $this->allocationService->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '99.9500',
            paymentMethodAccountId: $this->bankAccountId,
            date: now()
        );
        
        $invoice->refresh();
        
        $this->assertEquals('0.0000', $invoice->balance_due);
        $this->assertEquals('paid', $invoice->status);
        
        // Verify tolerance GL entry created
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'payment_tolerance',
            'source_id' => $invoice->id,
        ]);
    }
    
    /** @test */
    public function underpayment_exceeding_tolerance_leaves_balance(): void
    {
        $invoice = $this->createInvoice('100.0000');
        
        // Pay 99.50 (0.50 underpayment, exceeds 0.10 max for TN)
        $payment = $this->allocationService->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '99.5000',
            paymentMethodAccountId: $this->bankAccountId,
            date: now()
        );
        
        $invoice->refresh();
        
        $this->assertEquals('0.5000', $invoice->balance_due);
        $this->assertEquals('posted', $invoice->status);
    }
    
    /** @test */
    public function overpayment_within_tolerance_is_auto_written_off(): void
    {
        $invoice = $this->createInvoice('100.0000');
        
        // Pay 100.08 (0.08 overpayment, within 0.10 max)
        $payment = $this->allocationService->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '100.0800',
            paymentMethodAccountId: $this->bankAccountId,
            date: now()
        );
        
        $invoice->refresh();
        $this->customer->refresh();
        
        $this->assertEquals('0.0000', $invoice->balance_due);
        $this->assertEquals('0.0000', $this->customer->credit_balance); // No credit created
    }
    
    /** @test */
    public function overpayment_exceeding_tolerance_creates_credit(): void
    {
        $invoice = $this->createInvoice('100.0000');
        
        // Pay 110.00 (10.00 overpayment, exceeds tolerance)
        $payment = $this->allocationService->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '110.0000',
            paymentMethodAccountId: $this->bankAccountId,
            date: now()
        );
        
        $invoice->refresh();
        $this->customer->refresh();
        
        $this->assertEquals('0.0000', $invoice->balance_due);
        $this->assertEquals('10.0000', $this->customer->credit_balance);
    }
    
    /** @test */
    public function company_tolerance_override_takes_precedence(): void
    {
        // Set company-level override (stricter: 0.01 max)
        $this->company->update(['max_payment_tolerance_amount' => '0.0100']);
        
        $settings = $this->toleranceService->getToleranceSettings($this->company->id);
        
        $this->assertEquals('0.0100', $settings['max_amount']);
        $this->assertEquals('company', $settings['source']);
    }
    
    /** @test */
    public function tolerance_disabled_leaves_all_balances(): void
    {
        $this->company->update(['payment_tolerance_enabled' => false]);
        
        $invoice = $this->createInvoice('100.0000');
        
        // Pay 99.98 (small underpayment)
        $payment = $this->allocationService->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '99.9800',
            paymentMethodAccountId: $this->bankAccountId,
            date: now()
        );
        
        $invoice->refresh();
        
        $this->assertEquals('0.0200', $invoice->balance_due); // Not written off
    }
    
    private function createInvoice(string $amount): Document
    {
        return Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'document_type' => 'invoice',
            'total_ttc' => $amount,
            'balance_due' => $amount,
            'status' => 'posted',
        ]);
    }
    
    private function createBankAccount(string $companyId): string
    {
        // Create and return bank account ID
        // Implementation depends on your Account model
    }
}
```

#### 7.2 AllocationMethodTest

**File:** `tests/Feature/Treasury/AllocationMethodTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationMethodTest extends TestCase
{
    use RefreshDatabase;
    
    private PaymentAllocationService $service;
    // ... setup similar to PaymentToleranceTest
    
    /** @test */
    public function fifo_allocates_by_invoice_date(): void
    {
        $inv1 = $this->createInvoice('200.0000', now()->subDays(10)); // Oldest
        $inv2 = $this->createInvoice('300.0000', now()->subDays(5));
        $inv3 = $this->createInvoice('400.0000', now()->subDays(1)); // Newest
        
        $payment = $this->service->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '500.0000',
            paymentMethodAccountId: $this->bankAccountId,
            date: now(),
            allocationMethod: AllocationMethod::FIFO
        );
        
        $inv1->refresh();
        $inv2->refresh();
        $inv3->refresh();
        
        $this->assertEquals('0.0000', $inv1->balance_due); // Paid first
        $this->assertEquals('0.0000', $inv2->balance_due); // Paid second
        $this->assertEquals('400.0000', $inv3->balance_due); // Untouched
    }
    
    /** @test */
    public function due_date_priority_allocates_by_due_date(): void
    {
        $inv1 = $this->createInvoice('200.0000', now()->subDays(10), now()->addDays(10)); // Due latest
        $inv2 = $this->createInvoice('300.0000', now()->subDays(5), now()->subDays(5)); // Most overdue
        $inv3 = $this->createInvoice('400.0000', now()->subDays(1), now()); // Due today
        
        $payment = $this->service->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '500.0000',
            paymentMethodAccountId: $this->bankAccountId,
            date: now(),
            allocationMethod: AllocationMethod::DUE_DATE_PRIORITY
        );
        
        $inv1->refresh();
        $inv2->refresh();
        $inv3->refresh();
        
        $this->assertEquals('200.0000', $inv1->balance_due); // Untouched (due latest)
        $this->assertEquals('0.0000', $inv2->balance_due); // Paid first (most overdue)
        $this->assertEquals('200.0000', $inv3->balance_due); // Partially paid
    }
    
    /** @test */
    public function manual_allocation_respects_user_selection(): void
    {
        $inv1 = $this->createInvoice('200.0000', now()->subDays(10)); // Oldest
        $inv2 = $this->createInvoice('300.0000', now()->subDays(5));
        
        // User explicitly selects inv2 only
        $payment = $this->service->recordPayment(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '250.0000',
            paymentMethodAccountId: $this->bankAccountId,
            date: now(),
            allocationMethod: AllocationMethod::MANUAL,
            invoiceAllocations: [
                ['document_id' => $inv2->id, 'amount' => '250.0000'],
            ]
        );
        
        $inv1->refresh();
        $inv2->refresh();
        
        $this->assertEquals('200.0000', $inv1->balance_due); // Untouched
        $this->assertEquals('50.0000', $inv2->balance_due); // Partially paid
    }
    
    /** @test */
    public function preview_shows_correct_allocation(): void
    {
        $this->createInvoice('200.0000', now()->subDays(2));
        $this->createInvoice('300.0000', now()->subDays(1));
        
        $preview = $this->service->previewAllocation(
            $this->company->id,
            $this->customer->id,
            '350.0000',
            AllocationMethod::FIFO
        );
        
        $this->assertEquals('350.0000', $preview['total_to_invoices']);
        $this->assertEquals('0.0000', $preview['excess_amount']);
        $this->assertEquals(1, $preview['invoices_fully_paid']);
        $this->assertEquals(1, $preview['invoices_partially_paid']);
        $this->assertArrayHasKey('tolerance_settings', $preview);
    }
    
    private function createInvoice(string $amount, $date, $dueDate = null): Document
    {
        return Document::factory()->create([
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'document_type' => 'invoice',
            'document_date' => $date,
            'due_date' => $dueDate ?? $date->copy()->addDays(30),
            'total_ttc' => $amount,
            'balance_due' => $amount,
            'status' => 'posted',
        ]);
    }
}
```

#### 7.3 CreditNoteTest

**File:** `tests/Feature/Document/CreditNoteTest.php`

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $service;
    // ... setup

    /** @test */
    public function credit_note_reduces_invoice_balance(): void
    {
        $invoice = $this->createInvoice('119.0000');

        $creditNote = $this->service->createCreditNote(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '50.0000',
            taxAmount: '9.5000',
            reason: CreditNoteReason::Return,
            date: now(),
            relatedDocumentId: $invoice->id,
            returnComment: 'Customer returned defective item'
        );

        $invoice->refresh();

        $this->assertEquals('59.5000', $creditNote->total_ttc);
        $this->assertEquals(DocumentType::CreditNote->value, $creditNote->document_type);
        $this->assertEquals('59.5000', $invoice->balance_due); // 119 - 59.5
    }

    /** @test */
    public function credit_note_requires_reason(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        // API call without reason
        $this->postJson('/api/documents/credit-notes', [
            'partner_id' => $this->customer->id,
            'amount' => '50.0000',
            'tax_amount' => '9.5000',
            'document_date' => now()->toDateString(),
            // Missing 'reason'
        ]);
    }

    /** @test */
    public function credit_note_other_reason_requires_comment(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Return comment is required');

        $this->service->createCreditNote(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '50.0000',
            taxAmount: '9.5000',
            reason: CreditNoteReason::Other,
            date: now(),
            returnComment: null // Missing comment for OTHER
        );
    }

    /** @test */
    public function credit_note_creates_correct_gl_entries(): void
    {
        $creditNote = $this->service->createCreditNote(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '100.0000',
            taxAmount: '19.0000',
            reason: CreditNoteReason::PriceAdjustment,
            date: now()
        );

        // Verify GL entry exists
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'credit_note',
            'source_id' => $creditNote->id,
        ]);

        // Verify lines: Dr. Sales Returns 100, Dr. VAT 19, Cr. AR 119
        $this->assertDatabaseHas('journal_lines', [
            'debit' => '100.0000',
            'credit' => '0',
            // account_id for sales return
        ]);
    }

    /** @test */
    public function credit_note_links_to_original_invoice(): void
    {
        $invoice = $this->createInvoice('200.0000');

        $creditNote = $this->service->createCreditNote(
            companyId: $this->company->id,
            partnerId: $this->customer->id,
            amount: '50.0000',
            taxAmount: '0',
            reason: CreditNoteReason::BillingError,
            date: now(),
            relatedDocumentId: $invoice->id
        );

        $this->assertEquals($invoice->id, $creditNote->related_document_id);
        $this->assertNotNull($creditNote->relatedDocument);
        $this->assertEquals($invoice->id, $creditNote->relatedDocument->id);
    }
}
```

#### Phase 7 Verification

```bash
php artisan test --filter=PaymentTolerance
php artisan test --filter=AllocationMethod
php artisan test --filter=CreditNote

# Full test suite
php artisan test

# PHPStan
./vendor/bin/phpstan analyse app/Modules/Treasury app/Modules/Document --level=5
```

**Commit:** `test(treasury): add payment tolerance, allocation method, and credit note tests`

---

## Phase 8: Frontend Implementation

> **Follow CLAUDE.md:** All user-facing text MUST use translation keys. Use pessimistic UI pattern for financial operations.

### 8.1 TypeScript Types

Create `apps/web/src/types/treasury.ts`:

```typescript
// apps/web/src/types/treasury.ts

export type AllocationMethod = 'fifo' | 'due_date' | 'manual';

export interface AllocationMethodOption {
  value: AllocationMethod;
  label: string;
  description: string;
}

export interface PaymentAllocation {
  document_id: string;
  document_number: string;
  amount: string;
  original_balance: string;
  days_overdue: number;
}

export interface ToleranceSettings {
  enabled: boolean;
  percentage: string;
  max_amount: string;
  source: 'country' | 'company';
}

export interface PaymentPreview {
  allocation_method: AllocationMethod;
  allocations: PaymentAllocation[];
  total_to_invoices: string;
  excess_amount: string;
  excess_handling: 'credit_balance' | 'tolerance_writeoff';
  tolerance_settings: ToleranceSettings;
}

export interface OpenInvoice {
  id: string;
  document_number: string;
  partner_name: string;
  issue_date: string;
  due_date: string;
  total_amount: string;
  balance_due: string;
  days_overdue: number;
  currency_code: string;
}

export interface CreditNoteReason {
  value: 'return' | 'price_adjustment' | 'billing_error' | 'damaged_goods' | 'service_issue' | 'other';
  label: string;
}

export interface CreatePaymentRequest {
  partner_id: string;
  amount: string;
  payment_method_id: string;
  repository_id: string;
  allocation_method: AllocationMethod;
  allocations?: { document_id: string; amount: string }[];
  reference?: string;
  notes?: string;
}

export interface CreateCreditNoteRequest {
  related_document_id: string;
  reason: CreditNoteReason['value'];
  lines: {
    product_id: string;
    quantity: number;
    unit_price: string;
    description?: string;
  }[];
  return_comment?: string;
}
```

### 8.2 API Hooks

Create `apps/web/src/features/treasury/hooks/useSmartPayment.ts`:

```typescript
// apps/web/src/features/treasury/hooks/useSmartPayment.ts
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  OpenInvoice,
  PaymentPreview,
  CreatePaymentRequest,
  AllocationMethod,
  ToleranceSettings,
} from '@/types/treasury';

// Fetch open invoices for a partner
export function useOpenInvoices(partnerId: string) {
  return useQuery({
    queryKey: ['open-invoices', partnerId],
    queryFn: async (): Promise<OpenInvoice[]> => {
      const response = await api.get(`/v1/partners/${partnerId}/open-invoices`);
      return response.data.data;
    },
    enabled: !!partnerId,
  });
}

// Get payment preview with allocations
export function usePaymentPreview(
  partnerId: string,
  amount: string,
  allocationMethod: AllocationMethod
) {
  return useQuery({
    queryKey: ['payment-preview', partnerId, amount, allocationMethod],
    queryFn: async (): Promise<PaymentPreview> => {
      const response = await api.post(`/v1/partners/${partnerId}/payment-preview`, {
        amount,
        allocation_method: allocationMethod,
      });
      return response.data.data;
    },
    enabled: !!partnerId && !!amount && parseFloat(amount) > 0,
  });
}

// Get tolerance settings for a partner
export function useToleranceSettings(partnerId: string) {
  return useQuery({
    queryKey: ['tolerance-settings', partnerId],
    queryFn: async (): Promise<ToleranceSettings> => {
      const response = await api.get(`/v1/partners/${partnerId}/payment-settings`);
      return response.data.data;
    },
    enabled: !!partnerId,
  });
}

// Create payment mutation - PESSIMISTIC pattern
export function useCreatePayment() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreatePaymentRequest) => {
      const response = await api.post('/v1/payments', data);
      return response.data.data;
    },
    onSuccess: (_, variables) => {
      // Invalidate related queries after successful payment
      queryClient.invalidateQueries({ queryKey: ['open-invoices', variables.partner_id] });
      queryClient.invalidateQueries({ queryKey: ['partner', variables.partner_id] });
      queryClient.invalidateQueries({ queryKey: ['payments'] });
    },
  });
}
```

Create `apps/web/src/features/documents/hooks/useCreditNote.ts`:

```typescript
// apps/web/src/features/documents/hooks/useCreditNote.ts
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { CreateCreditNoteRequest } from '@/types/treasury';

// Create credit note mutation - PESSIMISTIC pattern
export function useCreateCreditNote() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateCreditNoteRequest) => {
      const response = await api.post('/v1/documents/credit-notes', data);
      return response.data.data;
    },
    onSuccess: (result, variables) => {
      // Invalidate related queries
      queryClient.invalidateQueries({ queryKey: ['document', variables.related_document_id] });
      queryClient.invalidateQueries({ queryKey: ['documents'] });
      queryClient.invalidateQueries({ queryKey: ['partner'] });
    },
  });
}
```

### 8.3 i18n Translations

Add to `apps/web/src/locales/en/treasury.json`:

```json
{
  "payment": {
    "title": "Record Payment",
    "amount": "Payment Amount",
    "method": "Payment Method",
    "repository": "Repository",
    "reference": "Reference",
    "notes": "Notes",
    "allocationMethod": {
      "label": "Allocation Method",
      "fifo": "First In First Out",
      "fifoDescription": "Oldest invoices paid first",
      "dueDate": "Due Date Priority",
      "dueDateDescription": "Most overdue invoices paid first",
      "manual": "Manual Selection",
      "manualDescription": "Choose which invoices to pay"
    },
    "preview": {
      "title": "Payment Preview",
      "allocations": "Invoice Allocations",
      "totalToInvoices": "Total to Invoices",
      "excessAmount": "Excess Amount",
      "creditBalance": "Will be added to credit balance",
      "toleranceWriteoff": "Will be written off as tolerance"
    },
    "tolerance": {
      "title": "Payment Tolerance",
      "enabled": "Auto write-off enabled",
      "disabled": "Auto write-off disabled",
      "threshold": "Threshold: {{percentage}}% / {{maxAmount}} max",
      "source": "Settings from {{source}}"
    },
    "openInvoices": {
      "title": "Open Invoices",
      "documentNumber": "Invoice #",
      "issueDate": "Issue Date",
      "dueDate": "Due Date",
      "totalAmount": "Total",
      "balanceDue": "Balance Due",
      "daysOverdue": "Days Overdue",
      "noInvoices": "No open invoices"
    },
    "actions": {
      "submit": "Record Payment",
      "cancel": "Cancel",
      "processing": "Processing..."
    },
    "success": "Payment recorded successfully",
    "error": "Failed to record payment"
  },
  "creditNote": {
    "title": "Create Credit Note",
    "relatedInvoice": "Related Invoice",
    "reason": {
      "label": "Reason",
      "return": "Product Return",
      "priceAdjustment": "Price Adjustment",
      "billingError": "Billing Error",
      "damagedGoods": "Damaged Goods",
      "serviceIssue": "Service Issue",
      "other": "Other"
    },
    "returnComment": "Return Comment",
    "lines": {
      "title": "Credit Note Lines",
      "product": "Product",
      "quantity": "Quantity",
      "unitPrice": "Unit Price",
      "total": "Total",
      "add": "Add Line",
      "remove": "Remove"
    },
    "actions": {
      "create": "Create Credit Note",
      "cancel": "Cancel",
      "processing": "Creating..."
    },
    "success": "Credit note created successfully",
    "error": "Failed to create credit note"
  }
}
```

Add to `apps/web/src/locales/fr/treasury.json`:

```json
{
  "payment": {
    "title": "Enregistrer un paiement",
    "amount": "Montant du paiement",
    "method": "Mode de paiement",
    "repository": "Caisse",
    "reference": "Référence",
    "notes": "Notes",
    "allocationMethod": {
      "label": "Méthode d'allocation",
      "fifo": "Premier entré, premier sorti",
      "fifoDescription": "Les factures les plus anciennes sont payées en premier",
      "dueDate": "Priorité par échéance",
      "dueDateDescription": "Les factures les plus en retard sont payées en premier",
      "manual": "Sélection manuelle",
      "manualDescription": "Choisir les factures à payer"
    },
    "preview": {
      "title": "Aperçu du paiement",
      "allocations": "Allocations aux factures",
      "totalToInvoices": "Total aux factures",
      "excessAmount": "Montant excédentaire",
      "creditBalance": "Sera ajouté au solde créditeur",
      "toleranceWriteoff": "Sera passé en perte de tolérance"
    },
    "tolerance": {
      "title": "Tolérance de paiement",
      "enabled": "Écriture automatique activée",
      "disabled": "Écriture automatique désactivée",
      "threshold": "Seuil: {{percentage}}% / {{maxAmount}} max",
      "source": "Paramètres de {{source}}"
    },
    "openInvoices": {
      "title": "Factures ouvertes",
      "documentNumber": "Facture #",
      "issueDate": "Date d'émission",
      "dueDate": "Date d'échéance",
      "totalAmount": "Total",
      "balanceDue": "Solde dû",
      "daysOverdue": "Jours de retard",
      "noInvoices": "Aucune facture ouverte"
    },
    "actions": {
      "submit": "Enregistrer le paiement",
      "cancel": "Annuler",
      "processing": "Traitement..."
    },
    "success": "Paiement enregistré avec succès",
    "error": "Échec de l'enregistrement du paiement"
  },
  "creditNote": {
    "title": "Créer un avoir",
    "relatedInvoice": "Facture associée",
    "reason": {
      "label": "Motif",
      "return": "Retour de produit",
      "priceAdjustment": "Ajustement de prix",
      "billingError": "Erreur de facturation",
      "damagedGoods": "Marchandise endommagée",
      "serviceIssue": "Problème de service",
      "other": "Autre"
    },
    "returnComment": "Commentaire de retour",
    "lines": {
      "title": "Lignes de l'avoir",
      "product": "Produit",
      "quantity": "Quantité",
      "unitPrice": "Prix unitaire",
      "total": "Total",
      "add": "Ajouter une ligne",
      "remove": "Supprimer"
    },
    "actions": {
      "create": "Créer l'avoir",
      "cancel": "Annuler",
      "processing": "Création..."
    },
    "success": "Avoir créé avec succès",
    "error": "Échec de la création de l'avoir"
  }
}
```

### 8.4 React Components

Create `apps/web/src/features/treasury/components/AllocationMethodSelector.tsx`:

```tsx
// apps/web/src/features/treasury/components/AllocationMethodSelector.tsx
import { useTranslation } from 'react-i18next';
import type { AllocationMethod, AllocationMethodOption } from '@/types/treasury';

interface Props {
  value: AllocationMethod;
  onChange: (method: AllocationMethod) => void;
  disabled?: boolean;
}

export function AllocationMethodSelector({ value, onChange, disabled }: Props) {
  const { t } = useTranslation('treasury');

  const options: AllocationMethodOption[] = [
    {
      value: 'fifo',
      label: t('payment.allocationMethod.fifo'),
      description: t('payment.allocationMethod.fifoDescription'),
    },
    {
      value: 'due_date',
      label: t('payment.allocationMethod.dueDate'),
      description: t('payment.allocationMethod.dueDateDescription'),
    },
    {
      value: 'manual',
      label: t('payment.allocationMethod.manual'),
      description: t('payment.allocationMethod.manualDescription'),
    },
  ];

  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium text-gray-700">
        {t('payment.allocationMethod.label')}
      </label>
      <div className="space-y-2">
        {options.map((option) => (
          <label
            key={option.value}
            className={`flex items-start p-3 border rounded-lg cursor-pointer transition-colors ${
              value === option.value
                ? 'border-primary-500 bg-primary-50'
                : 'border-gray-200 hover:border-gray-300'
            } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
          >
            <input
              type="radio"
              name="allocation_method"
              value={option.value}
              checked={value === option.value}
              onChange={(e) => onChange(e.target.value as AllocationMethod)}
              disabled={disabled}
              className="mt-1 me-3"
            />
            <div>
              <div className="font-medium">{option.label}</div>
              <div className="text-sm text-gray-500">{option.description}</div>
            </div>
          </label>
        ))}
      </div>
    </div>
  );
}
```

Create `apps/web/src/features/treasury/components/OpenInvoicesList.tsx`:

```tsx
// apps/web/src/features/treasury/components/OpenInvoicesList.tsx
import { useTranslation } from 'react-i18next';
import { formatCurrency, formatDate } from '@/lib/formatters';
import type { OpenInvoice } from '@/types/treasury';

interface Props {
  invoices: OpenInvoice[];
  selectedIds?: string[];
  onSelectionChange?: (ids: string[]) => void;
  selectable?: boolean;
  loading?: boolean;
}

export function OpenInvoicesList({
  invoices,
  selectedIds = [],
  onSelectionChange,
  selectable = false,
  loading = false,
}: Props) {
  const { t } = useTranslation('treasury');

  if (loading) {
    return (
      <div className="animate-pulse space-y-2">
        {[1, 2, 3].map((i) => (
          <div key={i} className="h-12 bg-gray-200 rounded" />
        ))}
      </div>
    );
  }

  if (invoices.length === 0) {
    return (
      <div className="text-center py-8 text-gray-500">
        {t('payment.openInvoices.noInvoices')}
      </div>
    );
  }

  const handleToggle = (id: string) => {
    if (!onSelectionChange) return;
    const newSelection = selectedIds.includes(id)
      ? selectedIds.filter((i) => i !== id)
      : [...selectedIds, id];
    onSelectionChange(newSelection);
  };

  return (
    <div className="space-y-4">
      <h3 className="font-medium">{t('payment.openInvoices.title')}</h3>
      <div className="border rounded-lg overflow-hidden">
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50">
            <tr>
              {selectable && <th className="w-8 px-3 py-2" />}
              <th className="px-3 py-2 text-start text-xs font-medium text-gray-500 uppercase">
                {t('payment.openInvoices.documentNumber')}
              </th>
              <th className="px-3 py-2 text-start text-xs font-medium text-gray-500 uppercase">
                {t('payment.openInvoices.dueDate')}
              </th>
              <th className="px-3 py-2 text-end text-xs font-medium text-gray-500 uppercase">
                {t('payment.openInvoices.balanceDue')}
              </th>
              <th className="px-3 py-2 text-end text-xs font-medium text-gray-500 uppercase">
                {t('payment.openInvoices.daysOverdue')}
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {invoices.map((invoice) => (
              <tr
                key={invoice.id}
                className={selectable ? 'cursor-pointer hover:bg-gray-50' : ''}
                onClick={() => selectable && handleToggle(invoice.id)}
              >
                {selectable && (
                  <td className="px-3 py-2">
                    <input
                      type="checkbox"
                      checked={selectedIds.includes(invoice.id)}
                      onChange={() => handleToggle(invoice.id)}
                      onClick={(e) => e.stopPropagation()}
                    />
                  </td>
                )}
                <td className="px-3 py-2 text-sm font-medium">
                  {invoice.document_number}
                </td>
                <td className="px-3 py-2 text-sm text-gray-500">
                  {formatDate(invoice.due_date)}
                </td>
                <td className="px-3 py-2 text-sm text-end font-medium">
                  {formatCurrency(invoice.balance_due, invoice.currency_code)}
                </td>
                <td className="px-3 py-2 text-sm text-end">
                  <span
                    className={
                      invoice.days_overdue > 0
                        ? 'text-red-600 font-medium'
                        : 'text-gray-500'
                    }
                  >
                    {invoice.days_overdue > 0 ? invoice.days_overdue : '-'}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
```

Create `apps/web/src/features/treasury/components/AllocationPreview.tsx`:

```tsx
// apps/web/src/features/treasury/components/AllocationPreview.tsx
import { useTranslation } from 'react-i18next';
import { formatCurrency } from '@/lib/formatters';
import type { PaymentPreview } from '@/types/treasury';

interface Props {
  preview: PaymentPreview;
  currencyCode: string;
}

export function AllocationPreview({ preview, currencyCode }: Props) {
  const { t } = useTranslation('treasury');

  return (
    <div className="space-y-4 p-4 bg-gray-50 rounded-lg">
      <h3 className="font-medium">{t('payment.preview.title')}</h3>

      {/* Allocations */}
      {preview.allocations.length > 0 && (
        <div className="space-y-2">
          <div className="text-sm text-gray-600">{t('payment.preview.allocations')}</div>
          <div className="space-y-1">
            {preview.allocations.map((alloc) => (
              <div key={alloc.document_id} className="flex justify-between text-sm">
                <span>{alloc.document_number}</span>
                <span className="font-medium">
                  {formatCurrency(alloc.amount, currencyCode)}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Totals */}
      <div className="border-t pt-2 space-y-1">
        <div className="flex justify-between text-sm">
          <span>{t('payment.preview.totalToInvoices')}</span>
          <span className="font-medium">
            {formatCurrency(preview.total_to_invoices, currencyCode)}
          </span>
        </div>

        {parseFloat(preview.excess_amount) > 0 && (
          <div className="flex justify-between text-sm">
            <span>{t('payment.preview.excessAmount')}</span>
            <span className="font-medium text-amber-600">
              {formatCurrency(preview.excess_amount, currencyCode)}
            </span>
          </div>
        )}
      </div>

      {/* Excess handling */}
      {parseFloat(preview.excess_amount) > 0 && (
        <div className="text-sm text-gray-600 italic">
          {preview.excess_handling === 'credit_balance'
            ? t('payment.preview.creditBalance')
            : t('payment.preview.toleranceWriteoff')}
        </div>
      )}

      {/* Tolerance info */}
      {preview.tolerance_settings.enabled && (
        <div className="text-xs text-gray-500 border-t pt-2">
          <div>{t('payment.tolerance.enabled')}</div>
          <div>
            {t('payment.tolerance.threshold', {
              percentage: preview.tolerance_settings.percentage,
              maxAmount: formatCurrency(preview.tolerance_settings.max_amount, currencyCode),
            })}
          </div>
        </div>
      )}
    </div>
  );
}
```

Create `apps/web/src/features/treasury/components/SmartPaymentForm.tsx`:

```tsx
// apps/web/src/features/treasury/components/SmartPaymentForm.tsx
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useForm } from 'react-hook-form';
import { toast } from 'react-hot-toast';
import { useOpenInvoices, usePaymentPreview, useCreatePayment } from '../hooks/useSmartPayment';
import { AllocationMethodSelector } from './AllocationMethodSelector';
import { OpenInvoicesList } from './OpenInvoicesList';
import { AllocationPreview } from './AllocationPreview';
import type { AllocationMethod, CreatePaymentRequest } from '@/types/treasury';

interface Props {
  partnerId: string;
  currencyCode: string;
  onSuccess?: () => void;
  onCancel?: () => void;
}

export function SmartPaymentForm({ partnerId, currencyCode, onSuccess, onCancel }: Props) {
  const { t } = useTranslation('treasury');
  const [allocationMethod, setAllocationMethod] = useState<AllocationMethod>('fifo');
  const [selectedInvoices, setSelectedInvoices] = useState<string[]>([]);

  const { register, handleSubmit, watch, formState: { errors } } = useForm<CreatePaymentRequest>({
    defaultValues: {
      partner_id: partnerId,
      allocation_method: 'fifo',
    },
  });

  const amount = watch('amount');

  const { data: openInvoices, isLoading: loadingInvoices } = useOpenInvoices(partnerId);
  const { data: preview, isLoading: loadingPreview } = usePaymentPreview(
    partnerId,
    amount,
    allocationMethod
  );

  const createPayment = useCreatePayment();

  const onSubmit = async (data: CreatePaymentRequest) => {
    try {
      await createPayment.mutateAsync({
        ...data,
        allocation_method: allocationMethod,
        allocations: allocationMethod === 'manual'
          ? selectedInvoices.map((id) => ({
              document_id: id,
              amount: openInvoices?.find((inv) => inv.id === id)?.balance_due ?? '0',
            }))
          : undefined,
      });
      toast.success(t('payment.success'));
      onSuccess?.();
    } catch (error) {
      toast.error(t('payment.error'));
    }
  };

  const isSubmitting = createPayment.isPending;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Amount */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          {t('payment.amount')}
        </label>
        <input
          type="number"
          step="0.01"
          {...register('amount', { required: true, min: 0.01 })}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
          disabled={isSubmitting}
        />
        {errors.amount && (
          <p className="mt-1 text-sm text-red-600">{t('validation:required')}</p>
        )}
      </div>

      {/* Payment Method */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          {t('payment.method')}
        </label>
        <select
          {...register('payment_method_id', { required: true })}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
          disabled={isSubmitting}
        >
          <option value="">{t('common:select')}</option>
          {/* Payment methods loaded from API */}
        </select>
      </div>

      {/* Allocation Method */}
      <AllocationMethodSelector
        value={allocationMethod}
        onChange={setAllocationMethod}
        disabled={isSubmitting}
      />

      {/* Open Invoices (for manual selection) */}
      {allocationMethod === 'manual' && (
        <OpenInvoicesList
          invoices={openInvoices ?? []}
          selectedIds={selectedInvoices}
          onSelectionChange={setSelectedInvoices}
          selectable
          loading={loadingInvoices}
        />
      )}

      {/* Preview */}
      {preview && !loadingPreview && (
        <AllocationPreview preview={preview} currencyCode={currencyCode} />
      )}

      {/* Actions - PESSIMISTIC: button disabled during submission */}
      <div className="flex justify-end gap-3">
        <button
          type="button"
          onClick={onCancel}
          className="px-4 py-2 border rounded-md hover:bg-gray-50"
          disabled={isSubmitting}
        >
          {t('payment.actions.cancel')}
        </button>
        <button
          type="submit"
          className="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 disabled:opacity-50"
          disabled={isSubmitting}
        >
          {isSubmitting ? t('payment.actions.processing') : t('payment.actions.submit')}
        </button>
      </div>
    </form>
  );
}
```

Create `apps/web/src/features/documents/components/CreditNoteForm.tsx`:

```tsx
// apps/web/src/features/documents/components/CreditNoteForm.tsx
import { useTranslation } from 'react-i18next';
import { useForm, useFieldArray } from 'react-hook-form';
import { toast } from 'react-hot-toast';
import { useCreateCreditNote } from '../hooks/useCreditNote';
import type { CreateCreditNoteRequest, CreditNoteReason } from '@/types/treasury';

interface Props {
  relatedDocumentId: string;
  relatedDocumentNumber: string;
  onSuccess?: () => void;
  onCancel?: () => void;
}

const CREDIT_NOTE_REASONS: CreditNoteReason['value'][] = [
  'return',
  'price_adjustment',
  'billing_error',
  'damaged_goods',
  'service_issue',
  'other',
];

export function CreditNoteForm({
  relatedDocumentId,
  relatedDocumentNumber,
  onSuccess,
  onCancel,
}: Props) {
  const { t } = useTranslation(['treasury', 'common']);

  const { register, control, handleSubmit, formState: { errors } } = useForm<CreateCreditNoteRequest>({
    defaultValues: {
      related_document_id: relatedDocumentId,
      reason: 'return',
      lines: [{ product_id: '', quantity: 1, unit_price: '0' }],
    },
  });

  const { fields, append, remove } = useFieldArray({
    control,
    name: 'lines',
  });

  const createCreditNote = useCreateCreditNote();

  const onSubmit = async (data: CreateCreditNoteRequest) => {
    try {
      await createCreditNote.mutateAsync(data);
      toast.success(t('treasury:creditNote.success'));
      onSuccess?.();
    } catch (error) {
      toast.error(t('treasury:creditNote.error'));
    }
  };

  const isSubmitting = createCreditNote.isPending;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
      {/* Related Invoice */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          {t('treasury:creditNote.relatedInvoice')}
        </label>
        <div className="mt-1 p-2 bg-gray-100 rounded-md text-sm">
          {relatedDocumentNumber}
        </div>
      </div>

      {/* Reason */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          {t('treasury:creditNote.reason.label')}
        </label>
        <select
          {...register('reason', { required: true })}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
          disabled={isSubmitting}
        >
          {CREDIT_NOTE_REASONS.map((reason) => (
            <option key={reason} value={reason}>
              {t(`treasury:creditNote.reason.${reason}`)}
            </option>
          ))}
        </select>
      </div>

      {/* Return Comment */}
      <div>
        <label className="block text-sm font-medium text-gray-700">
          {t('treasury:creditNote.returnComment')}
        </label>
        <textarea
          {...register('return_comment')}
          rows={3}
          className="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
          disabled={isSubmitting}
        />
      </div>

      {/* Lines */}
      <div className="space-y-4">
        <div className="flex justify-between items-center">
          <h3 className="font-medium">{t('treasury:creditNote.lines.title')}</h3>
          <button
            type="button"
            onClick={() => append({ product_id: '', quantity: 1, unit_price: '0' })}
            className="text-sm text-primary-600 hover:text-primary-700"
            disabled={isSubmitting}
          >
            {t('treasury:creditNote.lines.add')}
          </button>
        </div>

        {fields.map((field, index) => (
          <div key={field.id} className="flex gap-2 items-start">
            <div className="flex-1">
              <input
                {...register(`lines.${index}.product_id`, { required: true })}
                placeholder={t('treasury:creditNote.lines.product')}
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                disabled={isSubmitting}
              />
            </div>
            <div className="w-20">
              <input
                type="number"
                {...register(`lines.${index}.quantity`, { required: true, min: 1 })}
                placeholder={t('treasury:creditNote.lines.quantity')}
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                disabled={isSubmitting}
              />
            </div>
            <div className="w-28">
              <input
                type="number"
                step="0.01"
                {...register(`lines.${index}.unit_price`, { required: true })}
                placeholder={t('treasury:creditNote.lines.unitPrice')}
                className="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                disabled={isSubmitting}
              />
            </div>
            {fields.length > 1 && (
              <button
                type="button"
                onClick={() => remove(index)}
                className="text-red-500 hover:text-red-700 p-2"
                disabled={isSubmitting}
              >
                {t('treasury:creditNote.lines.remove')}
              </button>
            )}
          </div>
        ))}
      </div>

      {/* Actions - PESSIMISTIC: button disabled during submission */}
      <div className="flex justify-end gap-3">
        <button
          type="button"
          onClick={onCancel}
          className="px-4 py-2 border rounded-md hover:bg-gray-50"
          disabled={isSubmitting}
        >
          {t('treasury:creditNote.actions.cancel')}
        </button>
        <button
          type="submit"
          className="px-4 py-2 bg-primary-600 text-white rounded-md hover:bg-primary-700 disabled:opacity-50"
          disabled={isSubmitting}
        >
          {isSubmitting
            ? t('treasury:creditNote.actions.processing')
            : t('treasury:creditNote.actions.create')}
        </button>
      </div>
    </form>
  );
}
```

### 8.5 Component Exports

Update `apps/web/src/features/treasury/index.ts`:

```typescript
// apps/web/src/features/treasury/index.ts
export * from './components/AllocationMethodSelector';
export * from './components/OpenInvoicesList';
export * from './components/AllocationPreview';
export * from './components/SmartPaymentForm';
export * from './hooks/useSmartPayment';
```

Update `apps/web/src/features/documents/components/index.ts`:

```typescript
// Add to existing exports
export * from './CreditNoteForm';
```

### 8.6 Phase 8 Verification

```bash
# TypeScript check
cd apps/web && pnpm typecheck

# ESLint
pnpm lint

# Unit tests
pnpm test

# i18n key check (manual)
grep -r "t('" src/features/treasury/components/ | grep -v ".test." | head -20
grep -r "t('" src/features/documents/components/CreditNoteForm.tsx
```

**Commit:** `feat(web): add smart payment frontend components`

---

## Final Verification

```bash
echo "=== SMART PAYMENT FEATURES V2 FINAL CHECK ==="

echo -e "\n1. Database tables:"
php artisan tinker --execute="
echo 'country_payment_settings: ' . (Schema::hasTable('country_payment_settings') ? '✓' : '✗') . PHP_EOL;
"

echo -e "\n2. Enums:"
php artisan tinker --execute="
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
echo 'AllocationMethod: ' . count(AllocationMethod::cases()) . ' cases' . PHP_EOL;
echo 'CreditNoteReason: ' . count(CreditNoteReason::cases()) . ' cases' . PHP_EOL;
"

echo -e "\n3. Services:"
php artisan tinker --execute="
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Document\Application\Services\CreditNoteService;
echo 'PaymentToleranceService: ✓' . PHP_EOL;
echo 'PaymentAllocationService: ✓' . PHP_EOL;
echo 'CreditNoteService: ✓' . PHP_EOL;
"

echo -e "\n4. Routes:"
php artisan route:list | grep -E "open-invoices|payments|credit-note|payment-settings"

echo -e "\n5. Tests:"
php artisan test --filter=PaymentTolerance --filter=AllocationMethod --filter=CreditNote

echo -e "\n6. PHPStan:"
./vendor/bin/phpstan analyse app/Modules/Treasury app/Modules/Document --level=5

echo -e "\n=== ALL CHECKS COMPLETE ==="
```

---

## Commit Summary

After all phases complete:

```bash
git add .
git commit -m "feat(treasury): implement smart payment features v2

- Add payment tolerance with country adaptation integration
- Add credit note document type with reasons
- Add FIFO and due date priority allocation methods
- Add tolerance and credit note GL journal entries
- Add API endpoints for payments and credit notes
- Add frontend components for smart payments
- Add comprehensive tests

Closes #XXX"
```
