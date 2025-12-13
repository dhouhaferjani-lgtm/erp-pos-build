# Hardcoded Data Audit

> Mock data and values that should be dynamic

---

## Executive Summary

Found **3 critical issues** and **12 configuration concerns** across backend and frontend. Most relate to currency defaults and margin inconsistencies.

---

## 1. Critical Issues (Must Fix)

### 1.1 Frontend Currency Hardcoding

**Severity**: HIGH
**Impact**: Multi-country deployments will show wrong currency

**Default in format.ts**:
```typescript
// apps/web/src/lib/format.ts:28
currency = 'USD'  // Should be from company context
locale = 'en-US'
```

**Components defaulting to TND**:

| File | Line | Component |
|------|------|-----------|
| ProductPricingCard.tsx | 28 | currency prop |
| LandedCostBreakdown.tsx | 23 | currency prop |
| AdditionalCostsForm.tsx | 31 | currency prop |
| DocumentForm.tsx | 416 | currency field |
| DocumentDetailPage.tsx | 837 | currency display |
| PurchaseOrderAdditionalCosts.tsx | 23 | currency prop |
| PurchaseOrderLandedCostBreakdown.tsx | 19 | currency prop |
| PriceInputWithMargin.tsx | 47 | currency prop |
| GoodsReceiptListPage.tsx | 134-135 | currency logic |

**Fix**: Accept currency from company context, not hardcoded default.

---

### 1.2 Margin Default Inconsistency

**Severity**: HIGH
**Impact**: Different defaults applied inconsistently

**CompanyFactory.php** (line 71-72):
```php
'default_target_margin' => 30.0
'default_minimum_margin' => 15.0
```

**MarginService.php** (line 38-42):
```php
$targetMargin = $product->target_margin_override
    ?? $company->default_target_margin ?? 30.0;  // OK

$minimumMargin = $product->minimum_margin_override
    ?? $company->default_minimum_margin ?? 10.0;  // WRONG: Should be 15.0
```

**Fix**: Change 10.0 to 15.0 in MarginService.php

---

### 1.3 Payment Methods Not Country-Aware

**Severity**: MEDIUM
**Impact**: Same payment methods seeded for all countries

**PaymentMethodSeeder.php** (lines 21-22):
```php
$tenant = Tenant::first();  // Hardcoded to first tenant
$company = Company::first();
```

**Fix**: Parameterize by country and seed appropriate methods.

---

## 2. Database Seeders (Demo Data)

### 2.1 Demo Tenant/Company

**File**: `DatabaseSeeder.php`

| Item | Value | Line |
|------|-------|------|
| Tenant name | 'Demo Garage' | 77 |
| Tenant slug | 'demo-garage' | 78 |
| Country | 'FR' | 82 |
| Currency | 'EUR' | 83 |
| Company name | 'Demo Garage' | 100 |
| Legal name | 'Demo Garage SARL' | 100 |
| Tax ID | 'FR12345678901' | 102 |

**Status**: Appropriate for development seeding

### 2.2 Test Users

**File**: `DatabaseSeeder.php`

| User | Email | Password | Line |
|------|-------|----------|------|
| Test | test@example.com | password | 123-124 |
| Admin | admin@example.com | admin123 | 152-153 |

**Status**: Must NOT be in production seeds

### 2.3 Payment Repositories (Demo Bank Accounts)

**File**: `PaymentRepositorySeeder.php`

| Code | Name | Balance | Details |
|------|------|---------|---------|
| CASH-01 | Main Cash Register | 500.00 | - |
| CASH-02 | Workshop Cash Register | 200.00 | - |
| SAFE-01 | Office Safe | 5,000.00 | - |
| BANK-01 | BNP Paribas | 25,000.00 | Fake IBAN |
| BANK-02 | Crédit Agricole | 15,000.00 | Fake IBAN |
| BANK-03 | Société Générale | 10,000.00 | Fake IBAN |
| VIRT-01 | PayPal Business | 3,500.00 | business@example.com |

**Status**: Appropriate for demo, never for production

---

## 3. Tax Rates

**File**: `CountryTaxRatesSeeder.php`

### Tunisia (TN)
| Name | Rate | Code |
|------|------|------|
| TVA 19% | 19.00 | 24 |
| TVA 13% | 13.00 | 33 |
| TVA 7% | 7.00 | 42 |
| Exonéré | 0.00 | 51 |

