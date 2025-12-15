# AutoERP Codebase Audit Report

**Date:** 2025-12-08
**Audited by:** Claude Code (Opus 4.5)
**Purpose:** Establish accurate baseline before Smart Payment implementation

---

## Executive Summary

The AutoERP codebase follows Hexagonal Architecture with 12 modules. Key finding: **CountryAdaptation module does NOT exist** - Country is a global model. The spec documents reference incorrect namespaces and non-existent modules. Controllers use `Presentation/Controllers/` pattern, not `Http/Controllers/`. The existing `CreditNote` document type should be used instead of adding a duplicate `CREDIT_MEMO` case.

---

## Module Inventory

| Module | Location | Status | Key Contents |
|--------|----------|--------|--------------|
| Accounting | `app/Modules/Accounting/` | ✓ Exists | Account, JournalEntry, JournalLine, GeneralLedgerService, ChartOfAccountsService, PartnerBalanceService, SystemAccountPurpose |
| Company | `app/Modules/Company/` | ✓ Exists | Company, Location, CompanyController |
| Compliance | `app/Modules/Compliance/` | ✓ Exists | FiscalHashService, AuditService, AnomalyDetectionService, BackfillFiscalHashesCommand |
| Document | `app/Modules/Document/` | ✓ Exists | Document, DocumentLine, DocumentAdditionalCost, DocumentSequence, DocumentType, DocumentPostingService, DocumentConversionService |
| Identity | `app/Modules/Identity/` | ✓ Exists | User, Role, Permission |
| Inventory | `app/Modules/Inventory/` | ✓ Exists | Stock, StockMovement, LandedCostService, WeightedAverageCostService, InventoryCounting |
| Partner | `app/Modules/Partner/` | ✓ Exists | Partner, PartnerController |
| Pricing | `app/Modules/Pricing/` | ✓ Exists | PricingService |
| Product | `app/Modules/Product/` | ✓ Exists | Product, MarginService |
| Tenant | `app/Modules/Tenant/` | ✓ Exists | Tenant, TenantSubscription |
| Treasury | `app/Modules/Treasury/` | ✓ Exists | Payment, PaymentAllocation, PaymentMethod, PaymentRepository, PaymentInstrument, MultiPaymentService, PaymentRefundService |
| Vehicle | `app/Modules/Vehicle/` | ✓ Exists | Vehicle |
| **CountryAdaptation** | - | ✗ **Does NOT exist** | - |

---

## Model Locations

| Model | Spec Document Says | Actual Location | Namespace |
|-------|-------------------|-----------------|-----------|
| Country | `Modules/CountryAdaptation/Domain/` | `app/Models/Country.php` | `App\Models` |
| CountryTaxRate | `Modules/CountryAdaptation/Domain/` | `app/Models/CountryTaxRate.php` | `App\Models` |
| Company | `Modules/Accounting/Domain/` | `app/Modules/Company/Domain/Company.php` | `App\Modules\Company\Domain` |
| Document | - | `app/Modules/Document/Domain/Document.php` | `App\Modules\Document\Domain` |
| Account | - | `app/Modules/Accounting/Domain/Account.php` | `App\Modules\Accounting\Domain` |
| JournalEntry | - | `app/Modules/Accounting/Domain/JournalEntry.php` | `App\Modules\Accounting\Domain` |
| JournalLine | - | `app/Modules/Accounting/Domain/JournalLine.php` | `App\Modules\Accounting\Domain` |
| Payment | - | `app/Modules/Treasury/Domain/Payment.php` | `App\Modules\Treasury\Domain` |
| PaymentAllocation | - | `app/Modules/Treasury/Domain/PaymentAllocation.php` | `App\Modules\Treasury\Domain` |
| Partner | - | `app/Modules/Partner/Domain/Partner.php` | `App\Modules\Partner\Domain` |

---

## Controller Architecture

**Pattern Used:** `Presentation/Controllers/` (NOT `Http/Controllers/`)

| Module | Controller Path | Namespace |
|--------|-----------------|-----------|
| Accounting | `Accounting/Presentation/Controllers/` | `App\Modules\Accounting\Presentation\Controllers` |
| Company | `Company/Presentation/Controllers/` | `App\Modules\Company\Presentation\Controllers` |
| Document | `Document/Presentation/Controllers/` | `App\Modules\Document\Presentation\Controllers` |
| Partner | `Partner/Presentation/Controllers/` | `App\Modules\Partner\Presentation\Controllers` |
| Treasury | `Treasury/Presentation/Controllers/` | `App\Modules\Treasury\Presentation\Controllers` |

**Routes:** Each module has `Presentation/routes.php`, required by `routes/api.php`

---

## Enum Inventory

### DocumentType
- **Location:** `app/Modules/Document/Domain/Enums/DocumentType.php`
- **Namespace:** `App\Modules\Document\Domain\Enums`
- **Cases:**
  - `Quote = 'quote'`
  - `SalesOrder = 'sales_order'`
  - `PurchaseOrder = 'purchase_order'`
  - `Invoice = 'invoice'`
  - **`CreditNote = 'credit_note'`** (already exists!)
  - `DeliveryNote = 'delivery_note'`
