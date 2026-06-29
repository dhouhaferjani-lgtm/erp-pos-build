# AutoERP Technical Debt & Gaps Discovery

**Date:** 2026-06-15
**Scope:** Full codebase analysis (`apps/api`, `apps/web`, `apps/pos`, `packages/shared`)
**Codebase:** 1,526 PHP source files (API), 584 test files, 330 migrations, 38 modules

---

## CRITICAL -- Could Cause Bugs or Data Issues

### 1. Float Arithmetic on Money

The Billing module's `Money` value object uses `float` internally (`public float $amount`), which causes IEEE 754 precision errors on financial calculations. All arithmetic (`add`, `subtract`, `multiply`) uses native float operators instead of bcmath.

**File:** `apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php`

Meanwhile, the rest of the codebase (Treasury, POS, Document, Accounting) correctly uses bcmath string arithmetic (1,149 bcmath calls across 187 files). The Billing module is the outlier.

Additionally, the following services cast decimal strings to `(float)` for money math, bypassing bcmath:

| File | Issue |
|------|-------|
| `Modules/POS/Application/Services/ReceiptReturnService.php:529` | `(float) $netAmount * (float) $taxRate / 100.0` |
| `Modules/POS/Application/Services/ReceiptSyncService.php:468` | Same pattern |
| `Modules/Inventory/Application/Services/LandedCostService.php` | Pervasive `(float)` casts on proportions, costs, subtotals (~15 casts) |
| `Modules/Inventory/Application/Services/WeightedAverageCostService.php` | `(float)` on cost_price for WAC calculation |
| `Modules/Product/Application/Services/MarginService.php` | `(float)` on cost_price, sale_price for margin math |
| `Modules/Document/Application/Services/FacturXService.php:222-236` | `(float)` casts on subtotal, tax_amount, total, balance_due |
| `Modules/Document/Application/DTOs/DocumentData.php:138-139` | `(float)` on total and balance_due |
| `Modules/Loyalty/Domain/ValueObjects/LoyaltyBalance.php` | All methods typed `float $amount` |
| `Modules/Loyalty/Domain/Services/PointEarningService.php` | Multiple `(float)` casts on transaction amounts |

**Risk:** Rounding errors accumulate in invoices, POS receipts, and accounting entries. A TND 999.99 receipt split across tax groups could lose or gain millimes.

### 2. 'XXX' Currency Fallback in Z-Report Sync

**File:** `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:262,361`

When payment method currency is not transmitted in the sync payload, the code falls back to `'XXX'` (ISO 4217 "no currency"). If the company lookup also fails, `'XXX'` is persisted. This corrupts financial records.

### 3. TODO: Missing Notifications in DailyExpiryCheck

**File:** `apps/api/app/Modules/BatchExpiry/Jobs/DailyExpiryCheck.php:64,196`

```
// TODO: Send notifications to company admins
// TODO: Send alert to system administrators
```

This daily job detects expiring/expired product batches but **never notifies anyone**. For regulated industries (pharma, food), this is a compliance gap.

### 4. Hierarchy Balance Calculation Broken in Accounting Reports

**File:** `apps/api/app/Modules/Accounting/Presentation/Controllers/ReportsController.php:143,298,449`

Three endpoints have the same `TODO: Fix hierarchy balance calculation` comment. The `include_hierarchy` parameter exists and is accepted but the underlying calculation is known-broken. Users requesting hierarchical trial balance, P&L, or balance sheet get incorrect numbers.

### 5. Missing COGS Entry Creation

**File:** `apps/api/tests/Feature/Document/CompleteSalesCycleWithReturnTest.php:409,436`

```
// TODO: Create COGS entry for invoice
// TODO: Verify COGS entry exists (currently skipped, see above)
```

Test explicitly documents that COGS journal entries are not created for invoices. This means cost of goods sold is not tracked in the general ledger for the sales cycle.

---

## IMPORTANT -- Architectural Violations

### 6. Massive Circular Dependencies (41 unique pairs)

41 bidirectional module-to-module imports. The worst clusters:

- **Document <-> 11 other modules** (Accounting, BatchExpiry, Communication, Company, Compliance, Expense, Inventory, Product, Service, Taxation, Treasury)
- **POS <-> 6 modules** (Accounting, BatchExpiry, Compliance, Tenant, Treasury, Catalog)
- **Company <-> 6 modules** (Accounting, Document, Identity, SmartPrompts, Taxation, Tenant)
- **Accounting <-> 5 modules** (Company, Document, Inventory, POS, Treasury)
- **Treasury <-> 5 modules** (Accounting, Document, POS, Taxation, Tenant)

