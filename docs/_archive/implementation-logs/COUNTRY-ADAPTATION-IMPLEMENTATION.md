# Country Adaptation - Account Abstraction Layer Implementation

## Context

This task implements the account abstraction layer needed for multi-country support. Currently, the `accounts` table is scoped by `tenant_id` and the `GeneralLedgerService` uses hardcoded account codes that don't even match the Tunisian chart of accounts.

**Goal:** Enable the GL system to work with any country's chart of accounts by looking up accounts by `system_purpose` (e.g., `customer_receivable`) rather than by code (e.g., `411000`).

## Pre-Implementation Checklist

Before starting, verify:
```bash
php artisan test
# All tests should pass

php artisan migrate:status
# All migrations should be applied
```

---

## Phase 1: Database Schema Changes

### 1.1 Create SystemAccountPurpose Enum

**File:** `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

enum SystemAccountPurpose: string
{
    // Asset Accounts
    case BANK = 'bank';
    case CASH = 'cash';
    case CUSTOMER_RECEIVABLE = 'customer_receivable';
    case SUPPLIER_ADVANCE = 'supplier_advance';
    case INVENTORY = 'inventory';
    
    // Liability Accounts
    case SUPPLIER_PAYABLE = 'supplier_payable';
    case CUSTOMER_ADVANCE = 'customer_advance';
    case VAT_COLLECTED = 'vat_collected';
    case VAT_DEDUCTIBLE = 'vat_deductible';
    
    // Revenue Accounts
    case PRODUCT_REVENUE = 'product_revenue';
    case SERVICE_REVENUE = 'service_revenue';
    
    // Expense Accounts
    case COST_OF_GOODS_SOLD = 'cost_of_goods_sold';
    case PURCHASE_EXPENSES = 'purchase_expenses';
    
    // Equity Accounts
    case RETAINED_EARNINGS = 'retained_earnings';
    case OPENING_BALANCE_EQUITY = 'opening_balance_equity';
    
    /**
     * Get human-readable label for display
     */
    public function label(): string
    {
        return match($this) {
            self::BANK => 'Bank Account',
            self::CASH => 'Cash Account',
            self::CUSTOMER_RECEIVABLE => 'Customer Receivable (AR)',
            self::SUPPLIER_ADVANCE => 'Advance to Supplier',
            self::INVENTORY => 'Inventory',
            self::SUPPLIER_PAYABLE => 'Supplier Payable (AP)',
            self::CUSTOMER_ADVANCE => 'Customer Advance/Prepayment',
            self::VAT_COLLECTED => 'VAT Collected (Output)',
            self::VAT_DEDUCTIBLE => 'VAT Deductible (Input)',
            self::PRODUCT_REVENUE => 'Product Sales Revenue',
            self::SERVICE_REVENUE => 'Service Revenue',
            self::COST_OF_GOODS_SOLD => 'Cost of Goods Sold',
            self::PURCHASE_EXPENSES => 'Purchase Expenses',
            self::RETAINED_EARNINGS => 'Retained Earnings',
            self::OPENING_BALANCE_EQUITY => 'Opening Balance Equity',
        };
    }
    
    /**
     * Get all purposes that should be seeded for every company
     */
    public static function requiredPurposes(): array
    {
        return [
            self::CUSTOMER_RECEIVABLE,
            self::CUSTOMER_ADVANCE,
            self::SUPPLIER_PAYABLE,
            self::VAT_COLLECTED,
            self::VAT_DEDUCTIBLE,
            self::PRODUCT_REVENUE,
            self::SERVICE_REVENUE,
        ];
    }
}
```

### 1.2 Migration: Add company_id and system_purpose to accounts

**File:** `database/migrations/YYYY_MM_DD_HHMMSS_add_company_id_and_system_purpose_to_accounts.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            // Add company_id column
            $table->uuid('company_id')->nullable()->after('tenant_id');
            
            // Add system_purpose column
            $table->string('system_purpose', 50)->nullable()->after('type');
            
            // Add foreign key
            $table->foreign('company_id')
                ->references('id')
                ->on('companies')
                ->onDelete('cascade');
        });
        
        // Migrate existing data: tenant_id -> company_id
        // For each tenant, find their first/default company and assign accounts to it
        DB::statement("
            UPDATE accounts a
            SET company_id = (
                SELECT c.id 
                FROM companies c 
                WHERE c.tenant_id = a.tenant_id 
                ORDER BY c.created_at ASC 
                LIMIT 1
            )
            WHERE a.company_id IS NULL
        ");
        
        // Add unique constraint for system_purpose per company
        // (only one account can have a given purpose per company)
        Schema::table('accounts', function (Blueprint $table) {
            $table->unique(['company_id', 'system_purpose'], 'accounts_company_purpose_unique');
            
            // Add index for lookups
            $table->index(['company_id', 'system_purpose'], 'accounts_company_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique('accounts_company_purpose_unique');
            $table->dropIndex('accounts_company_purpose_idx');
            $table->dropColumn(['company_id', 'system_purpose']);
        });
    }
};
```

