# Country Adaptation System

> **Purpose:** Reference documentation for implementing country-specific business rules, tax rates, compliance requirements, and accounting standards.

---

## Overview

AutoERP supports multi-country operations through a layered adaptation system. Each country can have specific:

- Tax rates and VAT configurations
- Payment method availability and settings
- Chart of accounts (localized GL structure)
- Compliance profiles (NF525, ZATCA, GoBD, etc.)
- Document flow rules (e.g., DN required before invoice)
- Numbering formats and sequences

---

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Country Model                          │
│  - code (ISO 3166-1 alpha-2: TN, FR, IT, SA)               │
│  - name, currency, locale                                   │
│  - vat_enabled, default settings                            │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   CountryTaxRate                            │
│  - VAT rates per country (standard, reduced, zero, exempt)  │
│  - Rate percentages and applicability rules                 │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                CountryPaymentSettings                       │
│  - Payment tolerance percentages                            │
│  - FX gain/loss accounts                                    │
│  - Rounding rules                                           │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                     Company Model                           │
│  - country_code → inherits country rules                    │
│  - compliance_profile (optional override)                   │
│  - fiscal_chain_seed (for hash chain)                       │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│              Chart of Accounts Seeder                       │
│  - TunisiaChartOfAccountsSeeder (PCN Tunisien)             │
│  - FranceChartOfAccountsSeeder (PCG Français)              │
│  - Per-country GL structure                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Key Models

### Country (`app/Models/Country.php`)

The base country configuration model.

```php
// Key fields
$country->code;           // 'TN', 'FR', 'IT', 'SA'
$country->name;           // 'Tunisia', 'France', etc.
$country->currency_code;  // 'TND', 'EUR', 'SAR'
$country->locale;         // 'fr_TN', 'fr_FR', 'ar_SA'
$country->vat_enabled;    // boolean
```

### CountryTaxRate (`app/Models/CountryTaxRate.php`)

VAT/tax rates for each country.

```php
// Example: Tunisia VAT rates
CountryTaxRate::where('country_code', 'TN')->get();

// Returns:
// - Standard: 19%
// - Reduced: 13%
// - Super-reduced: 7%
// - Zero: 0%
```

### CountryPaymentSettings (`app/Modules/Treasury/Domain/CountryPaymentSettings.php`)

Payment-related settings per country.

```php
// Key settings
$settings->tolerance_percentage;    // 0.5% for minor differences
$settings->fx_gain_account_id;      // GL account for FX gains
$settings->fx_loss_account_id;      // GL account for FX losses
$settings->rounding_method;         // 'nearest', 'up', 'down'
```

---

## Currently Configured Countries

### Tunisia (TN)

| Setting | Value |
|---------|-------|
| Currency | TND (Tunisian Dinar) |
| VAT Rates | 19%, 13%, 7%, 0% |
| Chart of Accounts | PCN Tunisien (Plan Comptable Normalisé) |
| Compliance | Basic hash chain for invoices |
| DN Requirements | Sequential, tamper-proof, fiscal year matching |
| Payment Tolerance | 0.5% |

**Special Accounting Rules:**
- Account 418: Clients - Produits non encore facturés (uninvoiced sales DNs)
- Account 408: Fournisseurs - Factures non parvenues (uninvoiced purchase DNs)

### France (FR)

| Setting | Value |
|---------|-------|
| Currency | EUR |
| VAT Rates | 20%, 10%, 5.5%, 2.1%, 0% |
| Chart of Accounts | PCG (Plan Comptable Général) - Not yet seeded |
| Compliance | NF525 for POS (future), Factur-X for e-invoicing |
| Payment Tolerance | 0.5% |

**Future Requirements:**
- NF525 certification for cash register software
- Factur-X e-invoicing (EN 16931)
- PDP submission for B2B invoices

---

## Adding a New Country

### Step 1: Add Country Record

```php
// database/seeders/CountriesSeeder.php
Country::create([
    'code' => 'IT',
    'name' => 'Italy',
    'currency_code' => 'EUR',
    'locale' => 'it_IT',
    'vat_enabled' => true,
]);
```

### Step 2: Add Tax Rates

```php
// database/seeders/CountryTaxRatesSeeder.php
CountryTaxRate::create([
    'country_code' => 'IT',
    'name' => 'Standard',
    'rate' => 22.00,
    'is_default' => true,
]);

CountryTaxRate::create([
    'country_code' => 'IT',
    'name' => 'Reduced',
    'rate' => 10.00,
]);
```

### Step 3: Add Payment Settings

```php
// database/seeders/CountryPaymentSettingsSeeder.php
CountryPaymentSettings::create([
    'country_code' => 'IT',
    'tolerance_percentage' => 0.5,
    'rounding_method' => 'nearest',
]);
```

### Step 4: Create Chart of Accounts Seeder (Optional)

```php
// database/seeders/ItalyChartOfAccountsSeeder.php
class ItalyChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Italian chart of accounts structure
    }
}
```

### Step 5: Add Document Flow Rules (If Different)

For countries with specific document flow requirements:

```php
// Future: country_document_rules table or config
[
    'country_code' => 'IT',
    'rule_type' => 'flow_transition',
    'rule_key' => 'dn_required_before_invoice',
    'rule_value' => true,  // Italy requires DDT before invoice
]
```

---

## Compliance Profiles

Each country may have specific compliance requirements. The `compliance_profile` field on Company allows overriding defaults.

