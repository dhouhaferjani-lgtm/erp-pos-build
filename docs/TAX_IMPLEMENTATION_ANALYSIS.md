# Tax Implementation Analysis Report
**Generated:** 2025-12-30
**Scope:** Complete analysis of tax configuration, application, and tracking across the AutoERP system

---

## Executive Summary

### Current Capabilities ✅
- **Modern tax architecture** with flexible configuration (percentage and fixed amounts)
- **Stamp duty system** fully implemented for Tunisia (and extensible to other countries)
- **Line-item tax calculation** with per-product tax rates
- **Document-level tax breakdown** with detailed UI display
- **Basic tax settings** UI for company-level defaults
- **Country-specific tax rates** seeded via `country_tax_rates` table
- **Media/Attachment system** for document linking

### Critical Gaps ❌
- **No party/customer tax status tracking** (VAT registration, exemptions)
- **No tax exemption handling** (cannot sell tax-free to exempt customers)
- **No recoverability tracking** on purchase documents (recoverable vs non-recoverable)
- **No tax configuration UI** (TaxConfiguration CRUD missing)
- **Limited product-level tax configuration** (only basic `tax_rate` field)
- **No category-level tax defaults** (field exists but not used in UI)
- **No historical tax rate changes** (cannot track rate changes over time)
- **No tax reporting** (no GL tax accounts, no tax reports)

---

## 1. Tax Configuration & Settings

### Database Tables

#### `tax_configurations` ⚙️
**Location:** `database/migrations/2025_12_30_100000_create_tax_configurations_table.php`

**Schema:**
```sql
- id (uuid, PK)
- country_code (char(2), FK → countries)
- tax_type (string) -- TaxType enum: PERCENTAGE, FIXED_AMOUNT
- name (string) -- Display name (e.g., "TVA 19%")
- code (string, nullable) -- Internal code
- percentage_rate (decimal 5,2, nullable) -- For percentage taxes
- fixed_amount (decimal 10,3, nullable) -- For fixed taxes
- applies_to (string) -- TaxApplicationLevel enum: LINE_ITEMS, DOCUMENT_TOTAL
- is_default (boolean)
- is_active (boolean)
- metadata (jsonb, nullable) -- Country-specific config
- timestamps
```

**Enums:**
- `TaxType`: `PERCENTAGE` | `FIXED_AMOUNT`
- `TaxApplicationLevel`: `LINE_ITEMS` | `DOCUMENT_TOTAL`

**Model:** `app/Modules/Taxation/Domain/Entities/TaxConfiguration.php`

**Methods:**
- `getTaxValue(): string` - Returns rate or amount based on type
- `isPercentage(): bool`
- `isFixedAmount(): bool`
- `appliesToLineItems(): bool`
- `appliesToDocumentTotal(): bool`

**Status:** ✅ Schema exists, ❌ No CRUD UI, ❌ Not actively used in calculations

---

#### `country_tax_rates` 🌍
**Location:** `database/migrations/2025_12_01_192545_create_country_tax_rates_table.php`

**Schema:**
```sql
- id (uuid, PK)
- country_code (char(2), FK → countries)
- name (string) -- e.g., "TVA Standard"
- rate (decimal 5,2) -- e.g., 19.00
- code (string, nullable)
- is_default (boolean)
- is_active (boolean)
- created_at
```

**Model:** `app/Models/CountryTaxRate.php`

**Seeder:** `database/seeders/CountryTaxRatesSeeder.php`

**Status:** ✅ Populated with France (20%, 10%, 5.5%) and Tunisia (19%, 13%, 7%)

---

#### `stamp_duty_rules` 🏛️
**Location:** `database/migrations/2025_12_30_101000_create_stamp_duty_rules_table.php`

**Schema:**
```sql
- id (uuid, PK)
- country_code (char(2), FK → countries)
- document_type (string) -- Maps to DocumentType enum
- fiscal_category (string, nullable) -- Maps to FiscalCategory enum
- stamp_amount (decimal 10,3) -- Fixed amount (e.g., 1.000 TND)
- is_active (boolean)
- effective_from (date) -- Historical tracking
- effective_to (date, nullable)
- metadata (jsonb, nullable)
- timestamps
```

**Model:** `app/Modules/Taxation/Domain/Entities/StampDutyRule.php`

**Tunisia Rules (seeded):**
- TAX_INVOICE: 1.000 TND
- FISCAL_RECEIPT: 0.100 TND