### 1.3 Update Account Model

**File:** `app/Modules/Accounting/Domain/Account.php` (or wherever the model is)

Add these changes:

```php
<?php

// Add to imports
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;

// Add to $fillable array
protected $fillable = [
    // ... existing fields
    'company_id',
    'system_purpose',
];

// Add to $casts array
protected $casts = [
    // ... existing casts
    'system_purpose' => SystemAccountPurpose::class,
];

// Add relationship
public function company(): BelongsTo
{
    return $this->belongsTo(Company::class);
}

// Add scope for company
public function scopeForCompany(Builder $query, string $companyId): Builder
{
    return $query->where('company_id', $companyId);
}

// Add scope for system purpose
public function scopeWithPurpose(Builder $query, SystemAccountPurpose $purpose): Builder
{
    return $query->where('system_purpose', $purpose->value);
}

// Add static helper method
public static function findByPurpose(string $companyId, SystemAccountPurpose $purpose): ?self
{
    return static::forCompany($companyId)
        ->withPurpose($purpose)
        ->first();
}

public static function findByPurposeOrFail(string $companyId, SystemAccountPurpose $purpose): self
{
    $account = static::findByPurpose($companyId, $purpose);
    
    if (!$account) {
        throw new \RuntimeException(
            "No account found with purpose '{$purpose->value}' for company {$companyId}. " .
            "Please ensure the chart of accounts has been properly seeded."
        );
    }
    
    return $account;
}
```

### Verification for Phase 1

```bash
php artisan migrate

# Verify columns exist
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'company_id exists: ' . (Schema::hasColumn('accounts', 'company_id') ? 'YES' : 'NO') . PHP_EOL;
echo 'system_purpose exists: ' . (Schema::hasColumn('accounts', 'system_purpose') ? 'YES' : 'NO') . PHP_EOL;
"

# Verify enum exists
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
echo 'SystemAccountPurpose cases: ' . count(SystemAccountPurpose::cases()) . PHP_EOL;
foreach (SystemAccountPurpose::requiredPurposes() as \$p) {
    echo '  - ' . \$p->value . PHP_EOL;
}
"

php artisan test --filter=Account
```

---

## Phase 2: Update Tunisia Chart of Accounts Seeder

### 2.1 Refactor TunisiaChartOfAccountsSeeder

The seeder should:
1. Accept `company_id` instead of `tenant_id`
2. Set `system_purpose` on key accounts
3. Be callable from company creation flow

