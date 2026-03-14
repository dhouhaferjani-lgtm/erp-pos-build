# Country Readiness — Multi-Country Registration

**Goal:** Allow users from any country to sign up and use AutoERP with sensible defaults, while providing rich pre-configured data for France and Tunisia.

**Priority regions for future deep support:**
1. MENA (Middle East & North Africa)
2. Africa
3. Europe / Rest of World

---

## Current State

Only **France (FR)** and **Tunisia (TN)** are seeded in the `countries` table. Registration is blocked for all other countries.

### What's country-dependent today

| Area | FR | TN | Others | Fallback |
|------|----|----|--------|----------|
| Country record (currency, locale, tz) | Seeded | Seeded | **Missing** — blocks signup | None |
| Chart of Accounts | Plan Comptable Général (PCG) ~50 accounts | Plan Comptable National (PCN) ~50 accounts | **Missing** | Falls back to France (wrong) |
| Tax rates | TVA 20/10/5.5/2.1% | TVA 19/13/7/0% + stamp duties | **Missing** | No taxes seeded |
| Withholding rules | N/A | 10 rules | N/A | Only TN needs this |
| Fiscal year rules | Calendar year, flexible | Calendar year, flexible | Defaults to FR | Reasonable |
| Currency/timezone/locale | Hardcoded mapping in AuthController | Same | ~20 countries mapped | EUR/UTC/en |
| Compliance | NF525 (POS fiscal chain) | TEJ (withholding exports) | Not enforced | Gated by module |
| Document numbering | Generic `INV-YYYY-NNNN` | Same | Same | Country-agnostic |
| Payment methods | Generic set | Same | Same | Country-agnostic |

### What does NOT need to change
- Document numbering (already country-agnostic)
- Payment methods (generic set works globally)
- Permissions / roles
- Compliance modules (already gated to FR/TN)
- Core accounting engine (double-entry logic is universal)

---

## Implementation Plan

### Task 1: Expand Countries Seeder
**Status:** Done
**Files:** `apps/api/database/seeders/CountriesSeeder.php`

Seed ~40 countries covering:
- All EU members (EUR + local currencies)
- MENA region (SA, AE, QA, KW, BH, OM, EG, JO, LB, IQ, LY)
- North/West/East Africa (MA, DZ, SN, CI, CM, NG, KE, GH, ET)
- UK, US, Canada
- Turkey

Each country needs: `code`, `name`, `native_name`, `currency_code`, `currency_symbol`, `currency_decimal_places`, `phone_prefix`, `date_format`, `default_locale`, `default_timezone`, `is_active`, `tax_id_label`, `tax_id_regex`.

For `tax_id_regex`: use a permissive pattern for countries where we don't know the exact format (e.g., `^.{4,30}$`). Users can always edit later.

### Task 2: Generic Chart of Accounts Seeder
**Status:** Done
**Files:** New `apps/api/database/seeders/GenericChartOfAccountsSeeder.php`

Create a minimal international chart of accounts (~25-30 accounts) that:
- Covers all `SystemAccountPurpose` enum values (required for GL operations)
- Uses intuitive English names
- Follows a simple class structure: 1=Equity, 2=Assets, 3=Inventory, 4=Receivables/Payables, 5=Cash/Bank, 6=Expenses, 7=Revenue
- Marks system accounts correctly (`is_system`, `system_purpose`)

This is the **fallback** for any country without a dedicated seeder.

### Task 3: Generic Tax Rates — Known VAT Rates per Country
**Status:** Done
**Files:** `apps/api/database/seeders/CountryTaxRatesSeeder.php`

Expand the seeder to include standard VAT rates for common countries:
- EU countries (well-documented public rates)
- MENA countries (known rates)
- African countries (known rates)
- Countries with no VAT (US states vary — seed 0% with note)

Minimum per country: one standard rate + one zero/exempt rate. Users can add more via settings.

### Task 4: Update TenantInitializationService
**Status:** Done
**Files:** `apps/api/app/Modules/Identity/Application/Services/TenantInitializationService.php`

Change the chart of accounts fallback:
```
TN → TunisiaChartOfAccountsSeeder
FR → FranceChartOfAccountsSeeder
*  → GenericChartOfAccountsSeeder (instead of France!)
```

### Task 5: Remove Hardcoded Defaults from AuthController
**Status:** Done
**Files:** `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php`

Replace the `getDefaultCurrency()`, `getDefaultTimezone()`, `getDefaultLocale()` match statements with lookups from the `countries` table. The data is already there — no need to duplicate it in PHP code.

```php
// Before: match (strtoupper($countryCode)) { 'FR' => 'EUR', ... }
// After:  Country::find($countryCode)?->currency_code ?? 'EUR'
```

### Task 6: Frontend — Use API Countries List
**Status:** Done
**Files:** `apps/web/src/features/auth/RegisterPage.tsx`, `apps/api/app/Models/Country.php`

- Frontend already fetches from `/countries` API — dropdown auto-populates with all active countries
- Added `flag` computed accessor on Country model (converts ISO code to flag emoji via regional indicator symbols)
- Expanded Country TypeScript interface with additional fields (currency, phone_prefix, tax_id_label, etc.)
- Native `<select>` handles 40+ countries fine (scrollable)

### Task 7: Verify End-to-End Registration for New Country
**Status:** Done

Test registering as a user from a country that uses the generic fallback (e.g., Germany or Morocco):
1. Country appears in dropdown with correct flag/name
2. Registration completes without errors
3. Generic chart of accounts is seeded (not France's)
4. Correct tax rates are seeded
5. Currency, locale, timezone are correct
6. User can access dashboard and create an invoice
7. User can add/edit tax rates and chart of accounts from settings

---

## Data Sources for Tax Rates

| Region | Source |
|--------|--------|
| EU VAT rates | [EC VAT rates](https://taxation-customs.ec.europa.eu/vat-rates_en) — official, updated regularly |
| MENA | Country tax authority websites — rates are generally stable |
| Africa | Country-specific — many have VAT, some don't |
| US | No federal VAT; state sales tax varies — seed 0% with note |

---

## Future Work (Post-Launch)

- **Dedicated Chart of Accounts** for MENA countries (Arabic account names, local standards)
- **Dedicated Chart of Accounts** for African countries (OHADA SYSCOHADA for francophone Africa)
- **RTL support** for Arabic-locale countries
- **Country-specific compliance modules** as regulations require
- **Tax ID validation** — tighten regex patterns as we learn formats
- **Localized document templates** — invoice layouts per country requirements
