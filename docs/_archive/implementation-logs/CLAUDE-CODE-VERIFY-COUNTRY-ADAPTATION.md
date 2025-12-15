# Country Adaptation - Post-Implementation Verification

## Context

We just implemented the Country Adaptation account abstraction layer. Before proceeding to the GL subledger implementation, we need to verify everything works correctly.

## Your Task

Run all verification checks and report results. Do NOT fix anything - just report what you find.

---

## Verification Script

Run each section and report results:

### 1. Database Schema Verification

```bash
echo "=== 1. DATABASE SCHEMA ==="
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

echo 'accounts.company_id: ' . (Schema::hasColumn('accounts', 'company_id') ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL;
echo 'accounts.system_purpose: ' . (Schema::hasColumn('accounts', 'system_purpose') ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL;

// Check index exists
\$indexes = DB::select(\"SELECT indexname FROM pg_indexes WHERE tablename = 'accounts' AND indexname LIKE '%purpose%'\");
echo 'Purpose index: ' . (count(\$indexes) > 0 ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL;

// Check unique constraint
\$constraints = DB::select(\"SELECT conname FROM pg_constraint WHERE conname LIKE '%purpose%'\");
echo 'Purpose unique constraint: ' . (count(\$constraints) > 0 ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL;
"
```

### 2. Enum Verification

```bash
echo -e "\n=== 2. SYSTEM ACCOUNT PURPOSE ENUM ==="
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;

echo 'Enum exists: ✓' . PHP_EOL;
echo 'Total cases: ' . count(SystemAccountPurpose::cases()) . PHP_EOL;
echo PHP_EOL . 'Required purposes:' . PHP_EOL;
foreach (SystemAccountPurpose::requiredPurposes() as \$p) {
    echo '  - ' . \$p->value . ' => ' . \$p->label() . PHP_EOL;
}
"
```

### 3. Account Model Methods

```bash
echo -e "\n=== 3. ACCOUNT MODEL METHODS ==="
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Account;
use ReflectionClass;

\$reflection = new ReflectionClass(Account::class);
\$methods = ['findByPurpose', 'findByPurposeOrFail', 'scopeForCompany', 'scopeWithPurpose'];

foreach (\$methods as \$method) {
    \$exists = \$reflection->hasMethod(\$method);
    echo \$method . '(): ' . (\$exists ? '✓ EXISTS' : '✗ MISSING') . PHP_EOL;
}

// Check fillable includes new fields
\$account = new Account();
\$fillable = \$account->getFillable();
echo PHP_EOL . 'Fillable fields:' . PHP_EOL;
echo '  company_id: ' . (in_array('company_id', \$fillable) ? '✓' : '✗') . PHP_EOL;
echo '  system_purpose: ' . (in_array('system_purpose', \$fillable) ? '✓' : '✗') . PHP_EOL;

// Check casts
\$casts = \$account->getCasts();
echo PHP_EOL . 'system_purpose cast: ' . (isset(\$casts['system_purpose']) ? '✓ ' . \$casts['system_purpose'] : '✗ MISSING') . PHP_EOL;
"
```

### 4. ChartOfAccountsService

```bash
echo -e "\n=== 4. CHART OF ACCOUNTS SERVICE ==="
php artisan tinker --execute="
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use ReflectionClass;

\$service = app(ChartOfAccountsService::class);
echo 'Service instantiates: ✓' . PHP_EOL;

\$reflection = new ReflectionClass(\$service);
\$methods = ['seedForCompany', 'validateCompanyAccounts', 'getAccountByPurpose', 'getSupportedCountries', 'assignPurpose', 'removePurpose'];

echo PHP_EOL . 'Methods:' . PHP_EOL;
foreach (\$methods as \$method) {
    \$exists = \$reflection->hasMethod(\$method);
    echo '  ' . \$method . '(): ' . (\$exists ? '✓' : '✗') . PHP_EOL;
}

echo PHP_EOL . 'Supported countries: ' . implode(', ', \$service->getSupportedCountries()) . PHP_EOL;
"
```

### 5. GeneralLedgerService - No Hardcoded Codes

```bash
echo -e "\n=== 5. GENERAL LEDGER SERVICE ==="

# Check it imports SystemAccountPurpose
echo "Imports SystemAccountPurpose:"
grep -c "use.*SystemAccountPurpose" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php && echo "  ✓ YES" || echo "  ✗ NO"

# Check for any remaining hardcoded account codes (3-4 digit numbers in quotes)
echo -e "\nHardcoded account codes (should be 0):"
HARDCODED=$(grep -E "'[0-9]{3,4}'" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | grep -v "//" | grep -v "system_purpose" | wc -l)
echo "  Found: $HARDCODED"
if [ "$HARDCODED" -gt 0 ]; then
    echo "  ⚠ WARNING: Found hardcoded codes:"
    grep -E "'[0-9]{3,4}'" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php | grep -v "//" | grep -v "system_purpose"
else
    echo "  ✓ No hardcoded account codes"
fi

# Check it uses findByPurposeOrFail or similar
echo -e "\nUses purpose-based lookups:"
grep -c "findByPurpose\|getAccountByPurpose\|SystemAccountPurpose::" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php && echo "  ✓ YES" || echo "  ✗ NO"
```

### 6. CompanyController Seeds COA