**File:** `database/seeders/TunisiaChartOfAccountsSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Seeder;

class TunisiaChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed Tunisia PCN (Plan Comptable National) chart of accounts
     * 
     * @param string $companyId The company to seed accounts for
     * @param string|null $tenantId Legacy support - will be removed
     */
    public function run(string $companyId, ?string $tenantId = null): void
    {
        $accounts = $this->getAccountsDefinition();
        
        foreach ($accounts as $account) {
            Account::create([
                'company_id' => $companyId,
                'tenant_id' => $tenantId, // Keep for backward compatibility during transition
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'system_purpose' => $account['system_purpose'] ?? null,
                'is_active' => true,
                'is_system' => $account['is_system'] ?? false,
                'balance' => 0,
            ]);
        }
    }
    
    /**
     * Tunisia PCN account definitions with system purposes
     */
    private function getAccountsDefinition(): array
    {
        return [
            // Class 1 - Comptes de capitaux
            ['code' => '10', 'name' => 'Capital', 'type' => AccountType::EQUITY, 'is_system' => true],
            ['code' => '101', 'name' => 'Capital social', 'type' => AccountType::EQUITY],
            ['code' => '106', 'name' => 'Réserves', 'type' => AccountType::EQUITY],
            ['code' => '12', 'name' => 'Résultat de l\'exercice', 'type' => AccountType::EQUITY, 
                'system_purpose' => SystemAccountPurpose::RETAINED_EARNINGS],
            
            // Class 2 - Comptes d'immobilisations
            ['code' => '20', 'name' => 'Immobilisations incorporelles', 'type' => AccountType::ASSET],
            ['code' => '21', 'name' => 'Immobilisations corporelles', 'type' => AccountType::ASSET],
            ['code' => '22', 'name' => 'Immobilisations mises en concession', 'type' => AccountType::ASSET],
            ['code' => '23', 'name' => 'Immobilisations en cours', 'type' => AccountType::ASSET],
            ['code' => '28', 'name' => 'Amortissements des immobilisations', 'type' => AccountType::CONTRA_ASSET],
            
            // Class 3 - Comptes de stocks
            ['code' => '30', 'name' => 'Stocks de marchandises', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::INVENTORY],
            ['code' => '31', 'name' => 'Matières premières', 'type' => AccountType::ASSET],
            ['code' => '32', 'name' => 'Autres approvisionnements', 'type' => AccountType::ASSET],
            ['code' => '35', 'name' => 'Stocks de produits', 'type' => AccountType::ASSET],
            
            // Class 4 - Comptes de tiers
            ['code' => '40', 'name' => 'Fournisseurs et comptes rattachés', 'type' => AccountType::LIABILITY, 'is_system' => true],
            ['code' => '401', 'name' => 'Fournisseurs', 'type' => AccountType::LIABILITY,
                'system_purpose' => SystemAccountPurpose::SUPPLIER_PAYABLE, 'is_system' => true],
            ['code' => '4011', 'name' => 'Fournisseurs - Achats de biens', 'type' => AccountType::LIABILITY],
            ['code' => '4017', 'name' => 'Fournisseurs - Retenues de garantie', 'type' => AccountType::LIABILITY],
            ['code' => '409', 'name' => 'Fournisseurs débiteurs', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::SUPPLIER_ADVANCE],
            
            ['code' => '41', 'name' => 'Clients et comptes rattachés', 'type' => AccountType::ASSET, 'is_system' => true],
            ['code' => '411', 'name' => 'Clients', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::CUSTOMER_RECEIVABLE, 'is_system' => true],
            ['code' => '4111', 'name' => 'Clients - Ventes de biens', 'type' => AccountType::ASSET],
            ['code' => '4117', 'name' => 'Clients - Retenues de garantie', 'type' => AccountType::ASSET],
            ['code' => '419', 'name' => 'Clients créditeurs', 'type' => AccountType::LIABILITY,
                'system_purpose' => SystemAccountPurpose::CUSTOMER_ADVANCE],
            
            ['code' => '42', 'name' => 'Personnel et comptes rattachés', 'type' => AccountType::LIABILITY],
            ['code' => '421', 'name' => 'Personnel - Rémunérations dues', 'type' => AccountType::LIABILITY],
            
            ['code' => '43', 'name' => 'État et collectivités publiques', 'type' => AccountType::LIABILITY, 'is_system' => true],
            ['code' => '4456', 'name' => 'TVA déductible', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::VAT_DEDUCTIBLE, 'is_system' => true],
            ['code' => '44562', 'name' => 'TVA déductible sur immobilisations', 'type' => AccountType::ASSET],
            ['code' => '44566', 'name' => 'TVA déductible sur biens et services', 'type' => AccountType::ASSET],
            ['code' => '4457', 'name' => 'TVA collectée', 'type' => AccountType::LIABILITY,
                'system_purpose' => SystemAccountPurpose::VAT_COLLECTED, 'is_system' => true],
            
            ['code' => '44', 'name' => 'État et autres collectivités', 'type' => AccountType::LIABILITY],
            ['code' => '45', 'name' => 'Groupe et associés', 'type' => AccountType::LIABILITY],
            ['code' => '46', 'name' => 'Débiteurs divers et créditeurs divers', 'type' => AccountType::ASSET],
            ['code' => '47', 'name' => 'Comptes transitoires ou d\'attente', 'type' => AccountType::ASSET],
            
            // Class 5 - Comptes financiers
            ['code' => '50', 'name' => 'Valeurs mobilières de placement', 'type' => AccountType::ASSET],
            ['code' => '51', 'name' => 'Banques, établissements financiers', 'type' => AccountType::ASSET, 'is_system' => true],
            ['code' => '512', 'name' => 'Banques', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::BANK, 'is_system' => true],
            ['code' => '53', 'name' => 'Caisse', 'type' => AccountType::ASSET,
                'system_purpose' => SystemAccountPurpose::CASH, 'is_system' => true],
            ['code' => '54', 'name' => 'Régies d\'avances et accréditifs', 'type' => AccountType::ASSET],
            
            // Class 6 - Comptes de charges
            ['code' => '60', 'name' => 'Achats', 'type' => AccountType::EXPENSE, 'is_system' => true],
            ['code' => '601', 'name' => 'Achats de matières premières', 'type' => AccountType::EXPENSE],
            ['code' => '607', 'name' => 'Achats de marchandises', 'type' => AccountType::EXPENSE,
                'system_purpose' => SystemAccountPurpose::PURCHASE_EXPENSES],
            ['code' => '61', 'name' => 'Services extérieurs', 'type' => AccountType::EXPENSE],
            ['code' => '62', 'name' => 'Autres services extérieurs', 'type' => AccountType::EXPENSE],
            ['code' => '63', 'name' => 'Impôts, taxes et versements assimilés', 'type' => AccountType::EXPENSE],
            ['code' => '64', 'name' => 'Charges de personnel', 'type' => AccountType::EXPENSE],
            ['code' => '65', 'name' => 'Autres charges de gestion courante', 'type' => AccountType::EXPENSE],
            ['code' => '66', 'name' => 'Charges financières', 'type' => AccountType::EXPENSE],
            ['code' => '67', 'name' => 'Charges exceptionnelles', 'type' => AccountType::EXPENSE],
            ['code' => '68', 'name' => 'Dotations aux amortissements', 'type' => AccountType::EXPENSE],
            ['code' => '69', 'name' => 'Impôts sur les bénéfices', 'type' => AccountType::EXPENSE],
            
            // Class 7 - Comptes de produits
            ['code' => '70', 'name' => 'Ventes de produits et services', 'type' => AccountType::REVENUE, 'is_system' => true],
            ['code' => '701', 'name' => 'Ventes de produits finis', 'type' => AccountType::REVENUE],
            ['code' => '706', 'name' => 'Prestations de services', 'type' => AccountType::REVENUE,
                'system_purpose' => SystemAccountPurpose::SERVICE_REVENUE],
            ['code' => '707', 'name' => 'Ventes de marchandises', 'type' => AccountType::REVENUE,
                'system_purpose' => SystemAccountPurpose::PRODUCT_REVENUE, 'is_system' => true],
            ['code' => '71', 'name' => 'Production stockée', 'type' => AccountType::REVENUE],
            ['code' => '72', 'name' => 'Production immobilisée', 'type' => AccountType::REVENUE],
            ['code' => '74', 'name' => 'Subventions d\'exploitation', 'type' => AccountType::REVENUE],
            ['code' => '75', 'name' => 'Autres produits de gestion courante', 'type' => AccountType::REVENUE],
            ['code' => '76', 'name' => 'Produits financiers', 'type' => AccountType::REVENUE],
            ['code' => '77', 'name' => 'Produits exceptionnels', 'type' => AccountType::REVENUE],
            ['code' => '78', 'name' => 'Reprises sur amortissements', 'type' => AccountType::REVENUE],
        ];
    }
    
    /**
     * Get the country code this seeder is for
     */
    public static function getCountryCode(): string
    {
        return 'TN';
    }
}
```

