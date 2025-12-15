# Country Adaptation Audit Report

**Date:** 2025-12-05
**Purpose:** Assess country adaptation infrastructure before GL subledger implementation

---

## Executive Summary

The codebase has **basic country infrastructure** (countries table, tax rates) but **lacks the critical account abstraction layer** needed for multi-country GL integration. The `GeneralLedgerService` uses **hardcoded Tunisian account codes** (`1200`, `4000`, `2100`) which will fail for French companies using different chart of accounts codes.

**Key Finding:** Chart of accounts is scoped by `tenant_id` not `company_id`, and there's no `system_purpose` column to lookup accounts by purpose (e.g., "customer_receivable") rather than by code.

**Recommendation:** Before implementing GL subledger, add `system_purpose` column to accounts and update `GeneralLedgerService` to lookup by purpose, not hardcoded codes.

---

## Tables Status

| Table | Exists | Has Data | Notes |
|-------|--------|----------|-------|
| `countries` | ✅ YES | ✅ YES (TN, FR) | Primary key is `code` (char 2) |
| `country_compliance_profiles` | ❌ NO | - | Not created |
| `country_tax_rates` | ✅ YES | ✅ YES | VAT rates per country |
| `accounts` | ✅ YES | ❓ Manual seed | **Missing `system_purpose` and `company_id`** |
| `companies` | ✅ YES | ✅ YES | Has `country_code` ✅ |

---

## Existing Components

### What's Already Built

#### 1. Countries Infrastructure
- **`countries` table** with fields:
  - `code` (PK, char 2) - ISO country code
  - `name`, `native_name`
  - `currency_code`, `currency_symbol`
  - `phone_prefix`, `date_format`
  - `default_locale`, `default_timezone`
  - `is_active`
  - `tax_id_label`, `tax_id_regex` (for validation)

- **`Country` model** at `app/Models/Country.php`
- **`CountriesSeeder`** - Seeds TN (Tunisia) and FR (France)

#### 2. Tax Rates
- **`country_tax_rates` table** with:
  - `country_code` (FK to countries)
  - `name`, `rate`, `code`
  - `is_default`, `is_active`

- **`CountryTaxRatesSeeder`** - Seeds VAT rates for TN and FR

#### 3. Company Model
- **`Company` model** at `app/Modules/Company/Domain/Company.php`
- Has `country_code` field ✅
- Has `currency`, `locale`, `timezone` fields ✅
- Company creation flow sets `country_code` from user input

#### 4. Chart of Accounts (Partial)
- **`accounts` table** exists with:
  - `tenant_id` (FK) - **PROBLEM: should be `company_id`**
  - `code`, `name`, `type`
  - `is_active`, `is_system`, `balance`
  - **NO `system_purpose` column** ❌
  - **NO `company_id` column** ❌

- **`TunisiaChartOfAccountsSeeder`** - Seeds full Tunisian PCG
  - Takes `tenant_id` parameter
  - **NOT called during company creation**
  - Uses Tunisian codes (411, 512, 706, etc.)

---

### What's Missing

#### 1. `system_purpose` Column on Accounts
**CRITICAL for multi-country support**

Currently, `GeneralLedgerService` does:
```php
$receivableAccount = $this->getAccountByCode($companyId, '1200');  // Tunisian code!
```

Should do:
```php
$receivableAccount = $this->getAccountByPurpose($companyId, 'customer_receivable');
```

**Required `system_purpose` values:**
| Purpose | Tunisia Code | France Code | Description |
|---------|--------------|-------------|-------------|
| `customer_receivable` | 411 | 411 | Accounts Receivable |
| `customer_advance` | 419 | 4191 | Customer Advances/Credit |
| `supplier_payable` | 401 | 401 | Accounts Payable |
| `vat_collected` | 4457 | 44571 | VAT Collected |
| `vat_deductible` | 4456 | 44566 | VAT Deductible |
| `sales_revenue` | 706/707 | 706/707 | Sales Revenue |
| `bank` | 512 | 512 | Bank Accounts |
| `cash` | 531 | 53 | Cash |

#### 2. `company_id` on Accounts
Currently scoped by `tenant_id`. A tenant with multiple companies (e.g., one in Tunisia, one in France) would share the same chart of accounts - **INCORRECT**.

#### 3. CountryAdaptation Module
No dedicated module exists at `app/Modules/CountryAdaptation/`.

#### 4. Automatic COA Seeding on Company Creation
**`CompanyController::store()` does NOT seed chart of accounts.**

Current flow:
1. Create company
2. Create default location
3. Create owner membership
4. Initialize hash chains
5. **MISSING: Seed country-appropriate chart of accounts**

#### 5. Country Compliance Profiles
No `country_compliance_profiles` table to define:
- Required document types per country
- Compliance requirements (NF525, Factur-X, etc.)
- Default sequences/prefixes

---

## Chart of Accounts Current State

### How is it seeded currently?
- **Manual** - Run `TunisiaChartOfAccountsSeeder` with tenant_id parameter
- **Not triggered** during company creation
- **Not country-aware** - only Tunisia seeder exists