**Status:** ✅ Fully implemented and working

---

#### `document_tax_details` 📊
**Location:** `database/migrations/2025_12_30_102000_create_document_tax_details_table.php`

**Schema:**
```sql
- id (uuid, PK)
- document_id (uuid, FK → documents)
- tax_type (string) -- TaxType enum
- tax_name (string) -- Display name
- tax_base (decimal 15,2, nullable) -- Subtotal for percentage taxes
- tax_rate (decimal 5,2, nullable) -- Rate for percentage
- tax_amount (decimal 15,2) -- Calculated amount
- is_stamp_duty (boolean)
- timestamps
```

**Model:** `app/Modules/Taxation/Domain/Entities/DocumentTaxDetail.php`

**Status:** ✅ Schema exists, ⚠️ Not yet stored in database (calculated on-the-fly)

---

### Company-Level Tax Settings

**Table:** `companies`
**Migration:** `database/migrations/2025_12_30_103000_add_tax_fields_to_companies.php`

**Fields:**
```php
- default_tax_rate (decimal 5,2, nullable)
- default_tax_configuration_id (uuid, nullable, FK → tax_configurations)
```

**UI Component:** `apps/web/src/features/settings/TaxSettingsPage.tsx`
- **Route:** `/settings/tax`
- **Features:**
  - Set default tax rate (percentage)
  - Configure fiscal year start month
  - Company-specific configuration

**Status:** ✅ UI exists and functional, ⚠️ Tax configuration reference not used yet

---

### Category-Level Tax Settings

**Table:** `categories`
**Migration:** `database/migrations/2025_12_30_104000_add_tax_fields_to_categories.php`

**Fields:**
```php
- default_tax_rate (decimal 5,2, nullable)
- default_tax_configuration_id (uuid, nullable, FK → tax_configurations)
```

**Status:** ✅ Schema exists, ❌ Not exposed in UI, ❌ Not used in calculations

---

### Tax Configuration UI Gaps ❌

**Missing Components:**
1. **TaxConfiguration CRUD:**
   - No admin UI to create/edit tax configurations
   - Cannot manage country-specific tax rules
   - Cannot set up stacked taxes (e.g., GST + PST)

2. **Stamp Duty Rule Management:**
   - No UI to manage stamp duty rules
   - Changes require database seeder or manual SQL

3. **Tax Category Management:**
   - Category tax defaults exist in schema but no UI

4. **Historical Rate Tracking:**
   - No system to track tax rate changes over time
   - Cannot retroactively apply correct rate to historical documents

---

## 2. Tax Application on Transactions

### Sales Documents (Invoices, Receipts, etc.)

#### Tax Calculation Service
**Location:** `app/Modules/Taxation/Domain/Services/TaxCalculationService.php`

**Method:** `calculateDocumentTaxes(Document $document): DocumentTaxCalculationResult`

**Logic Flow:**
1. **Line-item taxes (percentage-based VAT):**
   ```php
   foreach ($document->lines as $line) {
       $lineSubtotal = quantity × unit_price
       $lineTax = lineSubtotal × (tax_rate / 100)
       $lineTaxAmount += $lineTax
   }
   ```

2. **Stamp duty (fixed amount):**
   - **Only applied to POSTED invoices** (not drafts, quotes, orders)
   - Only on fiscal documents (not NON_FISCAL)
   - Retrieved via `StampDutyService::calculateStampDuty()`

3. **Total calculation:**
   ```php
   total = (subtotal - discount) + line_tax + stamp_duty
   ```

**Tax Detail Structure:**
```php
class TaxDetail {
    public TaxType $taxType
    public string $taxName
    public ?string $taxBase
    public ?string $taxRate
    public string $taxAmount
    public bool $isStampDuty
}
```

**Status:** ✅ Line taxes working, ✅ Stamp duty working, ❌ No stacked taxes support

---

#### Document Model Tax Fields
**Location:** `app/Modules/Document/Domain/Document.php`

**Migration:** `database/migrations/2025_12_30_107000_add_stamp_duty_to_documents.php`

**Tax-Related Fields:**
```php
- tax_amount (decimal 15,2) -- Total tax (line_tax + stamp_duty)
- line_tax_amount (decimal 15,2) -- Percentage-based taxes only
- stamp_duty_amount (decimal 10,3) -- Fixed stamp duties
```

**Method:** `recalculateTotals(): void`
- Calls `TaxCalculationService::calculateDocumentTaxes()`
- Updates document totals automatically