### Verification for Phase 2

```bash
# Test the seeder manually
php artisan tinker --execute="
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use App\Modules\Company\Domain\Company;

\$company = Company::first();
echo 'Testing seeder for company: ' . \$company->name . PHP_EOL;

// Clear existing accounts for this company (careful in production!)
// App\Modules\Accounting\Domain\Account::where('company_id', \$company->id)->delete();

// Run seeder
\$seeder = new TunisiaChartOfAccountsSeeder();
\$seeder->run(\$company->id);

// Verify key accounts have system_purpose
\$purposes = App\Modules\Accounting\Domain\Account::where('company_id', \$company->id)
    ->whereNotNull('system_purpose')
    ->get(['code', 'name', 'system_purpose']);
    
echo 'Accounts with system_purpose:' . PHP_EOL;
foreach (\$purposes as \$a) {
    echo '  ' . \$a->code . ' - ' . \$a->name . ' => ' . \$a->system_purpose->value . PHP_EOL;
}
"
```

---

## Phase 3: Create Chart of Accounts Service

### 3.1 Create ChartOfAccountsService

**File:** `app/Modules/Accounting/Application/Services/ChartOfAccountsService.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
// Future: use Database\Seeders\FranceChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ChartOfAccountsService
{
    /**
     * Seed chart of accounts for a newly created company
     * Automatically selects the appropriate seeder based on country
     */
    public function seedForCompany(Company $company): void
    {
        $seederClass = $this->getSeederForCountry($company->country_code);
        
        if (!$seederClass) {
            throw new RuntimeException(
                "No chart of accounts seeder available for country: {$company->country_code}. " .
                "Please create a seeder for this country first."
            );
        }
        
        DB::transaction(function () use ($company, $seederClass) {
            $seeder = new $seederClass();
            $seeder->run($company->id, $company->tenant_id);
        });
    }
    
    /**
     * Check if a company has required system accounts
     */
    public function validateCompanyAccounts(string $companyId): array
    {
        $missing = [];
        
        foreach (SystemAccountPurpose::requiredPurposes() as $purpose) {
            $account = Account::findByPurpose($companyId, $purpose);
            
            if (!$account) {
                $missing[] = $purpose->value;
            }
        }
        
        return [
            'valid' => empty($missing),
            'missing_purposes' => $missing,
        ];
    }
    
    /**
     * Get account by system purpose for a company
     */
    public function getAccountByPurpose(string $companyId, SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($companyId, $purpose);
    }
    
    /**
     * List all accounts with their system purposes for admin display
     */
    public function getAccountsWithPurposes(string $companyId): array
    {
        return Account::forCompany($companyId)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'system_purpose', 'is_system'])
            ->toArray();
    }
    
    /**
     * Assign a system purpose to an account (super admin function)
     * 
     * @throws RuntimeException if purpose already assigned to another account
     */
    public function assignPurpose(string $companyId, string $accountId, SystemAccountPurpose $purpose): void
    {
        // Check if another account already has this purpose
        $existing = Account::forCompany($companyId)
            ->withPurpose($purpose)
            ->where('id', '!=', $accountId)
            ->first();
            
        if ($existing) {
            throw new RuntimeException(
                "Purpose '{$purpose->value}' is already assigned to account {$existing->code} ({$existing->name}). " .
                "Remove it from that account first."
            );
        }
        
        $account = Account::where('company_id', $companyId)
            ->where('id', $accountId)
            ->firstOrFail();
            
        $account->update(['system_purpose' => $purpose]);
    }
    
    /**
     * Remove system purpose from an account
     */
    public function removePurpose(string $companyId, string $accountId): void
    {
        Account::where('company_id', $companyId)
            ->where('id', $accountId)
            ->update(['system_purpose' => null]);
    }
    
    /**
     * Get the seeder class for a given country
     */
    private function getSeederForCountry(string $countryCode): ?string
    {
        $seeders = [
            'TN' => TunisiaChartOfAccountsSeeder::class,
            // 'FR' => FranceChartOfAccountsSeeder::class,  // Future
            // 'IT' => ItalyChartOfAccountsSeeder::class,   // Future
        ];
        
        return $seeders[$countryCode] ?? null;
    }
    
    /**
     * Get list of supported countries for COA seeding
     */
    public function getSupportedCountries(): array
    {
        return ['TN']; // Add more as seeders are created
    }
}
```