### Is it tied to company creation?
**NO** - Must be manually seeded after company creation.

### Is it country-aware?
**PARTIAL** - `TunisiaChartOfAccountsSeeder` exists, but:
- No France seeder
- No way to auto-select seeder based on `company.country_code`
- No abstraction layer for country-specific account codes

---

## Company Creation Flow

### What happens when a company is created?

```
CompanyController::store()
├── 1. Create Company with country_code
├── 2. Create default Location
├── 3. Create UserCompanyMembership (owner)
├── 4. Initialize hash chains (for fiscal docs)
└── ❌ MISSING: Seed chart of accounts
```

### Is country selected during creation?
**YES** - `country_code` is a required field in `CreateCompanyRequest`.

### Is chart of accounts auto-seeded?
**NO** - Must be done manually.

---

## Hardcoded Account Codes Found

**Location:** `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

| Line | Code | Purpose | Problem |
|------|------|---------|---------|
| 31 | `'1200'` | Accounts Receivable | Non-standard code |
| 32 | `'4000'` | Sales Revenue | Non-standard code |
| 33 | `'2100'` | VAT Payable | Non-standard code |
| 100-102 | Same | Credit Note reversal | Same issue |

**These codes don't match the seeded Tunisian chart!**
- Tunisia uses `411` for AR, not `1200`
- Tunisia uses `706/707` for Revenue, not `4000`
- Tunisia uses `4457` for VAT Collected, not `2100`

**This is a bug** - the GL service won't work even for Tunisia.

---

## Recommendations

### Priority 1: Fix Immediate GL Issues

1. **Add `company_id` to accounts table**
   ```sql
   ALTER TABLE accounts ADD COLUMN company_id UUID REFERENCES companies(id);
   -- Migrate existing tenant_id data to company_id
   ```

2. **Add `system_purpose` to accounts table**
   ```sql
   ALTER TABLE accounts ADD COLUMN system_purpose VARCHAR(50);
   CREATE INDEX idx_accounts_purpose ON accounts(company_id, system_purpose);
   ```

3. **Update TunisiaChartOfAccountsSeeder**
   - Add `system_purpose` to key accounts:
     - `411` → `customer_receivable`
     - `419` → `customer_advance`
     - `401` → `supplier_payable`
     - `4457` → `vat_collected`
     - `706/707` → `sales_revenue`
     - `512` → `bank`
     - `531` → `cash`

4. **Fix GeneralLedgerService**
   ```php
   // Change from:
   $this->getAccountByCode($companyId, '1200');

   // To:
   $this->getAccountByPurpose($companyId, 'customer_receivable');
   ```

### Priority 2: Automate COA Seeding

5. **Create COA seeding service**
   ```php
   class ChartOfAccountsService {
       public function seedForCompany(Company $company): void {
           $seeder = match($company->country_code) {
               'TN' => new TunisiaChartOfAccountsSeeder(),
               'FR' => new FranceChartOfAccountsSeeder(),
               default => throw new UnsupportedCountryException(),
           };
           $seeder->run($company->id);
       }
   }
   ```

6. **Call from CompanyController::store()**
   ```php
   // After creating company...
   app(ChartOfAccountsService::class)->seedForCompany($company);
   ```

### Priority 3: Country Adaptation Module (Future)

7. Create `app/Modules/CountryAdaptation/` with:
   - Country compliance profiles
   - Document type configurations
   - Validation rules per country
   - E-invoicing requirements

---

## Dependencies for GL Subledger

Before implementing GL subledger (partner_id on journal_lines), these must be done:

| Dependency | Status | Blocks |
|------------|--------|--------|
| `accounts.company_id` | ❌ Missing | Account lookups |
| `accounts.system_purpose` | ❌ Missing | GL service account resolution |
| Fix hardcoded codes in GL service | ❌ Bug | Any GL operations |
| Auto-seed COA on company creation | ❌ Missing | New companies have no accounts |

**Recommendation:** Fix these 4 items BEFORE proceeding with GL subledger implementation.

---

## Estimated Effort

| Task | Estimate |
|------|----------|
| Add `company_id` to accounts + migrate | 1-2 hours |
| Add `system_purpose` + update seeder | 1 hour |
| Fix GeneralLedgerService | 1 hour |
| Add COA seeding to company creation | 1 hour |
| Create France COA seeder | 2-3 hours |
| Testing | 2 hours |
| **Total** | **8-10 hours** |

---

## Summary

| Component | Status | Action Required |
|-----------|--------|-----------------|
| Countries table | ✅ Good | None |
| Tax rates | ✅ Good | None |
| Company.country_code | ✅ Good | None |
| accounts.company_id | ❌ Missing | Add migration |
| accounts.system_purpose | ❌ Missing | Add migration + update seeder |
| GeneralLedgerService | ❌ Broken | Fix hardcoded codes |
| Auto COA seeding | ❌ Missing | Add to company creation |
| France COA seeder | ❌ Missing | Create new seeder |
| CountryAdaptation module | ❌ Missing | Future work |

**Verdict:** Cannot proceed with GL subledger until accounts table and GL service are fixed.