**Status:** ✅ Fully functional

---

#### Document Line Tax Fields
**Location:** `app/Modules/Document/Domain/DocumentLine.php`

**Tax-Related Fields:**
```php
- tax_rate (decimal, nullable) -- Line-specific tax rate (e.g., 19.00 for 19%)
- unit_price (decimal) -- Pre-tax price
- line_total (decimal) -- Pre-tax total (quantity × unit_price)
```

**Tax Calculation:** Per-line, percentage-based only

**Status:** ✅ Working, ⚠️ Tax amount not stored per-line (calculated on demand)

---

### Purchase Documents (Expenses)

**Module:** `app/Modules/Expense/`

**Schema:** Expenses use the unified `documents` table with `type = 'expense'`

**Tax Handling:**
- ✅ Same `tax_amount`, `line_tax_amount` fields as sales documents
- ❌ **No stamp duty on purchases** (correct - stamp duty is sales-side only)
- ❌ **No recoverability tracking** (see Section 5 below)
- ❌ **No landed cost with non-recoverable tax** (tax not added to inventory cost)

**Status:** ⚠️ Basic tax recording exists, ❌ No advanced purchase tax features

---

### Tax Application Level: Line vs Document

**Current Implementation:** **LINE_ITEMS only**
- All taxes calculated per line item
- Stamp duty applied at document level (but not configurable)

**`applies_to` field exists in `tax_configurations` table:**
- `LINE_ITEMS` - Apply to each line (current implementation)
- `DOCUMENT_TOTAL` - Apply to final subtotal (not implemented)

**Gap:** No support for document-level taxes (e.g., Canada HST on final total)

---

## 3. Party/Customer Tax Status

### Partner Model Tax Fields
**Location:** `app/Modules/Partner/Domain/Partner.php`

**Existing Fields:**
```php
- vat_number (string, nullable) -- VAT/Tax ID
- country_code (string, nullable)
```

**Missing Fields:** ❌
- `vat_registration_status` (enum: registered, exempt, non_registered)
- `tax_exempt` (boolean)
- `tax_exemption_reason` (string)
- `tax_exemption_certificate_number` (string)
- `tax_exemption_valid_until` (date)

**Impact:**
- ❌ Cannot mark customers as tax-exempt
- ❌ Cannot automatically exclude tax for exempt organizations
- ❌ No validation of VAT numbers
- ❌ Cannot track exemption certificates

**Recommendation:** Add migration to extend `partners` table:

```sql
ALTER TABLE partners ADD COLUMN tax_exempt BOOLEAN DEFAULT false;
ALTER TABLE partners ADD COLUMN tax_exemption_reason VARCHAR(255);
ALTER TABLE partners ADD COLUMN tax_exemption_certificate_number VARCHAR(100);
ALTER TABLE partners ADD COLUMN tax_exemption_valid_until DATE;
```

---

### Tax-Free Sales

**Current Capability:** ❌ **Not supported**

**How it should work:**
1. Check `partner.tax_exempt` before applying line taxes
2. If exempt, set `tax_rate = 0` on all lines
3. Store exemption justification in `document.notes` or new field
4. Link exemption certificate via Media module

**Gap:** Tax calculation service has no partner exemption logic

---

## 4. Document Types

### Defined Document Types
**Location:** `app/Modules/Document/Domain/Enums/DocumentType.php`

**Types:**
```php
- Quote
- SalesOrder
- Invoice
- CreditNote
- DeliveryNote
- ReturnNote
- PurchaseOrder
- GoodsReceipt
- Expense (implied, uses unified documents table)
```

**Status:** ✅ All types use same `documents` table (polymorphic design)

---

### Fiscal Categories
**Location:** `app/Modules/Document/Domain/Enums/FiscalCategory.php`

**Categories:**
```php
- NON_FISCAL -- Non-taxable (quotes, orders)
- FISCAL_RECEIPT -- Cash register receipt (requires NF525 for France)
- TAX_INVOICE -- B2B/B2C invoice (subject to VAT and stamp duty)
- CREDIT_NOTE -- Reversal document
- DELIVERY_NOTE -- Shipping document (DDT in Italy)
- RETURN_NOTE -- Customer returns
```

**Tax Relationship:**
- `TAX_INVOICE` → Subject to stamp duty (if country has rules)
- `FISCAL_RECEIPT` → Subject to different stamp duty rate (Tunisia: 0.100 TND)
- `NON_FISCAL` → No stamp duty, but line taxes may apply