### Verification for Phase 3

```bash
php artisan tinker --execute="
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;

\$service = app(ChartOfAccountsService::class);

// Check supported countries
echo 'Supported countries: ' . implode(', ', \$service->getSupportedCountries()) . PHP_EOL;

// Validate a company's accounts
\$company = Company::first();
\$result = \$service->validateCompanyAccounts(\$company->id);
echo 'Company accounts valid: ' . (\$result['valid'] ? 'YES' : 'NO') . PHP_EOL;
if (!empty(\$result['missing_purposes'])) {
    echo 'Missing: ' . implode(', ', \$result['missing_purposes']) . PHP_EOL;
}
"
```

---

## Phase 4: Fix GeneralLedgerService

### 4.1 Update GeneralLedgerService to Use System Purposes

**File:** `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

Replace all hardcoded account code lookups with system purpose lookups:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
// ... other imports

class GeneralLedgerService
{
    /**
     * Get account by system purpose - the ONLY way to lookup system accounts
     * 
     * NEVER use hardcoded account codes like '411' or '1200'
     * ALWAYS use this method with SystemAccountPurpose enum
     */
    private function getAccountByPurpose(string $companyId, SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($companyId, $purpose);
    }
    
    /**
     * Create journal entry for a validated invoice
     */
    public function createInvoiceJournalEntry(/* ... */): JournalEntry
    {
        // OLD (WRONG - hardcoded codes):
        // $receivableAccount = $this->getAccountByCode($companyId, '1200');
        // $revenueAccount = $this->getAccountByCode($companyId, '4000');
        // $vatAccount = $this->getAccountByCode($companyId, '2100');
        
        // NEW (CORRECT - system purpose):
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
        $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PRODUCT_REVENUE);
        $vatAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::VAT_COLLECTED);
        
        // ... rest of the method
    }
    
    /**
     * Create journal entry for a credit note (reverses invoice)
     */
    public function createCreditNoteJournalEntry(/* ... */): JournalEntry
    {
        // Use same purpose-based lookups
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
        $revenueAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::PRODUCT_REVENUE);
        $vatAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::VAT_COLLECTED);
        
        // ... rest of the method (debits/credits reversed)
    }
    
    /**
     * Create journal entry for a payment received
     */
    public function createPaymentReceivedJournalEntry(/* ... */): JournalEntry
    {
        $receivableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
        $bankAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::BANK);
        // Or for cash payments:
        // $cashAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CASH);
        
        // ... rest of the method
    }
    
    /**
     * Create journal entry for supplier payment
     */
    public function createSupplierPaymentJournalEntry(/* ... */): JournalEntry
    {
        $payableAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::SUPPLIER_PAYABLE);
        $bankAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::BANK);
        
        // ... rest of the method
    }
    
    /**
     * Create journal entry for customer advance/prepayment
     */
    public function createCustomerAdvanceJournalEntry(/* ... */): JournalEntry
    {
        $bankAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::BANK);
        $advanceAccount = $this->getAccountByPurpose($companyId, SystemAccountPurpose::CUSTOMER_ADVANCE);
        
        // ... rest of the method
    }
    
    // ... other methods following the same pattern
}
```

