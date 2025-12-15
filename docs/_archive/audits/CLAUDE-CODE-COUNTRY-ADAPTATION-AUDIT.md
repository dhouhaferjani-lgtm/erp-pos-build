# Country Adaptation Module - Implementation Audit

## Context

We're about to implement a GL subledger integration that needs to look up accounts by "system purpose" (e.g., `customer_receivable`) rather than hardcoded account codes (e.g., `411000`). This is critical for multi-country support where different countries have different chart of accounts codes.

Before implementing the GL subledger, we need to verify what Country Adaptation infrastructure already exists and what needs to be built.

## Your Task

Perform a comprehensive audit of the existing codebase to determine:
1. What Country Adaptation components already exist
2. What's missing
3. How the current Chart of Accounts seeding works
4. How company creation currently handles country-specific setup

## Audit Checklist

### 1. Database Tables - Check Existence

Run these commands and report results:

```bash
# Check each table exists and show its structure
echo "=== COUNTRIES TABLE ===" 
php artisan db:table countries 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COUNTRY_COMPLIANCE_PROFILES TABLE ===" 
php artisan db:table country_compliance_profiles 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COUNTRY_TAX_RATES TABLE ===" 
php artisan db:table country_tax_rates 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COMPANY_ONBOARDING_STEPS TABLE ===" 
php artisan db:table company_onboarding_steps 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COMPANY_ONBOARDING_FIELDS TABLE ===" 
php artisan db:table company_onboarding_fields 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COMPANY_DOCUMENT_TYPES TABLE ===" 
php artisan db:table company_document_types 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== COUNTRY_VERIFICATION_TIERS TABLE ===" 
php artisan db:table country_verification_tiers 2>&1 || echo "TABLE NOT EXISTS"

echo -e "\n=== ACCOUNTS TABLE (check for system_purpose column) ===" 
php artisan db:table accounts 2>&1 || echo "TABLE NOT EXISTS"
```

### 2. Check Companies Table Structure

```bash
echo "=== COMPANIES TABLE STRUCTURE ===" 
php artisan db:table companies

# Specifically check if country_code column exists
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'country_code column exists: ' . (Schema::hasColumn('companies', 'country_code') ? 'YES' : 'NO') . PHP_EOL;
echo 'country_id column exists: ' . (Schema::hasColumn('companies', 'country_id') ? 'YES' : 'NO') . PHP_EOL;
"
```

### 3. Check Existing Seeders

```bash
echo "=== LIST ALL SEEDERS ===" 
ls -la database/seeders/

echo -e "\n=== TUNISIA CHART OF ACCOUNTS SEEDER ===" 
cat database/seeders/TunisiaChartOfAccountsSeeder.php 2>/dev/null || echo "FILE NOT EXISTS"

echo -e "\n=== ANY COUNTRY SEEDERS ===" 
ls -la database/seeders/*Country* 2>/dev/null || echo "NO COUNTRY SEEDERS FOUND"
ls -la database/seeders/*Tunisia* 2>/dev/null || echo "NO TUNISIA SEEDERS FOUND"

echo -e "\n=== DATABASE SEEDER (main) ===" 
cat database/seeders/DatabaseSeeder.php
```

### 4. Check Existing Migrations

```bash
echo "=== MIGRATIONS RELATED TO COUNTRIES/ACCOUNTS ===" 
ls -la database/migrations/ | grep -E "(country|countries|account|compliance)" || echo "NO MATCHING MIGRATIONS"

echo -e "\n=== ACCOUNTS MIGRATION ===" 
cat database/migrations/*create_accounts_table*.php 2>/dev/null || echo "NOT FOUND"

echo -e "\n=== ANY COUNTRY-RELATED MIGRATIONS ===" 
cat database/migrations/*countries*.php 2>/dev/null || echo "NOT FOUND"
```

### 5. Check Existing Models

```bash
echo "=== COUNTRY ADAPTATION MODULE ===" 
ls -la app/Modules/CountryAdaptation/ 2>/dev/null || echo "MODULE NOT EXISTS"

echo -e "\n=== ACCOUNT MODEL ===" 
cat app/Modules/Accounting/Domain/Account.php 2>/dev/null || \
cat app/Modules/GeneralLedger/Domain/Account.php 2>/dev/null || \
find app -name "Account.php" -exec echo "Found: {}" \; -exec cat {} \; 2>/dev/null || \
echo "ACCOUNT MODEL NOT FOUND"

echo -e "\n=== COMPANY MODEL ===" 
cat app/Modules/Identity/Domain/Company.php 2>/dev/null || \
find app -name "Company.php" -type f -exec echo "Found: {}" \; -exec head -100 {} \; 2>/dev/null
```