**Status:** ✅ Fully implemented and enforced via database constraints

---

### Document Type → Tax Mapping

**Current Logic:**
```php
// Stamp duty only applies to:
- status = POSTED
- type = Invoice
- fiscal_category != NON_FISCAL
```

**Extensibility:** ✅ Can add new document types without changing tax logic

---

## 5. Recoverability Tracking

### Current Status: ❌ **NOT IMPLEMENTED**

**What's Missing:**
1. **Recoverable/Non-Recoverable Flag:**
   - Purchase documents don't track whether VAT can be reclaimed
   - No field: `is_tax_recoverable` or `recoverable_tax_amount`

2. **Impact on Inventory Cost:**
   - Non-recoverable tax should be added to `landed_unit_cost`
   - Current logic doesn't factor in non-recoverable taxes
   - **Weighted average cost calculation is incomplete**

3. **GL Posting Impact:**
   - Recoverable tax → Debit "VAT Receivable" (asset account)
   - Non-recoverable tax → Debit "Inventory" or "Expense" (increase cost)
   - Currently no logic to distinguish

---

### Recommended Implementation

#### Schema Changes
```sql
ALTER TABLE document_lines ADD COLUMN tax_recoverable BOOLEAN DEFAULT true;
ALTER TABLE document_lines ADD COLUMN tax_amount DECIMAL(15,2); -- Store calculated tax
ALTER TABLE document_lines ADD COLUMN recoverable_tax_amount DECIMAL(15,2);
ALTER TABLE document_lines ADD COLUMN non_recoverable_tax_amount DECIMAL(15,2);
```

#### Landed Cost Calculation
```php
// In StockService or similar
$landedUnitCost = $unitPrice
    + ($allocatedShippingCost / $quantity)
    + ($nonRecoverableTax / $quantity);
```

#### Use Cases:
- **France:** VAT fully recoverable for registered businesses
- **Tunisia:** Partially recoverable (need configuration per expense category)
- **Non-registered businesses:** No tax recovery (all tax becomes cost)

---

## 6. Audit Trail & Justification

### Media/Attachment System ✅

**Module:** `app/Modules/Media/`

**Service:** `app/Modules/Media/Application/Services/AttachmentService.php`

**Capabilities:**
- ✅ Upload files (PDFs, images, etc.)
- ✅ Link files to documents (invoices, expenses)
- ✅ Link files to partners (customers, suppliers)
- ✅ Metadata storage (filename, mime_type, size)
- ✅ Secure file storage

**Status:** ✅ Fully implemented and ready for use

---

### Tax Exemption Certificate Linking

**Recommended Usage:**
1. Upload exemption certificate via `AttachmentService`
2. Store attachment reference in `partners` table:
   ```php
   - tax_exemption_attachment_id (uuid, FK → attachments)
   ```
3. Validate exemption when creating tax-free documents

---

### Audit Trail (Events)

**Event Sourcing:** ✅ System has event-based architecture

**Tax-Related Events (potential):**
- `TaxRateChanged` (when company/product tax rate updates)
- `TaxExemptionGranted` (when partner marked exempt)
- `StampDutyApplied` (when stamp duty calculated on posting)

**Status:** ⚠️ Tax-specific events not yet implemented

---

## 7. Database Schema Summary

### Tax Configuration Tables
| Table | Purpose | Status |
|-------|---------|--------|
| `tax_configurations` | Flexible tax type definitions | ✅ Schema, ❌ No CRUD |
| `country_tax_rates` | Country-level rate presets | ✅ Seeded |
| `stamp_duty_rules` | Fixed-amount document taxes | ✅ Working |
| `document_tax_details` | Breakdown per document | ⚠️ Calculated, not stored |

### Document Tables (Tax-Relevant)
| Table | Tax Fields | Status |
|-------|------------|--------|
| `documents` | `tax_amount`, `line_tax_amount`, `stamp_duty_amount` | ✅ Working |
| `document_lines` | `tax_rate`, `unit_price`, `line_total` | ✅ Working |

### Party Tables (Tax-Relevant)
| Table | Tax Fields | Status |
|-------|------------|--------|
| `partners` | `vat_number`, `country_code` | ⚠️ Incomplete |
| **Missing:** | `tax_exempt`, `exemption_reason`, `exemption_certificate` | ❌ Not implemented |