### 4.2 Search and Replace All Hardcoded Codes

Run this to find any remaining hardcoded account codes:

```bash
# Find all potential hardcoded account codes in Accounting module
grep -rn "getAccountByCode\|'411'\|'401'\|'512'\|'706'\|'707'\|'1200'\|'4000'\|'2100'" \
  app/Modules/Accounting/ \
  --include="*.php"

# Each occurrence must be replaced with getAccountByPurpose()
```

### Verification for Phase 4

```bash
# Verify no hardcoded account codes remain
grep -rn "'[0-9]\{3,4\}'" app/Modules/Accounting/Domain/Services/ --include="*.php" | grep -v "// " || echo "No hardcoded codes found ✓"

# Run tests
php artisan test --filter=GeneralLedger
php artisan test --filter=Accounting
```

---

## Phase 5: Integrate COA Seeding with Company Creation

### 5.1 Update CompanyController or CreateCompanyAction

Find where companies are created and add COA seeding:

```php
// In CompanyController::store() or CreateCompanyAction

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;

public function store(CreateCompanyRequest $request): JsonResponse
{
    $company = DB::transaction(function () use ($request) {
        // 1. Create the company
        $company = Company::create([
            'tenant_id' => auth()->user()->tenant_id,
            'name' => $request->name,
            'country_code' => $request->country_code,
            // ... other fields
        ]);
        
        // 2. Create default location
        Location::create([
            'company_id' => $company->id,
            'name' => 'Main',
            'is_default' => true,
        ]);
        
        // 3. Create owner membership
        UserCompanyMembership::create([
            'user_id' => auth()->id(),
            'company_id' => $company->id,
            'role' => 'owner',
        ]);
        
        // 4. Initialize hash chains
        $this->hashChainService->initializeForCompany($company);
        
        // 5. NEW: Seed chart of accounts based on country
        try {
            app(ChartOfAccountsService::class)->seedForCompany($company);
        } catch (\RuntimeException $e) {
            // Log warning but don't fail company creation
            // Country might not have a seeder yet
            \Log::warning("Could not seed COA for company {$company->id}: " . $e->getMessage());
        }
        
        return $company;
    });
    
    return response()->json($company, 201);
}
```

### Verification for Phase 5

```bash
# Test company creation flow
php artisan tinker --execute="
use App\Modules\Company\Domain\Company;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;

// Get the most recently created company
\$company = Company::latest()->first();
echo 'Latest company: ' . \$company->name . ' (' . \$company->country_code . ')' . PHP_EOL;

// Check if it has accounts
\$count = Account::where('company_id', \$company->id)->count();
echo 'Account count: ' . \$count . PHP_EOL;

// Check system purposes
\$purposes = Account::where('company_id', \$company->id)
    ->whereNotNull('system_purpose')
    ->pluck('system_purpose')
    ->toArray();
echo 'System purposes assigned: ' . implode(', ', \$purposes) . PHP_EOL;
"
```