### Available Profiles

| Profile | Description | Countries |
|---------|-------------|-----------|
| `standard` | Basic hash chain, no special requirements | Default |
| `tunisian` | PCN accounting, DN hash chain | Tunisia |
| `nf525` | French POS certification (future) | France |
| `zatca` | Saudi e-invoicing (future) | Saudi Arabia |
| `italian_ddt` | DDT delivery note requirements | Italy |
| `gobd_tse` | German TSE requirements (future) | Germany |

### Using Compliance Profiles

```php
// Check company's compliance profile
$company = Company::find($id);
$profile = $company->compliance_profile; // ComplianceProfile enum

// Validate document flow based on profile
if ($profile === ComplianceProfile::ItalianDDT) {
    // Enforce DDT before invoice rule
}
```

---

## Document Flow Rules by Country

### Tunisia

```
Quote → Sales Order → Delivery Note → Invoice → Payment
                          ↓
                    (Multiple DNs can be consolidated into one Invoice)
```

**Rules:**
- DNs must be sequentially numbered
- DNs must be in hash chain (tamper-proof)
- All DNs must have matching invoice by fiscal year end
- Uninvoiced DNs use account 418/408 for year-end adjustment

### Italy

```
Quote → Sales Order → DDT (Delivery Note) → Invoice → Payment
```

**Rules:**
- DDT (Documento di Trasporto) required before invoice
- DDT must include transport details
- Invoice references DDT number(s)

### France (B2B)

```
Quote → Sales Order → Delivery Note (optional) → Invoice → Payment
```

**Rules:**
- Factur-X e-invoice for B2B (future requirement)
- No DDT requirement for services
- NF525 for retail POS only

---

## Tax Calculation

### Getting Applicable Tax Rates

```php
use App\Models\CountryTaxRate;

// Get all rates for a country
$rates = CountryTaxRate::forCountry('TN')->get();

// Get default rate
$defaultRate = CountryTaxRate::forCountry('TN')
    ->where('is_default', true)
    ->first();

// Calculate tax
$taxAmount = $subtotal * ($rate->rate / 100);
```

### Tax Display Format

Different countries display tax differently:
- Tunisia: "TVA 19%"
- France: "TVA 20%"
- UK: "VAT 20%"
- Saudi: "VAT 15%"

Use translation keys for tax labels:
```typescript
t('tax.label', { rate: 19 }) // "TVA 19%" in French, "VAT 19%" in English
```

---

## Currency and Localization

### Number Formatting

```php
// Use country locale for formatting
$formatted = Number::currency($amount, $country->currency_code, $country->locale);

// Examples:
// Tunisia: 1 234,567 TND
// France:  1 234,57 €
// UK:      £1,234.57
```

### Date Formatting

```php
// Use country locale for dates
$formatted = Carbon::parse($date)->locale($country->locale)->isoFormat('L');

// Examples:
// Tunisia: 11/12/2025
// France:  11/12/2025
// US:      12/11/2025
```

---

## Future Enhancements

### Country Document Rules Table

```sql
CREATE TABLE country_document_rules (
    id BIGSERIAL PRIMARY KEY,
    country_code CHAR(2) NOT NULL,
    rule_type VARCHAR(50) NOT NULL,    -- 'flow_transition', 'required_field', 'numbering'
    rule_key VARCHAR(100) NOT NULL,    -- 'dn_required_before_invoice', 'ddt_fields'
    rule_value JSONB NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### E-Invoicing Integration

| Country | Standard | Status |
|---------|----------|--------|
| France | Factur-X (EN 16931) | Planned |
| Saudi Arabia | ZATCA | Planned |
| Italy | FatturaPA | Future |

---

## Testing Country Adaptation

### Unit Tests

```php
// Test tax rate retrieval
public function test_can_get_tunisia_vat_rates(): void
{
    $rates = CountryTaxRate::forCountry('TN')->get();

    $this->assertCount(4, $rates);
    $this->assertEquals(19.00, $rates->firstWhere('is_default', true)->rate);
}

// Test document flow rules
public function test_italy_requires_ddt_before_invoice(): void
{
    $company = Company::factory()->create(['country_code' => 'IT']);
    $order = SalesOrder::factory()->for($company)->confirmed()->create();

    $this->expectException(ComplianceException::class);
    $this->conversionService->convertOrderToInvoice($order);
}
```

### Integration Tests

```php
// Test full flow with country rules
public function test_tunisia_dn_consolidation_flow(): void
{
    $company = Company::factory()->create(['country_code' => 'TN']);
    $order = SalesOrder::factory()->for($company)->confirmed()->create();

    // Create two partial delivery notes
    $dn1 = $this->conversionService->convertOrderToDelivery($order, partialLines: [...]);
    $dn2 = $this->conversionService->convertOrderToDelivery($order, partialLines: [...]);

    // Consolidate into one invoice
    $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);

    $this->assertEquals($order->total, $invoice->total);
}
```

---

## Reference Files

| File | Purpose |
|------|---------|
| `app/Models/Country.php` | Country base model |
| `app/Models/CountryTaxRate.php` | Tax rates per country |
| `app/Modules/Treasury/Domain/CountryPaymentSettings.php` | Payment settings |
| `database/seeders/CountriesSeeder.php` | Country data seeder |
| `database/seeders/TunisiaChartOfAccountsSeeder.php` | Tunisia GL structure |

---

*Last Updated: 2025-12-11*