Most modules import directly from each other's `Domain` layer rather than going through `app/Shared/Contracts/`. Only 16 of 38 modules use shared contracts at all.

### 7. Direct Domain Layer Imports Across Module Boundaries

Modules reach directly into another module's `Domain/` instead of depending on a `Shared/Contracts` interface. The top offenders:

| Importer | Imports From | Count | Location |
|----------|-------------|-------|----------|
| Compliance | Document/Domain | 13 | DomainEventSubscriber.php |
| Compliance | POS/Domain | 11 | DomainEventSubscriber.php |
| Inventory | Accounting/Domain | 9 | InventoryOpeningService.php |
| Workshop | Document/Domain | 7 | DocumentGenerationAdapter.php |
| POS | Catalog/Domain | 7 | ReceiptCreationService.php |
| Marketplace | Document/Domain | 7 | MarketplaceOrderService.php |
| Cart | Document/Domain | 7 | CartConversionService.php |

### 8. Inconsistent Module Structure

The intended hexagonal pattern is `Application/`, `Domain/`, `Infrastructure/`, `Presentation/`, `Providers/`. Multiple violations:

**Service provider placement (6 modules at root instead of Providers/):**
- `BatchExpiry/BatchExpiryServiceProvider.php`
- `Company/CompanyServiceProvider.php`
- `Media/MediaServiceProvider.php`
- `Partner/PartnerServiceProvider.php`
- `Product/ProductServiceProvider.php`
- `Scheduling/SchedulingServiceProvider.php`

**Routes placement (11 modules at root instead of Presentation/):**
- Company, Contact, Dashboard, Expense, Identity, Media, POS (5 route files at root), Partner, Product, Taxation, Tenant

**Non-hexagonal directories at module root:**
- `Company/Services/` (should be `Application/Services/`)
- `Compliance/Services/`, `Compliance/Commands/`, `Compliance/Listeners/`
- `Import/Services/`
- `BatchExpiry/Jobs/`, `BatchExpiry/Notifications/`
- `POS/Commands/`
- `Accounting/Listeners/`, `Inventory/Listeners/`

**Missing Application layer:**
- `Dashboard` -- has only Presentation/ and Providers/
- `Pricing` -- has only Domain/ and Presentation/

**Workshop uses unique sub-module pattern:**
- `Workshop/Bundle/`, `Workshop/Technician/`, `Workshop/WorkOrder/` -- each is a full hexagonal module. No other module follows this pattern. This is not necessarily wrong but is unique.