---

## Phase 6: Super Admin API Endpoints (Optional but Recommended)

### 6.1 Create Admin Controller for Account Purpose Management

**File:** `app/Modules/Accounting/Presentation/Controllers/Admin/AccountPurposeController.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Presentation\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountPurposeController extends Controller
{
    public function __construct(
        private ChartOfAccountsService $chartOfAccountsService
    ) {}
    
    /**
     * GET /admin/companies/{companyId}/accounts/purposes
     * List all accounts with their system purposes
     */
    public function index(string $companyId): JsonResponse
    {
        $accounts = $this->chartOfAccountsService->getAccountsWithPurposes($companyId);
        
        return response()->json([
            'accounts' => $accounts,
            'available_purposes' => collect(SystemAccountPurpose::cases())->map(fn($p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ]),
        ]);
    }
    
    /**
     * GET /admin/companies/{companyId}/accounts/validate
     * Validate company has all required system accounts
     */
    public function validate(string $companyId): JsonResponse
    {
        $result = $this->chartOfAccountsService->validateCompanyAccounts($companyId);
        
        return response()->json($result);
    }
    
    /**
     * PUT /admin/companies/{companyId}/accounts/{accountId}/purpose
     * Assign a system purpose to an account
     */
    public function assignPurpose(Request $request, string $companyId, string $accountId): JsonResponse
    {
        $request->validate([
            'purpose' => ['required', 'string', 'in:' . implode(',', array_column(SystemAccountPurpose::cases(), 'value'))],
        ]);
        
        $purpose = SystemAccountPurpose::from($request->purpose);
        
        try {
            $this->chartOfAccountsService->assignPurpose($companyId, $accountId, $purpose);
            return response()->json(['message' => 'Purpose assigned successfully']);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
    
    /**
     * DELETE /admin/companies/{companyId}/accounts/{accountId}/purpose
     * Remove system purpose from an account
     */
    public function removePurpose(string $companyId, string $accountId): JsonResponse
    {
        $this->chartOfAccountsService->removePurpose($companyId, $accountId);
        
        return response()->json(['message' => 'Purpose removed successfully']);
    }
}
```

### 6.2 Add Routes

**File:** `routes/api.php` (or admin routes file)

```php
// Super Admin routes
Route::middleware(['auth:sanctum', 'super-admin'])->prefix('admin')->group(function () {
    // Account purpose management
    Route::get('companies/{companyId}/accounts/purposes', [AccountPurposeController::class, 'index']);
    Route::get('companies/{companyId}/accounts/validate', [AccountPurposeController::class, 'validate']);
    Route::put('companies/{companyId}/accounts/{accountId}/purpose', [AccountPurposeController::class, 'assignPurpose']);
    Route::delete('companies/{companyId}/accounts/{accountId}/purpose', [AccountPurposeController::class, 'removePurpose']);
});
```

---

## Phase 7: Tests

### 7.1 Create Unit Tests

**File:** `tests/Unit/Accounting/SystemAccountPurposeTest.php`

```php
<?php

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use PHPUnit\Framework\TestCase;

class SystemAccountPurposeTest extends TestCase
{
    public function test_all_required_purposes_exist(): void
    {
        $required = SystemAccountPurpose::requiredPurposes();
        
        $this->assertContains(SystemAccountPurpose::CUSTOMER_RECEIVABLE, $required);
        $this->assertContains(SystemAccountPurpose::CUSTOMER_ADVANCE, $required);
        $this->assertContains(SystemAccountPurpose::SUPPLIER_PAYABLE, $required);
        $this->assertContains(SystemAccountPurpose::VAT_COLLECTED, $required);
        $this->assertContains(SystemAccountPurpose::VAT_DEDUCTIBLE, $required);
    }
    
    public function test_all_purposes_have_labels(): void
    {
        foreach (SystemAccountPurpose::cases() as $purpose) {
            $this->assertNotEmpty($purpose->label());
        }
    }
}
```

**File:** `tests/Feature/Accounting/ChartOfAccountsServiceTest.php`