### 6. Check Company Creation Flow

```bash
echo "=== HOW IS COMPANY CREATED? ===" 
grep -r "Company::create" app/ --include="*.php" -l
grep -r "new Company" app/ --include="*.php" -l

echo -e "\n=== COMPANY CREATION ACTION/SERVICE ===" 
find app -name "*CreateCompany*" -o -name "*CompanyCreation*" | xargs -I {} sh -c 'echo "=== {} ===" && cat {}'

echo -e "\n=== REGISTRATION/SIGNUP FLOW ===" 
find app -name "*Register*" -o -name "*Signup*" | head -5 | xargs -I {} sh -c 'echo "=== {} ===" && cat {}'
```

### 7. Check Chart of Accounts Seeding Trigger

```bash
echo "=== WHEN IS CHART OF ACCOUNTS SEEDED? ===" 
grep -r "ChartOfAccounts" app/ --include="*.php" -A 5 -B 5
grep -r "TunisiaChart" app/ --include="*.php" -A 5 -B 5
grep -r "seedAccounts" app/ --include="*.php" -A 5 -B 5
```

### 8. Check Existing Enums

```bash
echo "=== EXISTING ENUMS ===" 
ls -la app/Modules/*/Domain/Enums/ 2>/dev/null || ls -la app/Enums/ 2>/dev/null || echo "NO ENUMS DIRECTORY"

echo -e "\n=== ACCOUNT TYPE ENUM ===" 
find app -name "AccountType.php" -exec cat {} \;

echo -e "\n=== ANY COUNTRY-RELATED ENUMS ===" 
find app -name "*Country*.php" -path "*/Enums/*" -exec echo "Found: {}" \; -exec cat {} \;
```

### 9. Check Current Account Data

```bash
echo "=== SAMPLE ACCOUNTS IN DATABASE ===" 
php artisan tinker --execute="
use App\Modules\Accounting\Domain\Account;
// Or try alternative namespace
// use App\Modules\GeneralLedger\Domain\Account;

try {
    \$accounts = Account::limit(20)->get(['id', 'code', 'name', 'type', 'company_id']);
    echo \$accounts->toJson(JSON_PRETTY_PRINT);
} catch (\Exception \$e) {
    echo 'Error: ' . \$e->getMessage();
}
"
```

### 10. Check GeneralLedgerService

```bash
echo "=== GENERAL LEDGER SERVICE ===" 
find app -name "GeneralLedgerService.php" -exec cat {} \; 2>/dev/null || echo "NOT FOUND"

echo -e "\n=== ANY HARDCODED ACCOUNT CODES ===" 
grep -r "411000\|419000\|512000\|706000" app/ --include="*.php" -n
```

## Report Format

After running all checks, provide a summary report in this format:

```markdown
# Country Adaptation Audit Report

## Executive Summary
[One paragraph: what exists, what's missing, overall readiness]

## Tables Status

| Table | Exists | Has Data | Notes |
|-------|--------|----------|-------|
| countries | YES/NO | YES/NO | |
| country_compliance_profiles | YES/NO | YES/NO | |
| country_tax_rates | YES/NO | YES/NO | |
| accounts | YES/NO | YES/NO | Has system_purpose: YES/NO |
| companies | YES/NO | YES/NO | Has country_code: YES/NO |

## Existing Components

### What's Already Built
- [ ] List components that exist
- [ ] Note their current state

### What's Missing
- [ ] List components that need to be created

## Chart of Accounts Current State
- How is it seeded currently?
- Is it tied to company creation?
- Is it country-aware?

## Company Creation Flow
- What happens when a company is created?
- Is country selected during creation?
- Is chart of accounts auto-seeded?

## Hardcoded Account Codes Found
- List any hardcoded account codes in the codebase
- These will need to be converted to system_purpose lookups

## Recommendations
1. [Priority order for implementation]
2. [Dependencies between components]
3. [Risks or concerns]
```

## Do Not Implement Anything Yet

This is an audit task only. Do not:
- Create migrations
- Modify code
- Add new files

Just gather information and report back.