- **Methods:** `getPrefix()`, `label()`

### PaymentType
- **Location:** `app/Modules/Treasury/Domain/Enums/PaymentType.php`
- **Namespace:** `App\Modules\Treasury\Domain\Enums`
- **Cases:**
  - `DocumentPayment = 'document_payment'`
  - `Advance = 'advance'`
  - `Refund = 'refund'`
  - `CreditApplication = 'credit_application'`
  - `SupplierPayment = 'supplier_payment'`
- **Methods:** `label()`, `increasesReceivable()`, `decreasesReceivable()`, `createsCredit()`, `isIncoming()`, `isOutgoing()`

### PaymentStatus
- **Location:** `app/Modules/Treasury/Domain/Enums/PaymentStatus.php`
- **Cases:** `Pending`, `Completed`, `Failed`, `Reversed`
- **Methods:** `canReverse()`, `isTerminal()`, `isSuccessful()`

### InstrumentStatus
- **Location:** `app/Modules/Treasury/Domain/Enums/InstrumentStatus.php`
- **Cases:** `Received`, `InTransit`, `Deposited`, `Clearing`, `Cleared`, `Bounced`, `Expired`, `Cancelled`, `Collected`

### SystemAccountPurpose
- **Location:** `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`
- **Namespace:** `App\Modules\Accounting\Domain\Enums`
- **Current Cases:**
  - **Assets:** `Bank`, `Cash`, `CustomerReceivable`, `SupplierAdvance`, `Inventory`
  - **Liabilities:** `SupplierPayable`, `CustomerAdvance`, `VatCollected`, `VatDeductible`
  - **Revenue:** `ProductRevenue`, `ServiceRevenue`
  - **Expense:** `CostOfGoodsSold`, `PurchaseExpenses`
  - **Equity:** `RetainedEarnings`, `OpeningBalanceEquity`

### RepositoryType
- **Location:** `app/Modules/Treasury/Domain/Enums/RepositoryType.php`
- **Cases:** `CashRegister`, `Safe`, `BankAccount`, `Virtual`

### FeeType
- **Location:** `app/Modules/Treasury/Domain/Enums/FeeType.php`
- **Cases:** `None`, `Fixed`, `Percentage`, `Mixed`

---

## Existing Services

### Accounting Module
| Service | Location | Key Methods |
|---------|----------|-------------|
| GeneralLedgerService | `Domain/Services/` | `createFromInvoice()`, `createFromCreditNote()`, `createPaymentEntry()`, `createCustomerAdvanceJournalEntry()`, `createSupplierInvoiceJournalEntry()`, `createSupplierPaymentJournalEntry()`, `postEntry()` |
| ChartOfAccountsService | `Application/Services/` | `seedForCompany()`, `validateCompanyAccounts()` |
| PartnerBalanceService | `Application/Services/` | `refreshPartnerBalance()`, `getPartnerBalance()` |

### Treasury Module
| Service | Location | Key Methods |
|---------|----------|-------------|
| MultiPaymentService | `Domain/Services/` | `createSplitPayment()`, `recordDeposit()`, `applyDepositToDocument()`, `getUnallocatedDepositBalance()`, `recordPaymentOnAccount()`, `getPartnerAccountBalance()` |
| PaymentRefundService | `Domain/Services/` | Refund and reversal operations |

### Document Module
| Service | Location | Key Methods |
|---------|----------|-------------|
| DocumentPostingService | `Domain/Services/` | `post()`, posting with fiscal hash |
| DocumentConversionService | `Domain/Services/` | Quote → Order → Invoice conversion |
| DocumentNumberingService | `Domain/Services/` | Sequential document numbering |

---

## Country/Company Structure

### Country Model
- **Location:** `app/Models/Country.php` (global, NOT in modules)
- **Primary Key:** `code` (ISO 3166-1 alpha-2)
- **Fields:** `code`, `name`, `native_name`, `currency_code`, `currency_symbol`, `phone_prefix`, `date_format`, `default_locale`, `default_timezone`, `is_active`, `tax_id_label`, `tax_id_regex`
- **Relationship:** `hasMany(CountryTaxRate::class)`

### Company Model
- **Location:** `app/Modules/Company/Domain/Company.php`
- **Key Field:** `country_code` (links to Country)
- **Margin Fields:** `inventory_costing_method`, `default_target_margin`, `default_minimum_margin`, `allow_below_cost_sales`
- **No `payment_tolerance_*` fields yet**

### Country Tax Rates
- **Location:** `app/Models/CountryTaxRate.php`
- **Purpose:** Tax rates per country (VAT, etc.)

### Country-Specific Settings
- **Current State:** No `country_payment_settings` table exists
- **COA Seeding:** Only Tunisia (TN) implemented via `TunisiaChartOfAccountsSeeder`