```php
<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsServiceTest extends TestCase
{
    use RefreshDatabase;
    
    private ChartOfAccountsService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ChartOfAccountsService::class);
    }
    
    public function test_seeds_chart_of_accounts_for_tunisia(): void
    {
        $company = Company::factory()->create(['country_code' => 'TN']);
        
        $this->service->seedForCompany($company);
        
        $accounts = Account::where('company_id', $company->id)->get();
        $this->assertGreaterThan(30, $accounts->count());
    }
    
    public function test_seeded_accounts_have_system_purposes(): void
    {
        $company = Company::factory()->create(['country_code' => 'TN']);
        
        $this->service->seedForCompany($company);
        
        // Verify key purposes are assigned
        foreach (SystemAccountPurpose::requiredPurposes() as $purpose) {
            $account = Account::findByPurpose($company->id, $purpose);
            $this->assertNotNull($account, "Account with purpose {$purpose->value} should exist");
        }
    }
    
    public function test_validate_company_accounts_passes_for_seeded_company(): void
    {
        $company = Company::factory()->create(['country_code' => 'TN']);
        $this->service->seedForCompany($company);
        
        $result = $this->service->validateCompanyAccounts($company->id);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['missing_purposes']);
    }
    
    public function test_validate_company_accounts_fails_for_empty_company(): void
    {
        $company = Company::factory()->create(['country_code' => 'TN']);
        // Don't seed
        
        $result = $this->service->validateCompanyAccounts($company->id);
        
        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['missing_purposes']);
    }
    
    public function test_throws_for_unsupported_country(): void
    {
        $company = Company::factory()->create(['country_code' => 'XX']);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No chart of accounts seeder available for country: XX');
        
        $this->service->seedForCompany($company);
    }
}
```

### Verification for Phase 7

```bash
php artisan test --filter=SystemAccountPurpose
php artisan test --filter=ChartOfAccountsService
php artisan test
```

---

## Final Verification Checklist

After completing all phases, run:

```bash
echo "=== FINAL VERIFICATION ==="

echo -e "\n1. Database schema check:"
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'accounts.company_id: ' . (Schema::hasColumn('accounts', 'company_id') ? '✓' : '✗') . PHP_EOL;
echo 'accounts.system_purpose: ' . (Schema::hasColumn('accounts', 'system_purpose') ? '✓' : '✗') . PHP_EOL;
"

echo -e "\n2. Enum check:"
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
echo 'SystemAccountPurpose cases: ' . count(SystemAccountPurpose::cases()) . PHP_EOL;
"

echo -e "\n3. Service check:"
php artisan tinker --execute="
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
\$service = app(ChartOfAccountsService::class);
echo 'ChartOfAccountsService: ✓' . PHP_EOL;
echo 'Supported countries: ' . implode(', ', \$service->getSupportedCountries()) . PHP_EOL;
"

echo -e "\n4. No hardcoded account codes in GL service:"
grep -rn "'[0-9]\{3,4\}'" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | grep -v "//" && echo "⚠ Found hardcoded codes!" || echo "✓ No hardcoded codes"

echo -e "\n5. All tests pass:"
php artisan test --filter=Accounting

echo -e "\n6. PHPStan check:"
./vendor/bin/phpstan analyse app/Modules/Accounting --level=8
```

---

## Summary

| Phase | Description | Estimated Time |
|-------|-------------|----------------|
| 1 | Database schema + enum | 1 hour |
| 2 | Update Tunisia seeder | 1 hour |
| 3 | ChartOfAccountsService | 1 hour |
| 4 | Fix GeneralLedgerService | 1-2 hours |
| 5 | Company creation integration | 30 min |
| 6 | Super admin endpoints | 1 hour |
| 7 | Tests | 1 hour |
| **Total** | | **6-8 hours** |

## After This Implementation

Once this is complete, the GL subledger implementation can proceed because:
1. ✅ Accounts are company-scoped
2. ✅ Accounts have system_purpose for country-agnostic lookups
3. ✅ GeneralLedgerService uses purpose-based lookups
4. ✅ New companies automatically get their chart of accounts
5. ✅ Super admin can manage account purposes

## Commit Strategy

Create one PR with atomic commits:
1. `feat(accounting): add SystemAccountPurpose enum`
2. `feat(accounting): add company_id and system_purpose to accounts`
3. `refactor(accounting): update Tunisia COA seeder with system purposes`
4. `feat(accounting): add ChartOfAccountsService`
5. `fix(accounting): replace hardcoded account codes with system purpose lookups`
6. `feat(company): seed COA on company creation`
7. `feat(admin): add account purpose management endpoints`
8. `test(accounting): add tests for system account purposes`