### Product Tables (Tax-Relevant)
| Table | Tax Fields | Status |
|-------|------------|--------|
| `products` | `tax_rate` | ✅ Working |
| `categories` | `default_tax_rate`, `default_tax_configuration_id` | ⚠️ Schema only, not used |

### Company Tables (Tax-Relevant)
| Table | Tax Fields | Status |
|-------|------------|--------|
| `companies` | `default_tax_rate`, `default_tax_configuration_id` | ✅ UI exists |

---

## 8. Key Files Reference

### Backend (Laravel)

#### Models
```
app/Modules/Taxation/Domain/Entities/TaxConfiguration.php
app/Modules/Taxation/Domain/Entities/StampDutyRule.php
app/Modules/Taxation/Domain/Entities/DocumentTaxDetail.php
app/Models/CountryTaxRate.php
app/Modules/Document/Domain/Document.php (lines 41-44: tax fields)
app/Modules/Document/Domain/DocumentLine.php (line 29: tax_rate)
app/Modules/Partner/Domain/Partner.php (line 31: vat_number)
app/Modules/Product/Domain/Product.php (line 28: tax_rate)
```

#### Services
```
app/Modules/Taxation/Domain/Services/TaxCalculationService.php ⭐ CORE
app/Modules/Taxation/Domain/Services/StampDutyService.php
app/Modules/Taxation/Domain/Services/TaxResolutionService.php
```

#### Controllers
```
app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php
app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php
```

#### Tests
```
tests/Feature/Taxation/TaxCalculationTest.php
tests/Feature/Taxation/TaxBreakdownEndpointTest.php
```

#### Migrations
```
database/migrations/2025_12_01_192545_create_country_tax_rates_table.php
database/migrations/2025_12_30_100000_create_tax_configurations_table.php
database/migrations/2025_12_30_101000_create_stamp_duty_rules_table.php
database/migrations/2025_12_30_102000_create_document_tax_details_table.php
database/migrations/2025_12_30_103000_add_tax_fields_to_companies.php
database/migrations/2025_12_30_104000_add_tax_fields_to_categories.php
database/migrations/2025_12_30_107000_add_stamp_duty_to_documents.php
```

---

### Frontend (React/TypeScript)

#### Components
```
apps/web/src/features/documents/components/TaxBreakdownPanel.tsx ⭐
apps/web/src/features/settings/TaxSettingsPage.tsx ⭐
```

#### API Clients
```
apps/web/src/features/documents/api/taxApi.ts
```

#### Types
```
apps/web/src/features/documents/api/taxApi.ts:
  - TaxBreakdown
  - TaxDetail
```

#### Translations
```
apps/web/src/locales/en/sales.json (tax.breakdown.*)
apps/web/src/locales/fr/sales.json (tax.breakdown.*)
apps/web/src/locales/en/settings.json (tax.*)
apps/web/src/locales/fr/settings.json (tax.*)
```

---

## 9. TODO Comments & Incomplete Work

### Found in Codebase

#### TaxConfiguration CRUD
- ❌ **No admin UI** to manage tax configurations
- Controller exists but no routes/frontend

#### StampDuty CRUD
- ❌ **No admin UI** to manage stamp duty rules
- Controller exists but no routes/frontend

#### DocumentTaxDetail Storage
- ⚠️ **Not persisted to database** - only calculated on-the-fly
- Schema exists but never populated
- **Reason:** Current approach is stateless calculation

#### Partner Tax Exemptions
- ❌ **No fields for tax exemptions**
- ❌ **No UI to mark customers tax-exempt**
- ❌ **No validation in tax calculation**

#### Recoverability
- ❌ **No purchase tax recoverability tracking**
- ❌ **No landed cost calculation with non-recoverable tax**

---

## 10. Gaps vs Requirements

### ✅ What Works Well
1. **Flexible tax architecture** - percentage and fixed amounts supported
2. **Stamp duty** - Tunisia rules working perfectly
3. **Line-item taxes** - percentage-based VAT calculated correctly
4. **Country-specific rates** - France and Tunisia seeded
5. **Tax breakdown UI** - beautiful React component showing all details
6. **Media/Attachment** - can link documents for audit trail