```bash
echo -e "\n=== 6. COMPANY CONTROLLER ==="

echo "Imports ChartOfAccountsService:"
grep -c "use.*ChartOfAccountsService" app/Modules/Company/Presentation/Controllers/CompanyController.php && echo "  ✓ YES" || echo "  ✗ NO"

echo -e "\nCalls seedForCompany:"
grep -c "seedForCompany" app/Modules/Company/Presentation/Controllers/CompanyController.php && echo "  ✓ YES" || echo "  ✗ NO"

echo -e "\nStore method snippet:"
grep -A 20 "function store" app/Modules/Company/Presentation/Controllers/CompanyController.php | head -25
```

### 7. Tunisia Seeder Updated

```bash
echo -e "\n=== 7. TUNISIA SEEDER ==="

echo "Has system_purpose assignments:"
grep -c "system_purpose" database/seeders/TunisiaChartOfAccountsSeeder.php && echo "  ✓ YES" || echo "  ✗ NO"

echo -e "\nKey account purposes:"
grep -E "CUSTOMER_RECEIVABLE|SUPPLIER_PAYABLE|VAT_COLLECTED|BANK" database/seeders/TunisiaChartOfAccountsSeeder.php | head -10
```

### 8. API Routes

```bash
echo -e "\n=== 8. API ROUTES ==="
php artisan route:list --path=purposes 2>/dev/null || php artisan route:list | grep -E "purpose"
```

### 9. Tests Pass

```bash
echo -e "\n=== 9. TESTS ==="
php artisan test --filter=SystemAccountPurpose
php artisan test --filter=ChartOfAccountsService
```

### 10. Real Data Verification

```bash
echo -e "\n=== 10. REAL DATA TEST ==="
php artisan tinker --execute="
use App\Modules\Company\Domain\Company;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;

// Find a Tunisia company
\$company = Company::where('country_code', 'TN')->first();

if (!\$company) {
    echo '⚠ No Tunisia company found in database' . PHP_EOL;
    echo 'Creating test company...' . PHP_EOL;
    // Don't actually create - just report
    exit(0);
}

echo 'Company: ' . \$company->name . ' (ID: ' . \$company->id . ')' . PHP_EOL;
echo 'Country: ' . \$company->country_code . PHP_EOL;

// Count accounts
\$totalAccounts = Account::where('company_id', \$company->id)->count();
echo PHP_EOL . 'Total accounts: ' . \$totalAccounts . PHP_EOL;

if (\$totalAccounts === 0) {
    echo '⚠ No accounts found for this company' . PHP_EOL;
    echo 'This could mean:' . PHP_EOL;
    echo '  - Company was created before COA auto-seeding was added' . PHP_EOL;
    echo '  - Migration did not properly assign company_id to existing accounts' . PHP_EOL;
    exit(0);
}

// Accounts with system_purpose
\$purposeAccounts = Account::where('company_id', \$company->id)
    ->whereNotNull('system_purpose')
    ->get(['code', 'name', 'system_purpose']);

echo 'Accounts with system_purpose: ' . \$purposeAccounts->count() . PHP_EOL;

if (\$purposeAccounts->count() > 0) {
    echo PHP_EOL . 'System purpose assignments:' . PHP_EOL;
    foreach (\$purposeAccounts as \$a) {
        echo '  ' . str_pad(\$a->code, 6) . ' | ' . str_pad(\$a->system_purpose->value, 25) . ' | ' . \$a->name . PHP_EOL;
    }
}

// Validate
echo PHP_EOL . 'Validation:' . PHP_EOL;
\$service = app(ChartOfAccountsService::class);
\$result = \$service->validateCompanyAccounts(\$company->id);

if (\$result['valid']) {
    echo '  ✓ All required system purposes are assigned' . PHP_EOL;
} else {
    echo '  ✗ Missing purposes: ' . implode(', ', \$result['missing_purposes']) . PHP_EOL;
}

// Test lookups
echo PHP_EOL . 'Lookup tests:' . PHP_EOL;
foreach (SystemAccountPurpose::requiredPurposes() as \$purpose) {
    try {
        \$account = Account::findByPurposeOrFail(\$company->id, \$purpose);
        echo '  ' . str_pad(\$purpose->value, 25) . ' => ' . \$account->code . ' ✓' . PHP_EOL;
    } catch (\Exception \$e) {
        echo '  ' . str_pad(\$purpose->value, 25) . ' => ✗ NOT FOUND' . PHP_EOL;
    }
}
"
```

### 11. PHPStan Check

```bash
echo -e "\n=== 11. PHPSTAN ==="
./vendor/bin/phpstan analyse app/Modules/Accounting --level=8 --no-progress
```

---

## Report Format

After running all checks, provide a summary:

```markdown
# Country Adaptation Verification Report

## Summary
| Check | Status |
|-------|--------|
| 1. Database schema | ✓/✗ |
| 2. Enum | ✓/✗ |
| 3. Account model | ✓/✗ |
| 4. ChartOfAccountsService | ✓/✗ |
| 5. GeneralLedgerService | ✓/✗ |
| 6. CompanyController | ✓/✗ |
| 7. Tunisia seeder | ✓/✗ |
| 8. API routes | ✓/✗ |
| 9. Tests | ✓/✗ |
| 10. Real data | ✓/✗ |
| 11. PHPStan | ✓/✗ |

## Issues Found
[List any issues]

## Recommendations
[Any fixes needed before proceeding]

## Ready for GL Subledger?
YES / NO (with reason)
```