---

## Credit Note Analysis

| Aspect | Status | Details |
|--------|--------|---------|
| Document type exists | ✓ Yes | `CreditNote = 'credit_note'` in DocumentType enum |
| GL method exists | ✓ Yes | `createFromCreditNote()` in GeneralLedgerService |
| `related_document_id` | ? Check | Need to verify in documents table |
| `credit_note_reason` | ✗ Missing | No CreditNoteReason enum exists |
| `return_comment` | ✗ Missing | Field does not exist on documents |

---

## Gaps for Smart Payment Implementation

### New Models Required

| Model | Location | Purpose |
|-------|----------|---------|
| CountryPaymentSettings | `Treasury/Domain/` | Payment tolerance per country |

### New Enums Required

| Enum | Location | Cases |
|------|----------|-------|
| AllocationMethod | `Treasury/Domain/Enums/` | `Fifo`, `DueDatePriority`, `Manual` |
| CreditNoteReason | `Document/Domain/Enums/` | `Return`, `PriceAdjustment`, `BillingError`, `DamagedGoods`, `ServiceIssue`, `Other` |

### Enum Additions Required

| Enum | New Cases |
|------|-----------|
| SystemAccountPurpose | `PaymentToleranceExpense`, `PaymentToleranceIncome`, `SalesReturn`, `RealizedFxGain`, `RealizedFxLoss`, `SalesDiscount` |
| AllocationType | Does not exist - may need to create |

### New Services Required

| Service | Location | Purpose |
|---------|----------|---------|
| PaymentToleranceService | `Treasury/Application/Services/` | Tolerance checking and auto-write-off |
| PaymentAllocationService | `Treasury/Application/Services/` | FIFO/DueDate allocation logic |

### Database Migrations Required

| Table/Column | Type | Purpose |
|--------------|------|---------|
| `country_payment_settings` | New table | Country-specific tolerance settings |
| `companies.payment_tolerance_enabled` | New column | Company override for tolerance |
| `companies.payment_tolerance_percentage` | New column | Company override for percentage |
| `companies.max_payment_tolerance_amount` | New column | Company override for max amount |
| `documents.related_document_id` | Check if exists | Link credit note to original invoice |
| `documents.credit_note_reason` | New column | Reason enum for credit notes |
| `documents.return_comment` | New column | Comment for credit note |
| `payments.allocation_method` | New column | Track allocation method used |
| `payment_allocations.tolerance_writeoff` | New column | Track tolerance amount |

---

## Spec Updates Required

The Smart Payment spec documents need these corrections:

| In Spec | Should Be | Impact |
|---------|-----------|--------|
| `App\Modules\CountryAdaptation\Domain\Country` | `App\Models\Country` | Namespace imports |
| `App\Modules\CountryAdaptation\Domain\CountryPaymentSettings` | `App\Modules\Treasury\Domain\CountryPaymentSettings` | New model location |
| `App\Modules\Accounting\Domain\Company` | `App\Modules\Company\Domain\Company` | Namespace imports |
| `DocumentType::CREDIT_MEMO` | Use existing `DocumentType::CreditNote` | No new case needed |
| `CreditMemoReason` enum | `CreditNoteReason` enum | Naming consistency |
| `CreditMemoService` | `CreditNoteService` | Naming consistency |
| `app/Modules/Treasury/Http/Controllers/` | `app/Modules/Treasury/Presentation/Controllers/` | Controller paths |
| `routes/api.php` additions | `app/Modules/Treasury/Presentation/routes.php` | Route locations |
| `collect()->sum()` | `array_reduce()` with bcmath | Money calculations |
| `app(Service::class)` | Constructor injection | Service injection |
| Time estimates in phases | Remove all time estimates | CLAUDE.md compliance |

---

## Recommendations

1. **Use existing `CreditNote`** document type instead of adding `CREDIT_MEMO`
2. **Place `CountryPaymentSettings`** in Treasury module since no CountryAdaptation module exists
3. **Rename `CreditMemoReason`** to `CreditNoteReason` for consistency
4. **Add missing columns** to documents table for credit note linking
5. **Follow existing patterns** for controller and service placement
6. **Use bcmath** for all money calculations (4 decimal precision)
7. **Use constructor injection** for all service dependencies

---

## Verification Commands

```bash
# Verify module structure
ls -la apps/api/app/Modules/

# Verify Country model location
find apps/api/app -name "Country.php" -type f

# Verify Company model location
find apps/api/app -name "Company.php" -type f

# Verify DocumentType enum
grep -A 20 "enum DocumentType" apps/api/app/Modules/Document/Domain/Enums/DocumentType.php

# Verify controller pattern
find apps/api/app/Modules -type d -name "Presentation"

# Verify no CountryAdaptation module
ls apps/api/app/Modules/CountryAdaptation/ 2>/dev/null || echo "Does not exist"
```