### ❌ Critical Gaps
1. **No tax exemption handling** (biggest issue for B2B/B2G sales)
2. **No recoverability tracking** (affects inventory costing)
3. **No tax configuration UI** (admin must use seeders/SQL)
4. **No category tax defaults in UI** (field exists but unused)
5. **No historical rate tracking** (can't change rates without breaking old docs)
6. **No stacked taxes** (can't do GST+PST, federal+provincial)

### ⚠️ Moderate Gaps
1. **Document tax details not stored** (recalculated each time - performance concern?)
2. **No document-level taxes** (only line-level)
3. **No tax reporting** (no dedicated GL accounts for taxes)
4. **No VAT validation** (VIES check for EU VAT numbers)

---

## 11. Recommendations (Priority Order)

### P0 - Critical (Do First)
1. **Add partner tax exemption fields** + UI
   - Allow marking customers as tax-exempt
   - Update `TaxCalculationService` to skip taxes for exempt partners
   - Add exemption certificate upload via Media module

2. **Implement purchase tax recoverability**
   - Add `tax_recoverable`, `recoverable_tax_amount`, `non_recoverable_tax_amount` to `document_lines`
   - Update landed cost calculation to include non-recoverable taxes
   - Create UI toggle for "Tax Recoverable" on purchase documents

### P1 - High Priority
3. **Build Tax Configuration CRUD UI**
   - Admin panel to manage `tax_configurations`
   - Country-specific tax rules
   - Historical rate tracking (effective_from/to dates)

4. **Add Stamp Duty Rules Management UI**
   - CRUD for `stamp_duty_rules`
   - Preview which documents will be affected

5. **Persist `document_tax_details` to database**
   - Store breakdown for audit trail
   - Improve performance (no recalculation on every page load)

### P2 - Medium Priority
6. **Implement category tax defaults in UI**
   - Use `categories.default_tax_rate` when creating products
   - UI for setting category-level tax rules

7. **Add stacked tax support**
   - Multiple taxes per line item (GST + PST)
   - Compound taxes (tax-on-tax)

8. **Tax reporting**
   - VAT collected report
   - VAT paid report (on purchases)
   - Net VAT due calculation

### P3 - Nice to Have
9. **VAT number validation**
   - VIES API integration for EU
   - Country-specific validation regex

10. **Historical tax rate changes**
    - Track rate changes with effective dates
    - Auto-apply correct historical rate to old documents

---

## 12. Testing Coverage

### Existing Tests ✅
```
tests/Feature/Taxation/TaxCalculationTest.php
tests/Feature/Taxation/TaxBreakdownEndpointTest.php
```

### Missing Tests ❌
- Partner tax exemption scenarios
- Recoverable vs non-recoverable tax
- Stacked taxes
- Historical rate changes
- Tax configuration CRUD endpoints

---

## Appendix: Example Tax Scenarios

### Scenario 1: Standard B2C Sale (France)
```
Product: Brake Caliper
Price: 100.00 EUR
Tax Rate: 20% (VAT)
Customer: Regular (not exempt)

Calculation:
- Subtotal: 100.00
- VAT (20%): 20.00
- Stamp Duty: 0.00 (France has no stamp duty)
- Total: 120.00
```

### Scenario 2: B2B Invoice (Tunisia)
```
Product: Oil Filter
Price: 50.00 TND
Tax Rate: 19% (VAT)
Customer: Registered business (not exempt)
Document Type: TAX_INVOICE

Calculation:
- Subtotal: 50.00
- VAT (19%): 9.50
- Stamp Duty: 1.000 (Tunisia TAX_INVOICE)
- Total: 60.50
```

### Scenario 3: Tax-Exempt Sale (SHOULD WORK - Currently ❌)
```
Product: Medical Equipment
Price: 1000.00 EUR
Tax Rate: 20% (standard)
Customer: Hospital (tax-exempt, certificate #MED-2025-001)

Expected Calculation:
- Subtotal: 1000.00
- VAT (20%): 0.00 (EXEMPT)
- Stamp Duty: 0.00
- Total: 1000.00
- Note: "Tax exempt - Certificate MED-2025-001"

Current Behavior: ❌ Charges 200.00 VAT (doesn't check exemption)
```

### Scenario 4: Purchase with Non-Recoverable Tax (SHOULD WORK - Currently ❌)
```
Supplier: Restaurant supplier
Product: Coffee machine
Price: 500.00 EUR
Tax Rate: 20% (VAT)
Business: Non-registered (startup)

Expected Calculation:
- Purchase Price: 500.00
- VAT (20%): 100.00 (NON-RECOVERABLE)
- Landed Unit Cost: 600.00 (price + non-recoverable tax)
- Total Paid: 600.00

Current Behavior: ❌ Records tax but doesn't add to inventory cost
```

---

**End of Report**