**Modules with no ServiceProvider at all:**
- `Admin`, `Communication`, `Workshop` (though Workshop's sub-modules each have one)

### 9. Hardcoded Permissions Map in Frontend

**File:** `apps/web/src/hooks/usePermissions.ts:1`

```
// TODO(auth): this hook reads from a hardcoded ROLE_PERMISSIONS map. The proper fix is to consume
```

Permissions are hardcoded in the frontend instead of being fetched from the API. Any backend permission change requires a frontend deployment.

### 10. Test Anti-patterns Documented

**File:** `apps/web/src/features/crm/api/__tests__/contactApi.test.ts:1`

```
// TODO(test-pattern): this file mocks apiGet/api.get with hand-fed payloads. This antipattern
```

Tests mock at the wrong layer, making them brittle and not representative of actual API contracts.

---

## IMPORTANT -- Missing Test Coverage

### Modules with ZERO test files:

| Module | Source Files | Tests |
|--------|-------------|-------|
| **Billing** | Has domain, services, controllers, webhooks | 0 |
| **Communication** | Has services, controllers | 0 |
| **Dashboard** | Has controller | 0 |
| **Expense** | Has services, controllers | 0 |
| **Media** | Has services, controllers | 0 |

### Modules with minimal coverage (1-3 test files):

| Module | Tests | Concern |
|--------|-------|---------|
| Admin | 2 | Monitoring service untested |
| Coupon | 2 | Validation logic untested |
| Menu | 3 | |
| Pricing | 2 | Pricing engine barely tested |
| PurchaseHub | 2 | |
| SmartPrompts | 2 | |
| Uom | 3 | Unit conversion precision critical |

---

## IMPORTANT -- Hardcoded Values

### Currency defaults scattered across modules:

| File | Default |
|------|---------|
| `Billing/Application/Services/InvoiceService.php:57,131` | `'EUR'` hardcoded |
| `Billing/Presentation/Controllers/StripeWebhookController.php:252,329,384` | `'EUR'` fallback |
| `Billing/Presentation/Controllers/AdminBillingController.php:250` | `'EUR'` default |
| `Service/Domain/Service.php:88` | `'TND'` hardcoded as attribute default |
| `Tenant/Application/Services/TenantInitializationService.php:102` | `'TND'` fallback |
| `Tenant/Application/Commands/CreateTenantCommand.php:72` | `'EUR'` fallback |
| `Treasury/Application/Services/PaymentToleranceQueryService.php:29` | `DEFAULT_CURRENCY = 'EUR'` |
| `Treasury/Presentation/Controllers/PaymentController.php:182,199,318` | `'TND'` fallback |

No single source of truth for default currency. Different modules assume different currencies.

### Other hardcoded values:

| File | Value | Issue |
|------|-------|-------|
| `Taxation/Infrastructure/Exporters/FecExporter.php:47` | `$siren = '000000000'` | FEC export uses placeholder SIREN number |
| `Billing/Infrastructure/Providers/ManualPaymentProvider.php:188-189` | `'XXXX XXXX XXXX XXXX'` IBAN, `'XXXXXXXX'` BIC | Placeholder bank details in payment instructions |
| `Product/Application/Services/MarginService.php:49,52` | `30.0` target margin, `15.0` minimum margin | Should be config or company-level settings (partially addressed via company defaults but hardcoded fallback) |
| `Import/Services/MigrationWizardService.php:246,259,302,315` | `'tax_rate' => '19'` | Hardcoded Tunisia 19% VAT rate in migration wizard |

---

## MINOR -- Cleanup and Style

### 11. Commented-Out Code

| File | What |
|------|------|
| `Compliance/Services/AnomalyDetectionService.php:388-389` | Auto-restrict access feature stub |
| `Compliance/Services/FraudAlertNotificationService.php:43,46` | Slack and in-app notification stubs |
| `Product/Application/Services/MarginService.php:250-251` | Category margin override |

### 12. Temporary/Deferred Code

| File | Note |
|------|------|
| `Tenant/Application/Services/TenantInitializationService.php:73,233` | `TODO: Remove when Inventory becomes a separately purchased module` -- coupling Inventory to tenant init |
| `POS/Application/Services/FraudSettingsResolver.php:50` | Per-location override placeholder |
| `Compliance/Services/AnomalyDetectionService.php:387` | `TODO: Auto-restrict access if enabled (will implement in Phase 5)` |
| `Document/Application/Services/ArApOpeningService.php` | Uses `HIST-INV-XXXX` / `HIST-CN-XXXX` numbering scheme |

### 13. `app()` Helper Usage

Only 2 occurrences found, both acceptable:
- `Admin/Application/Services/MonitoringService.php:138` -- `app()->version()` (framework version check)
- `Tenant/Application/Commands/ResetTenantCommand.php:26` -- `app()->environment('production')` (environment guard)

Constructor injection is consistently used across the codebase. This is clean.

### 14. POS Frontend: Deferred Feature Flag

**File:** `apps/pos/src/api/toleranceApi.ts:14`

```
// TODO(feature-flags): gate this call on a `tolerance_v2` feature flag once
```

Feature flag system not yet implemented for POS tolerance features.

### 15. Missing Image Upload in Categories

**File:** `apps/web/src/features/categories/api/categoriesApi.ts:150,166`

```
// TODO: Handle image upload when file upload is implemented
```

Category image upload is not implemented.

---

## Summary by Priority

| Severity | Count | Key Risk |
|----------|-------|----------|
| **CRITICAL** | 5 | Float money math, missing COGS, broken hierarchy reports, silent batch expiry, XXX currency |
| **IMPORTANT** | 10 | 41 circular deps, direct domain imports, inconsistent structure, zero-test modules, hardcoded currencies |
| **MINOR** | 5 | Commented code, temp stubs, deferred features |

### Top 3 Recommendations

1. **Fix float money in Billing/Money VO and LandedCostService** -- rewrite to use bcmath string arithmetic. These are the two highest-risk float-on-money locations.

2. **Introduce shared contracts for the top circular dependency clusters** -- start with Document, Company, and Product since they're the most imported Domain layers. Extract interfaces to `app/Shared/Contracts/`.

3. **Add tests for Billing and Expense modules** -- these are financial modules with zero tests. Billing handles Stripe webhooks and subscription invoicing; bugs here directly impact revenue.
