# AutoERP - Pre-Fork Implementation Plan

> **Purpose:** Complete all critical features and testing before forking the codebase
> **Created:** December 2025
> **Estimated Total Effort:** 120-150 hours

---

## Table of Contents

1. [Phase 1: Regression Tests](#phase-1-regression-tests-critical)
2. [Phase 2: Critical Reports](#phase-2-critical-reports)
3. [Phase 3: Pricing & Discounts](#phase-3-pricing--discounts)
4. [Phase 4: Import Completion](#phase-4-import-completion)
5. [Phase 5: Final Polish](#phase-5-final-polish)

---

## Phase 1: Regression Tests (CRITICAL)

### Current Test Status
- **Backend:** 114 files, 1,025 test methods
- **Frontend:** 23 files, ~435 test cases
- **Coverage:** Strong on core paths, critical gaps identified

### Priority 1: CRITICAL Missing Tests

#### 1.1 Multi-Payment Tests
**File:** `tests/Feature/Treasury/MultiPaymentTest.php`

```php
class MultiPaymentTest extends TestCase
{
    // Test payment split across cash and check
    public function test_payment_split_across_cash_and_check(): void

    // Test payment split with different currencies
    public function test_payment_split_with_different_currencies(): void

    // Test payment split with tolerance on each method
    public function test_payment_split_with_tolerance_on_each_method(): void

    // Test that reversal maintains allocation integrity
    public function test_reverse_payment_maintains_allocation_integrity(): void

    // Test split payment creates correct GL entries for each method
    public function test_split_payment_creates_gl_entries_per_method(): void
}
```

**Why Critical:** MultiPaymentService is used but untested. Risk of regression when modifying payment flows.

---

#### 1.2 Payment Refund Tests
**File:** `tests/Feature/Treasury/PaymentRefundTest.php`

```php
class PaymentRefundTest extends TestCase
{
    // Full refund reverses entire allocation
    public function test_refund_full_payment_reverses_allocation(): void

    // Partial refund adjusts balance correctly
    public function test_refund_partial_payment_adjusts_balance(): void

    // Refund creates reverse journal entries
    public function test_refund_creates_reverse_journal_entries(): void

    // Refund restores invoice balance_due
    public function test_refund_restores_invoice_balance(): void

    // Cannot refund more than paid amount
    public function test_cannot_refund_more_than_paid(): void

    // Refund with different payment method
    public function test_refund_with_different_payment_method(): void
}
```

**Why Critical:** RefundService and PaymentRefundService exist but have no test coverage. Money-critical path.

---

#### 1.3 Document GL Integration Tests
**File:** `tests/Feature/Accounting/DocumentGLIntegrationTest.php`

```php
class DocumentGLIntegrationTest extends TestCase
{
    // Invoice posting creates AR and Revenue entries
    public function test_posting_invoice_creates_receivable_and_revenue_entries(): void

    // Credit note creates reversal entries
    public function test_posting_credit_note_creates_reversal_entries(): void

    // Multiple tax rates create separate tax liability entries
    public function test_multiple_tax_rates_create_separate_tax_entries(): void

    // GL entries match document total exactly
    public function test_gl_entries_balance_equals_document_total(): void

    // Posted document cannot be re-posted
    public function test_posted_document_cannot_be_reposted(): void

    // Cancelled document creates reversal GL entries
    public function test_cancelled_invoice_creates_reversal_entries(): void
}
```

---

#### 1.4 Stock Movement GL Integration
**File:** `tests/Feature/Inventory/StockMovementGLIntegrationTest.php`

```php
class StockMovementGLIntegrationTest extends TestCase
{
    // Stock receipt from PO creates inventory and payable entries
    public function test_stock_receipt_creates_inventory_and_payable_entries(): void

    // Stock adjustment creates variance entries
    public function test_stock_adjustment_creates_variance_entries(): void

    // Inventory counting variance creates writeoff entries
    public function test_counted_variance_creates_writeoff_entries(): void

    // Landed cost allocation updates cost basis
    public function test_landed_cost_allocation_creates_cost_entries(): void
}
```

---

#### 1.5 Concurrent Document Numbering
**File:** `tests/Feature/Document/ConcurrentNumberingTest.php`

```php
class ConcurrentNumberingTest extends TestCase
{
    // 10 parallel invoice creations maintain unique sequences
    public function test_concurrent_invoice_creation_maintains_sequence(): void

    // Different document types don't interfere
    public function test_sequence_by_company_and_type_isolation(): void

    // No duplicate numbers under load
    public function test_no_duplicate_numbers_across_parallel_requests(): void
}
```

---

### Priority 2: Important Tests

#### 1.6 Tolerance GL Integration
**File:** `tests/Feature/Treasury/ToleranceGLIntegrationTest.php`

```php
class ToleranceGLIntegrationTest extends TestCase
{
    // Underpayment tolerance creates expense entry
    public function test_underpayment_tolerance_creates_expense_entry(): void

    // Overpayment tolerance creates income entry
    public function test_overpayment_tolerance_creates_income_entry(): void

    // Tolerance exceeding threshold prevents posting
    public function test_tolerance_exceeding_threshold_prevents_posting(): void
}
```

---

#### 1.7 Delivery Note Edge Cases
**File:** `tests/Feature/Document/DeliveryNoteEdgeCasesTest.php`

```php
class DeliveryNoteEdgeCasesTest extends TestCase
{
    // Partial delivery updates remaining quantity
    public function test_partial_delivery_updates_remaining_balance(): void

    // Complete delivery of previously partial order
    public function test_complete_delivery_of_partial_order(): void

    // Cannot deliver more than ordered
    public function test_cannot_deliver_more_than_ordered(): void

    // Delivery note cancellation restores stock
    public function test_delivery_cancellation_restores_stock(): void
}
```

---

### Test Implementation Checklist

| Test File | Methods | Priority | Status |
|-----------|---------|----------|--------|
| MultiPaymentTest.php | 5 | CRITICAL | [ ] |
| PaymentRefundTest.php | 6 | CRITICAL | [ ] |
| DocumentGLIntegrationTest.php | 6 | CRITICAL | [ ] |
| StockMovementGLIntegrationTest.php | 4 | HIGH | [ ] |
| ConcurrentNumberingTest.php | 3 | HIGH | [ ] |
| ToleranceGLIntegrationTest.php | 3 | MEDIUM | [ ] |
| DeliveryNoteEdgeCasesTest.php | 4 | MEDIUM | [ ] |

**Total New Tests:** 31 test methods
**Estimated Effort:** 16-20 hours

---

## Phase 2: Critical Reports

### 2.1 Sales Revenue Chart

**Backend Endpoint:** `GET /api/v1/reports/sales-chart`

**Controller:** `ReportController.php`
```php
public function salesChart(Request $request): JsonResponse
{
    $validated = $request->validate([
        'period' => 'required|in:daily,weekly,monthly',
        'from_date' => 'required|date',
        'to_date' => 'required|date|after:from_date',
    ]);

    $data = $this->reportService->getSalesChart(
        $request->user()->company_id,
        $validated['period'],
        $validated['from_date'],
        $validated['to_date']
    );

    return response()->json(['data' => $data]);
}
```

**Service:** `ReportService.php`
```php
public function getSalesChart(
    string $companyId,
    string $period,
    string $fromDate,
    string $toDate
): array {
    $groupBy = match($period) {
        'daily' => "DATE(document_date)",
        'weekly' => "DATE_TRUNC('week', document_date)",
        'monthly' => "DATE_TRUNC('month', document_date)",
    };

    $results = Document::where('company_id', $companyId)
        ->where('type', DocumentType::Invoice)
        ->where('status', DocumentStatus::Posted)
        ->whereBetween('document_date', [$fromDate, $toDate])
        ->selectRaw("{$groupBy} as period, SUM(total) as revenue, COUNT(*) as invoice_count")
        ->groupByRaw($groupBy)
        ->orderByRaw($groupBy)
        ->get();

    return [
        'data' => $results,
        'summary' => [
            'total_revenue' => $results->sum('revenue'),
            'total_invoices' => $results->sum('invoice_count'),
            'average_per_period' => $results->avg('revenue'),
        ]
    ];
}
```

**Frontend Component:** `SalesChart.tsx`
- Install Recharts: `pnpm add recharts`
- Line/Bar chart with period toggle
- Date range picker
- Summary cards

**Estimated Effort:** 12 hours (Backend: 4h, Frontend: 8h)

---

### 2.2 Excel/CSV Export

**Backend Package:** `maatwebsite/excel`
```bash
composer require maatwebsite/excel
```

**Export Classes:**
```php
// app/Exports/TrialBalanceExport.php
class TrialBalanceExport implements FromCollection, WithHeadings
{
    public function __construct(
        private string $companyId,
        private string $asOfDate
    ) {}

    public function collection(): Collection
    {
        return $this->accountingService->getTrialBalance(
            $this->companyId,
            $this->asOfDate
        );
    }

    public function headings(): array
    {
        return ['Account Code', 'Account Name', 'Debit', 'Credit'];
    }
}
```

**Endpoints:**
```
GET /api/v1/reports/trial-balance/export?format=xlsx
GET /api/v1/reports/profit-loss/export?format=xlsx
GET /api/v1/reports/aged-receivables/export?format=xlsx
GET /api/v1/reports/aged-payables/export?format=xlsx
GET /api/v1/reports/sales-chart/export?format=csv
```

**Frontend:** Add export buttons to all report pages

**Estimated Effort:** 8 hours (Backend: 6h, Frontend: 2h)

---

### 2.3 Cash Flow Summary

**Backend Endpoint:** `GET /api/v1/reports/cash-flow`

**Service Logic:**
```php
public function getCashFlowSummary(
    string $companyId,
    string $fromDate,
    string $toDate
): array {
    // Opening balance: Sum of all payment repository balances at start
    $openingBalance = $this->getRepositoryBalances($companyId, $fromDate);

    // Inflows: Customer payments received
    $customerPayments = Payment::where('company_id', $companyId)
        ->where('payment_type', PaymentType::DocumentPayment)
        ->whereBetween('payment_date', [$fromDate, $toDate])
        ->sum('amount');

    // Outflows: Supplier payments made
    $supplierPayments = Payment::where('company_id', $companyId)
        ->where('payment_type', PaymentType::SupplierPayment)
        ->whereBetween('payment_date', [$fromDate, $toDate])
        ->sum('amount');

    // Breakdown by payment method
    $byMethod = Payment::where('company_id', $companyId)
        ->whereBetween('payment_date', [$fromDate, $toDate])
        ->join('payment_methods', 'payments.payment_method_id', '=', 'payment_methods.id')
        ->selectRaw('payment_methods.name, SUM(CASE WHEN payment_type IN (?, ?) THEN amount ELSE 0 END) as inflow',
            [PaymentType::DocumentPayment, PaymentType::Advance])
        ->selectRaw('SUM(CASE WHEN payment_type = ? THEN amount ELSE 0 END) as outflow',
            [PaymentType::SupplierPayment])
        ->groupBy('payment_methods.name')
        ->get();

    return [
        'opening_balance' => $openingBalance,
        'inflows' => [
            'customer_payments' => $customerPayments,
            'total' => $customerPayments,
        ],
        'outflows' => [
            'supplier_payments' => $supplierPayments,
            'total' => $supplierPayments,
        ],
        'closing_balance' => $openingBalance + $customerPayments - $supplierPayments,
        'net_change' => $customerPayments - $supplierPayments,
        'breakdown_by_method' => $byMethod,
    ];
}
```

**Estimated Effort:** 14 hours (Backend: 8h, Frontend: 6h)

---

### Reports Implementation Checklist

| Report | Backend | Frontend | Export | Status |
|--------|---------|----------|--------|--------|
| Sales Revenue Chart | [ ] | [ ] | [ ] | |
| Excel Export (5 reports) | [ ] | [ ] | N/A | |
| Cash Flow Summary | [ ] | [ ] | [ ] | |
| Top Customers | [ ] | [ ] | [ ] | |
| Top Products | [ ] | [ ] | [ ] | |

**Estimated Effort:** 40-50 hours

---

## Phase 3: Pricing & Discounts

### 3.1 Database Schema Extensions

**New Migration:** `add_discount_tables.php`

```php
Schema::create('discounts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('tenant_id');
    $table->uuid('company_id');
    $table->string('name');
    $table->text('description')->nullable();
    $table->enum('type', ['percentage', 'fixed_amount', 'buy_x_get_y']);
    $table->decimal('value', 10, 2); // Percentage or fixed amount
    $table->enum('scope', ['global', 'category', 'product', 'partner', 'partner_group']);
    $table->uuid('scope_id')->nullable(); // FK to category/product/partner
    $table->decimal('minimum_quantity', 10, 2)->nullable();
    $table->decimal('minimum_amount', 15, 2)->nullable();
    $table->date('valid_from')->nullable();
    $table->date('valid_until')->nullable();
    $table->boolean('is_active')->default(true);
    $table->integer('priority')->default(0); // Higher = applied first
    $table->boolean('is_combinable')->default(false);
    $table->timestamps();
    $table->softDeletes();

    $table->foreign('company_id')->references('id')->on('companies');
    $table->index(['company_id', 'is_active', 'valid_from', 'valid_until']);
});

Schema::create('discount_rules', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('discount_id');
    $table->enum('rule_type', ['quantity_tier', 'time_based', 'first_purchase', 'loyalty']);
    $table->json('conditions'); // Flexible rule conditions
    $table->decimal('adjusted_value', 10, 2)->nullable();
    $table->timestamps();

    $table->foreign('discount_id')->references('id')->on('discounts')->onDelete('cascade');
});

// Add discount tracking to document lines
Schema::table('document_lines', function (Blueprint $table) {
    $table->uuid('discount_id')->nullable()->after('tax_rate');
    $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_id');
    $table->decimal('original_unit_price', 15, 2)->nullable()->after('discount_amount');

    $table->foreign('discount_id')->references('id')->on('discounts');
});
```

---

### 3.2 Discount Types

| Type | Description | Example |
|------|-------------|---------|
| **Percentage** | X% off | 10% off all oil changes |
| **Fixed Amount** | $X off | $50 off brake service |
| **Buy X Get Y** | Quantity-based | Buy 4 tires, get alignment free |
| **Tiered** | Volume discount | 5+ units: 5% off, 10+ units: 10% off |
| **Time-Based** | Promotional period | Summer sale: 15% off AC service |
| **Partner-Specific** | Customer loyalty | VIP customers: 8% on all products |
| **Category** | Product group | 20% off all filters |
| **First Purchase** | New customer | First order: 10% off |

---

### 3.3 Discount Domain Model

**Entities:**

```php
// app/Modules/Pricing/Domain/Discount.php
class Discount extends Model
{
    protected $casts = [
        'type' => DiscountType::class,
        'scope' => DiscountScope::class,
        'value' => 'decimal:2',
        'minimum_quantity' => 'decimal:2',
        'minimum_amount' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'is_combinable' => 'boolean',
    ];

    public function isValid(): bool
    {
        if (!$this->is_active) return false;

        $now = now();
        if ($this->valid_from && $now->lt($this->valid_from)) return false;
        if ($this->valid_until && $now->gt($this->valid_until)) return false;

        return true;
    }

    public function appliesTo(Product $product, ?Partner $partner = null): bool
    {
        return match($this->scope) {
            DiscountScope::Global => true,
            DiscountScope::Category => $product->category_id === $this->scope_id,
            DiscountScope::Product => $product->id === $this->scope_id,
            DiscountScope::Partner => $partner?->id === $this->scope_id,
            DiscountScope::PartnerGroup => $partner?->group_id === $this->scope_id,
        };
    }
}

// Enums
enum DiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';
    case BuyXGetY = 'buy_x_get_y';
}

enum DiscountScope: string
{
    case Global = 'global';
    case Category = 'category';
    case Product = 'product';
    case Partner = 'partner';
    case PartnerGroup = 'partner_group';
}
```

---

### 3.4 Discount Application Service

```php
// app/Modules/Pricing/Application/Services/DiscountService.php
class DiscountService
{
    public function getApplicableDiscounts(
        string $companyId,
        Product $product,
        ?Partner $partner,
        float $quantity,
        float $lineTotal
    ): Collection {
        return Discount::where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('valid_from')
                  ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                  ->orWhere('valid_until', '>=', now());
            })
            ->where(function ($q) use ($quantity) {
                $q->whereNull('minimum_quantity')
                  ->orWhere('minimum_quantity', '<=', $quantity);
            })
            ->where(function ($q) use ($lineTotal) {
                $q->whereNull('minimum_amount')
                  ->orWhere('minimum_amount', '<=', $lineTotal);
            })
            ->orderBy('priority', 'desc')
            ->get()
            ->filter(fn($d) => $d->appliesTo($product, $partner));
    }

    public function calculateDiscount(
        Discount $discount,
        float $unitPrice,
        float $quantity
    ): float {
        return match($discount->type) {
            DiscountType::Percentage => $unitPrice * $quantity * ($discount->value / 100),
            DiscountType::FixedAmount => min($discount->value, $unitPrice * $quantity),
            DiscountType::BuyXGetY => $this->calculateBuyXGetY($discount, $unitPrice, $quantity),
        };
    }

    public function applyBestDiscount(
        string $companyId,
        Product $product,
        ?Partner $partner,
        float $unitPrice,
        float $quantity
    ): DiscountResult {
        $discounts = $this->getApplicableDiscounts(
            $companyId, $product, $partner, $quantity, $unitPrice * $quantity
        );

        if ($discounts->isEmpty()) {
            return new DiscountResult(
                discountId: null,
                originalPrice: $unitPrice,
                discountedPrice: $unitPrice,
                discountAmount: 0,
                discountName: null
            );
        }

        // Find best discount (highest savings)
        $best = $discounts->map(fn($d) => [
            'discount' => $d,
            'amount' => $this->calculateDiscount($d, $unitPrice, $quantity),
        ])->sortByDesc('amount')->first();

        $discountedTotal = ($unitPrice * $quantity) - $best['amount'];

        return new DiscountResult(
            discountId: $best['discount']->id,
            originalPrice: $unitPrice,
            discountedPrice: $discountedTotal / $quantity,
            discountAmount: $best['amount'],
            discountName: $best['discount']->name
        );
    }
}
```

---

### 3.5 API Endpoints

```
# Discount Management
GET    /api/v1/discounts              # List all discounts
POST   /api/v1/discounts              # Create discount
GET    /api/v1/discounts/{id}         # Get discount
PATCH  /api/v1/discounts/{id}         # Update discount
DELETE /api/v1/discounts/{id}         # Delete discount

# Discount Application (used by document creation)
POST   /api/v1/pricing/calculate      # Calculate price with discounts
{
  "product_id": "uuid",
  "partner_id": "uuid",
  "quantity": 5,
  "unit_price": 100.00
}

Response:
{
  "original_price": 100.00,
  "discounted_price": 90.00,
  "discount_amount": 50.00,
  "discount_name": "Volume Discount 10%",
  "discount_id": "uuid"
}
```

---

### 3.6 Frontend Components

**Pages:**
- `DiscountListPage.tsx` - List all discounts with filters
- `DiscountForm.tsx` - Create/edit discount
- `DiscountDetailPage.tsx` - View discount details

**Integration:**
- Update `DocumentLineEditor.tsx` to show discount info
- Add discount badge on product selection
- Show original vs discounted price

---

### Pricing Implementation Checklist

| Feature | Backend | Frontend | Tests | Status |
|---------|---------|----------|-------|--------|
| Discount schema/migration | [ ] | N/A | [ ] | |
| Discount entity & enums | [ ] | N/A | [ ] | |
| DiscountService | [ ] | N/A | [ ] | |
| Discount CRUD API | [ ] | [ ] | [ ] | |
| Price calculation API | [ ] | N/A | [ ] | |
| Document line integration | [ ] | [ ] | [ ] | |
| Discount list page | N/A | [ ] | [ ] | |
| Discount form | N/A | [ ] | [ ] | |

**Estimated Effort:** 30-40 hours

---

## Phase 4: Import Completion

### 4.1 Current Import Status

**Implemented:**
- Import framework (ImportJob, ImportRow)
- SpreadsheetParserService (CSV/Excel)
- ValidationEngine base

**Missing:**
- Specific import rules for each entity type
- Error handling and rollback
- Progress tracking
- Duplicate detection

---

### 4.2 Import Types Needed

| Import Type | Priority | Complexity |
|-------------|----------|------------|
| Partners (Customers/Suppliers) | HIGH | Low |
| Products | HIGH | Medium |
| Opening Balances (GL) | HIGH | Medium |
| Opening Stock | MEDIUM | Low |
| Historical Invoices | LOW | High |
| Price Lists | LOW | Low |

---

### 4.3 Partner Import Specification

**CSV Format:**
```csv
name,type,email,phone,vat_number,address,city,country_code
"ACME Corp",customer,contact@acme.com,+33123456789,FR12345678901,"123 Main St",Paris,FR
"Parts Supplier",supplier,sales@parts.com,+33987654321,FR98765432101,"456 Industrial Ave",Lyon,FR
```

**Validation Rules:**
```php
class PartnerImportRules implements ImportRules
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'type' => 'required|in:customer,supplier,both',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'vat_number' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country_code' => 'nullable|string|size:2',
        ];
    }

    public function transform(array $row): array
    {
        return [
            'name' => trim($row['name']),
            'type' => PartnerType::from($row['type']),
            'email' => $row['email'] ? strtolower(trim($row['email'])) : null,
            'phone' => $row['phone'] ?? null,
            'vat_number' => $row['vat_number'] ?? null,
            'address' => $row['address'] ?? null,
            'city' => $row['city'] ?? null,
            'country_code' => $row['country_code'] ? strtoupper($row['country_code']) : null,
        ];
    }

    public function findDuplicate(array $row, string $companyId): ?Partner
    {
        return Partner::where('company_id', $companyId)
            ->where(function ($q) use ($row) {
                $q->where('name', $row['name'])
                  ->orWhere('email', $row['email'])
                  ->orWhere('vat_number', $row['vat_number']);
            })
            ->first();
    }
}
```

---

### 4.4 Product Import Specification

**CSV Format:**
```csv
sku,name,description,type,sale_price,purchase_price,tax_rate,category,barcode
"OIL-5W30","Motor Oil 5W30","5L synthetic motor oil",product,45.00,32.00,20,Lubricants,3700123456789
"SVC-OIL","Oil Change Service","Standard oil change service",service,89.00,,20,Services,
```

**Validation Rules:**
```php
class ProductImportRules implements ImportRules
{
    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:50',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:product,service,consumable',
            'sale_price' => 'required|numeric|min:0',
            'purchase_price' => 'nullable|numeric|min:0',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'category' => 'nullable|string|max:100',
            'barcode' => 'nullable|string|max:50',
        ];
    }
}
```

---

### Import Implementation Checklist

| Import Type | Rules | Validation | UI | Tests | Status |
|-------------|-------|------------|-----|-------|--------|
| Partners | [ ] | [ ] | [ ] | [ ] | |
| Products | [ ] | [ ] | [ ] | [ ] | |
| Opening Balances | [ ] | [ ] | [ ] | [ ] | |
| Opening Stock | [ ] | [ ] | [ ] | [ ] | |

**Estimated Effort:** 20-25 hours

---

## Phase 5: Final Polish

### 5.1 Code Quality

- [ ] Run PHPStan level 8 - fix all errors
- [ ] Run TypeScript strict - fix all errors
- [ ] Run ESLint - fix all warnings
- [ ] Ensure all tests pass

### 5.2 Documentation

- [ ] Update API documentation
- [ ] Complete i18n translations (FR)
- [ ] Update CLAUDE.md if needed
- [ ] Archive completed TASKS.md items

### 5.3 Database

- [ ] Add missing indexes for report queries
- [ ] Review foreign key constraints
- [ ] Test migration rollback

### 5.4 Security Review

- [ ] Review all API endpoints for authorization
- [ ] Check input validation completeness
- [ ] Verify tenant isolation

---

## Summary: Implementation Order

### Week 1: Regression Tests (16-20h)
1. MultiPaymentTest.php
2. PaymentRefundTest.php
3. DocumentGLIntegrationTest.php
4. StockMovementGLIntegrationTest.php
5. ConcurrentNumberingTest.php

### Week 2: Critical Reports (20-25h)
1. Install Recharts + maatwebsite/excel
2. Sales Revenue Chart (backend + frontend)
3. Excel Export for 5 reports
4. Cash Flow Summary

### Week 3: Pricing & Discounts (30-40h)
1. Database schema
2. Discount domain model
3. DiscountService
4. API endpoints
5. Frontend pages

### Week 4: Import & Polish (15-20h)
1. Partner import rules
2. Product import rules
3. Final code quality checks
4. Documentation updates

---

**Total Estimated Effort:** 81-105 hours (approximately 2-3 weeks focused work)

---

*Document Version: 1.0*
*Created: December 2025*