### France (FR)
| Name | Rate | Code |
|------|------|------|
| TVA 20% | 20.00 | 61 |
| TVA 10% | 10.00 | 70 |
| TVA 5.5% | 5.50 | 79 |
| TVA 2.1% | 2.10 | 88 |

**Status**: Correctly seeded per country

---

## 4. Factory Defaults

### CompanyFactory.php

| Field | Default | Line |
|-------|---------|------|
| Country | 'FR' | 38 |
| Currency | 'EUR' | 49 |
| Locale | 'fr_FR' | 50 |
| Timezone | 'Europe/Paris' | 51 |
| Primary color | '#2563eb' | 48 |
| Inventory method | 'weighted_average' | 70 |
| Target margin | 30.00 | 71 |
| Minimum margin | 15.00 | 72 |
| Invoice prefix | 'INV-' | 54 |
| Quote prefix | 'QUO-' | 56 |
| SO prefix | 'SO-' | 58 |
| PO prefix | 'PO-' | 60 |
| DN prefix | 'DN-' | 62 |

**Tunisia override** (method `tunisia()`):
- Country: 'TN'
- Currency: 'TND'
- Locale: 'fr_TN'
- Timezone: 'Africa/Tunis'

**Status**: Appropriate for factories

---

## 5. Test Data

### Hash Chain Reference (Legitimate)

**File**: `tests/Fixtures/HashChainReference.php`

Hardcoded SHA-256 test vectors for compliance testing:
```php
'6a523adf3b63c08ade1c582f242aedebcea0aeed07b4a8e2b02d1e9e0eedd24b'
```

**Status**: CORRECT - These are ground truth vectors. Do not change.

### Test Passwords

| Password | Usage | Files |
|----------|-------|-------|
| 'password' | Standard test | 50+ files |
| 'password123' | Alternative | Multiple |
| 'admin123' | Admin tests | Seeders |
| 'superadmin123' | Super admin | SuperAdminSeeder |

**Status**: Appropriate for tests, but should use constants

---

## 6. Frontend Constants

### countries.ts

**File**: `apps/web/src/lib/countries.ts`

Hardcoded supported countries:
- North Africa: TN, DZ, MA, LY, EG
- Europe: FR, IT, DE, ES, GB, BE, NL, CH, PT, AT, PL
- Gulf: SA, AE, QA, KW, BH, OM
- Other: TR, US, CA

**Status**: Appropriate - defines supported markets

### Format Helpers

```typescript
// Hardcoded locale functions
formatTND()  // Currency 'TND', locale 'fr-TN'
formatEUR()  // Currency 'EUR', locale 'fr-FR'
```

**Status**: These are convenience functions, acceptable

---

## 7. E2E Test Fixtures

**File**: `apps/web/e2e/fixtures.ts`

| Item | Value |
|------|-------|
| Company currency | 'TND' |
| Test user email | 'test@example.com' |

**Status**: Appropriate for E2E testing

---

## 8. Recommendations

### Must Fix (Before Production)

| Issue | File | Fix |
|-------|------|-----|
| Currency defaults to USD | format.ts:28 | Use company context |
| 8 components hardcode TND | Multiple | Accept prop from context |
| Margin default mismatch | MarginService.php:42 | Change 10.0 to 15.0 |
| Payment methods not parameterized | PaymentMethodSeeder.php:21 | Add country param |

### Should Review

| Issue | File | Action |
|-------|------|--------|
| Test passwords scattered | 50+ files | Create test helper |
| Only one costing method | CompanyFactory:70 | Document limitation |
| Demo users in seeder | DatabaseSeeder.php | Separate demo seeder |

### Leave As-Is

| Item | Reason |
|------|--------|
| Hash chain test vectors | Compliance ground truth |
| Countries list | Defines supported markets |
| Tax rates per country | Correctly parameterized |
| Factory defaults | Standard for tests |

---

## 9. Production Checklist

- [ ] Remove test user accounts from production seeds
- [ ] Verify currency comes from company context
- [ ] Fix margin default inconsistency
- [ ] Create separate demo data seeder
- [ ] Verify payment methods per country
- [ ] Clear fake bank account data

---

**File**: `docs/live-readiness/03-HARDCODED-DATA-AUDIT.md`
**Generated**: 2025-12-13
