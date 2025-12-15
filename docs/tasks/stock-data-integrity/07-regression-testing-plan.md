# E2E & Regression Testing Plan

> **Created:** 2025-12-13
> **Purpose:** Prevent regressions on critical financial/inventory features

---

## Executive Summary

This document outlines a comprehensive testing strategy to ensure data integrity features cannot regress. The plan covers:
1. Critical test scenarios that must never fail
2. Test implementation patterns
3. CI/CD integration requirements
4. Monitoring for production

---

## 1. Test Categories & Priorities

### Tier 1: Must Never Fail (Blocking CI)

These tests are **required to pass** for any merge to main:

| Test Category | What It Protects | Failure Impact |
|---------------|------------------|----------------|
| Stock Movement Atomicity | Inventory accuracy | Double-selling, lost stock |
| WAC Calculation | Cost of goods | Incorrect margins, tax issues |
| Landed Cost Allocation | Purchase costing | Wrong inventory valuation |
| Fiscal Hash Chain | Compliance | Legal issues, fines |
| Payment Allocation | Cash tracking | Missing money |
| Document Conversion | Workflow integrity | Orphan documents |

### Tier 2: Should Pass (Warning CI)

These tests warn but don't block:

| Test Category | What It Protects |
|---------------|------------------|
| UI Component Rendering | User experience |
| API Response Formats | Frontend compatibility |
| Performance Benchmarks | User experience |

### Tier 3: Nice to Have

| Test Category | What It Protects |
|---------------|------------------|
| Browser compatibility | Cross-browser UX |
| Accessibility | WCAG compliance |
| Mobile responsiveness | Mobile users |

---

## 2. Critical Test Scenarios (Tier 1)

### 2.1 Stock Movement Tests

```php
// tests/Feature/Inventory/StockIntegrityTest.php

class StockIntegrityTest extends TestCase
{
    /**
     * @test
     * @group critical
     * @group stock
     */
    public function stock_adjustment_is_atomic(): void
    {
        // Given: Product with 100 units
        $product = Product::factory()->create(['is_physical' => true]);
        $location = Location::factory()->create();
        StockLevel::factory()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
        ]);

        // When: Issue 50 units within transaction
        DB::transaction(function () use ($product, $location) {
            $service = app(StockAdjustmentService::class);
            $service->issue($product->id, $location->id, '50', 'TEST-001', auth()->id());
        });

        // Then: Stock is exactly 50
        $stockLevel = StockLevel::where('product_id', $product->id)->first();
        $this->assertEquals('50.0000', $stockLevel->quantity);

        // And: Movement record exists
        $movement = StockMovement::where('product_id', $product->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals('-50.0000', $movement->quantity);
    }

    /**
     * @test
     * @group critical
     * @group stock
     */
    public function concurrent_adjustments_are_serialized(): void
    {
        $product = Product::factory()->create(['is_physical' => true]);
        $location = Location::factory()->create();
        StockLevel::factory()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
        ]);

        // Simulate concurrent requests
        $results = [];
        $threads = [];

        for ($i = 0; $i < 5; $i++) {
            $threads[] = $this->forkProcess(function () use ($product, $location, $i) {
                try {
                    app(StockAdjustmentService::class)->issue(
                        $product->id,
                        $location->id,
                        '30',
                        "CONCURRENT-{$i}",
                        'system'
                    );
                    return 'success';
                } catch (InsufficientStockException $e) {
                    return 'insufficient';
                }
            });
        }

        // Wait for all threads
        foreach ($threads as $thread) {
            $results[] = $thread->wait();
        }

        // Verify: Some succeeded, some failed due to insufficient stock
        $successes = array_filter($results, fn($r) => $r === 'success');
        $failures = array_filter($results, fn($r) => $r === 'insufficient');

        // At most 3 should succeed (100 / 30 = 3.33)
        $this->assertLessThanOrEqual(3, count($successes));

        // Stock should be non-negative
        $stockLevel = StockLevel::where('product_id', $product->id)->first();
        $this->assertGreaterThanOrEqual(0, (float) $stockLevel->quantity);

        // Total issued should match movements
        $totalIssued = StockMovement::where('product_id', $product->id)
            ->where('movement_type', 'issue')
            ->sum(DB::raw('ABS(quantity)'));
        $this->assertEquals(
            (float) bcsub('100', $stockLevel->quantity, 4),
            (float) $totalIssued
        );
    }

    /**
     * @test
     * @group critical
     * @group stock
     */
    public function reservation_prevents_overselling(): void
    {
        $product = Product::factory()->create(['is_physical' => true]);
        $location = Location::factory()->create();
        StockLevel::factory()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        $service = app(StockAdjustmentService::class);

        // Reserve 8 units
        $service->reserve($product->id, $location->id, '8', 'SO-001', 'system');

        // Try to reserve 5 more (only 2 available)
        $this->expectException(InsufficientStockException::class);
        $service->reserve($product->id, $location->id, '5', 'SO-002', 'system');
    }
}
```

### 2.2 WAC Calculation Tests

```php
// tests/Feature/Inventory/WeightedAverageCostTest.php

class WeightedAverageCostTest extends TestCase
{
    /**
     * @test
     * @group critical
     * @group wac
     */
    public function wac_calculates_correctly_on_purchase(): void
    {
        // Given: Product with existing stock at $10/unit
        $product = Product::factory()->create([
            'cost_price' => '10.00',
        ]);
        $location = Location::factory()->create();
        StockLevel::factory()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
        ]);

        // When: Receive 50 more at $12/unit
        $service = app(WeightedAverageCostService::class);
        $service->recordPurchase($product, $location, 50, 12.00, 'PO-001');

        // Then: New WAC = (100 * 10 + 50 * 12) / 150 = 1600 / 150 = 10.67
        $product->refresh();
        $this->assertEquals('10.67', $product->cost_price);

        // And: Total quantity is correct
        $stockLevel = StockLevel::where('product_id', $product->id)->first();
        $this->assertEquals('150.0000', $stockLevel->quantity);
    }

    /**
     * @test
     * @group critical
     * @group wac
     */
    public function concurrent_purchases_maintain_correct_wac(): void
    {
        $product = Product::factory()->create(['cost_price' => '10.00']);
        $location = Location::factory()->create();
        StockLevel::factory()->create([
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 100,
        ]);

        // Simulate concurrent purchases
        $threads = [];
        $threads[] = $this->forkProcess(function () use ($product, $location) {
            app(WeightedAverageCostService::class)
                ->recordPurchase($product, $location, 50, 12.00, 'PO-001');
        });

        $threads[] = $this->forkProcess(function () use ($product, $location) {
            app(WeightedAverageCostService::class)
                ->recordPurchase($product, $location, 30, 15.00, 'PO-002');
        });

        foreach ($threads as $thread) {
            $thread->wait();
        }

        // Verify final state
        $product->refresh();
        $stockLevel = StockLevel::where('product_id', $product->id)->first();

        // Total quantity should be 100 + 50 + 30 = 180
        $this->assertEquals('180.0000', $stockLevel->quantity);

        // WAC should be (100*10 + 50*12 + 30*15) / 180 = 2050 / 180 = 11.39
        $this->assertEquals('11.39', $product->cost_price);
    }
}
```

### 2.3 Landed Cost Tests

```php
// tests/Feature/Inventory/LandedCostTest.php

class LandedCostTest extends TestCase
{
    /**
     * @test
     * @group critical
     * @group landed-cost
     */
    public function additional_costs_are_allocated_proportionally(): void
    {
        $po = Document::factory()->create([
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
        ]);

        // Line 1: $1000 (50% of total)
        $line1 = DocumentLine::factory()->create([
            'document_id' => $po->id,
            'quantity' => 10,
            'unit_price' => 100,
            'line_total' => 1000,
        ]);

        // Line 2: $1000 (50% of total)
        $line2 = DocumentLine::factory()->create([
            'document_id' => $po->id,
            'quantity' => 20,
            'unit_price' => 50,
            'line_total' => 1000,
        ]);

        // Add $200 shipping cost
        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'shipping',
            'amount' => 200,
        ]);

        // Allocate costs
        app(LandedCostService::class)->allocateCosts($po);

        // Verify allocation
        $line1->refresh();
        $line2->refresh();

        // Each line gets 50% of $200 = $100
        $this->assertEquals('100.00', $line1->allocated_costs);
        $this->assertEquals('100.00', $line2->allocated_costs);

        // Landed unit cost = (line_total + allocated) / qty
        $this->assertEquals('110.00', $line1->landed_unit_cost); // (1000 + 100) / 10
        $this->assertEquals('55.00', $line2->landed_unit_cost);  // (1000 + 100) / 20
    }

    /**
     * @test
     * @group critical
     * @group landed-cost
     */
    public function allocation_is_atomic(): void
    {
        $po = Document::factory()->create([
            'type' => DocumentType::PurchaseOrder,
        ]);

        // Create 5 lines
        for ($i = 0; $i < 5; $i++) {
            DocumentLine::factory()->create([
                'document_id' => $po->id,
                'line_total' => 100,
            ]);
        }

        DocumentAdditionalCost::create([
            'document_id' => $po->id,
            'cost_type' => 'shipping',
            'amount' => 100,
        ]);

        // Simulate failure on line 3
        $this->mock(DocumentLine::class)
            ->shouldReceive('save')
            ->times(2)
            ->andReturn(true)
            ->getMock()
            ->shouldReceive('save')
            ->once()
            ->andThrow(new \Exception('Database error'));

        try {
            app(LandedCostService::class)->allocateCosts($po);
            $this->fail('Expected exception');
        } catch (\Exception $e) {
            // Verify no partial allocation
            $allocatedLines = DocumentLine::where('document_id', $po->id)
                ->whereNotNull('allocated_costs')
                ->where('allocated_costs', '!=', '0.00')
                ->count();

            $this->assertEquals(0, $allocatedLines, 'Partial allocation should rollback');
        }
    }
}
```

### 2.4 Fiscal Hash Chain Tests

```php
// tests/Feature/Compliance/FiscalHashChainTest.php

class FiscalHashChainTest extends TestCase
{
    /**
     * @test
     * @group critical
     * @group fiscal
     */
    public function posted_invoice_has_valid_hash(): void
    {
        $invoice = Document::factory()->create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
        ]);

        $posted = app(DocumentPostingService::class)->post($invoice);

        $this->assertNotNull($posted->fiscal_hash);
        $this->assertEquals('SEALED', $posted->fiscal_status->value);
        $this->assertNotNull($posted->chain_sequence);
    }

    /**
     * @test
     * @group critical
     * @group fiscal
     */
    public function hash_chain_is_verifiable(): void
    {
        $company = Company::factory()->create();

        // Post 10 invoices
        for ($i = 0; $i < 10; $i++) {
            $invoice = Document::factory()->create([
                'company_id' => $company->id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Confirmed,
            ]);
            app(DocumentPostingService::class)->post($invoice);
        }

        // Verify chain integrity
        $result = app(FiscalHashService::class)->verifyChain(
            $company->tenant_id,
            $company->id,
            DocumentType::Invoice
        );

        $this->assertTrue($result);
    }

    /**
     * @test
     * @group critical
     * @group fiscal
     */
    public function tampered_invoice_breaks_chain(): void
    {
        $company = Company::factory()->create();

        // Post 5 invoices
        $invoices = [];
        for ($i = 0; $i < 5; $i++) {
            $invoice = Document::factory()->create([
                'company_id' => $company->id,
                'type' => DocumentType::Invoice,
                'status' => DocumentStatus::Confirmed,
            ]);
            $invoices[] = app(DocumentPostingService::class)->post($invoice);
        }

        // Tamper with invoice 3 (bypass model events)
        DB::table('documents')
            ->where('id', $invoices[2]->id)
            ->update(['total' => '9999.99']);

        // Verify chain detects tampering
        $result = app(FiscalHashService::class)->verifyChain(
            $company->tenant_id,
            $company->id,
            DocumentType::Invoice
        );

        $this->assertFalse($result);
    }
}
```

### 2.5 Document Conversion Tests

```php
// tests/Feature/Document/DocumentConversionE2ETest.php

class DocumentConversionE2ETest extends TestCase
{
    /**
     * @test
     * @group critical
     * @group conversion
     */
    public function complete_sales_flow_quote_to_payment(): void
    {
        // Create quote
        $quote = Document::factory()->withLines(3)->create([
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
        ]);

        $conversionService = app(DocumentConversionService::class);

        // Quote → Sales Order
        $order = $conversionService->convertQuoteToOrder($quote);
        $this->assertEquals(DocumentType::SalesOrder, $order->type);
        $this->assertEquals(3, $order->lines->count());

        // Confirm order
        $order->update(['status' => DocumentStatus::Confirmed]);

        // Order → Delivery Note
        $dn = $conversionService->convertOrderToDelivery($order);
        $this->assertEquals(DocumentType::DeliveryNote, $dn->type);

        // Confirm DN
        app(DeliveryNoteService::class)->confirm($dn);

        // Order → Invoice
        $invoice = $conversionService->convertOrderToInvoice($order);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
        $this->assertEquals($order->total, $invoice->total);

        // Post invoice
        $posted = app(DocumentPostingService::class)->post($invoice);
        $this->assertEquals('SEALED', $posted->fiscal_status->value);

        // Create payment
        $payment = Payment::factory()->create([
            'amount' => $invoice->total,
        ]);

        // Allocate payment
        app(PaymentAllocationService::class)->allocate($payment, $invoice);

        // Verify invoice is paid
        $invoice->refresh();
        $this->assertEquals('0.00', $invoice->balance_due);
    }

    /**
     * @test
     * @group critical
     * @group conversion
     */
    public function double_conversion_is_prevented(): void
    {
        $order = Document::factory()->create([
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
        ]);

        $conversionService = app(DocumentConversionService::class);

        // First conversion succeeds
        $invoice1 = $conversionService->convertOrderToInvoice($order);
        $this->assertNotNull($invoice1);

        // Second conversion fails
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already been fully invoiced');
        $conversionService->convertOrderToInvoice($order);
    }
}
```

---

## 3. Test File Structure

```
apps/api/tests/
├── Feature/
│   ├── Inventory/
│   │   ├── StockIntegrityTest.php          # Stock movement atomicity
│   │   ├── WeightedAverageCostTest.php     # WAC calculations
│   │   ├── LandedCostTest.php              # Cost allocation
│   │   ├── StockReservationTest.php        # Reservation system
│   │   └── GoodsReceiptTest.php            # PO receiving
│   ├── Document/
│   │   ├── DocumentConversionE2ETest.php   # Full flow tests
│   │   ├── DocumentPostingTest.php         # Posting & hash chain
│   │   ├── DeliveryNoteServiceTest.php     # DN confirmation
│   │   └── SalesOrderServiceTest.php       # SO confirmation
│   ├── Compliance/
│   │   ├── FiscalHashChainTest.php         # Hash verification
│   │   ├── FiscalHardeningE2ETest.php      # Immutability tests
│   │   └── AuditEventTest.php              # Audit logging
│   └── Treasury/
│       ├── PaymentAllocationTest.php       # Payment flow
│       └── PrepaymentTransferTest.php      # Prepayment handling
├── Unit/
│   ├── Inventory/
│   │   ├── LandedCostServiceTest.php       # Unit calculations
│   │   └── WacCalculationTest.php          # WAC formula
│   └── Document/
│       └── DocumentStatusTransitionTest.php # State machine
└── Browser/
    └── PurchaseOrder/
        └── AdditionalCostsFlowTest.php     # UI integration
```

---

## 4. CI/CD Integration

### 4.1 GitHub Actions Workflow

```yaml
# .github/workflows/critical-tests.yml
name: Critical Tests

on:
  push:
    branches: [main, develop]
  pull_request:
    branches: [main]

jobs:
  critical-tests:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_DB: testing
          POSTGRES_USER: testing
          POSTGRES_PASSWORD: testing
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, bcmath

      - name: Install dependencies
        run: |
          cd apps/api
          composer install --no-interaction

      - name: Run Critical Tests
        run: |
          cd apps/api
          php artisan test --group=critical --stop-on-failure
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_DATABASE: testing
          DB_USERNAME: testing
          DB_PASSWORD: testing

      - name: Run Tier 1 Tests (Must Pass)
        run: |
          cd apps/api
          php artisan test --group=stock,wac,landed-cost,fiscal,conversion

  all-tests:
    runs-on: ubuntu-latest
    needs: critical-tests

    steps:
      - name: Run Full Test Suite
        run: |
          cd apps/api
          php artisan test --coverage --min=80
```

### 4.2 PHPUnit Configuration

```xml
<!-- apps/api/phpunit.xml -->
<phpunit>
    <testsuites>
        <testsuite name="Critical">
            <directory>tests/Feature/Inventory</directory>
            <directory>tests/Feature/Compliance</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>

    <groups>
        <include>
            <group>critical</group>
            <group>stock</group>
            <group>wac</group>
            <group>landed-cost</group>
            <group>fiscal</group>
            <group>conversion</group>
        </include>
    </groups>
</phpunit>
```

---

## 5. Monitoring & Alerting

### 5.1 Production Checks

```php
// app/Console/Commands/VerifyDataIntegrity.php

class VerifyDataIntegrity extends Command
{
    protected $signature = 'integrity:verify {--company=}';

    public function handle(): int
    {
        $companies = $this->option('company')
            ? Company::where('id', $this->option('company'))->get()
            : Company::all();

        foreach ($companies as $company) {
            // Check 1: Fiscal hash chain
            if (!$this->verifyHashChain($company)) {
                $this->alert("CRITICAL: Hash chain broken for {$company->name}");
                Notification::send($this->admins, new HashChainBroken($company));
            }

            // Check 2: Stock levels match movements
            if (!$this->verifyStockBalances($company)) {
                $this->alert("WARNING: Stock discrepancy for {$company->name}");
                Notification::send($this->admins, new StockDiscrepancy($company));
            }

            // Check 3: Invoice totals match allocations
            if (!$this->verifyPaymentAllocations($company)) {
                $this->warn("WARNING: Payment allocation mismatch for {$company->name}");
            }
        }

        return 0;
    }
}
```

### 5.2 Scheduled Integrity Checks

```php
// app/Console/Kernel.php

protected function schedule(Schedule $schedule): void
{
    // Run integrity checks daily at 2 AM
    $schedule->command('integrity:verify')
        ->dailyAt('02:00')
        ->emailOutputOnFailure('admin@company.com');

    // Quick hash chain check every hour
    $schedule->command('integrity:verify-hash-chain')
        ->hourly()
        ->withoutOverlapping();
}
```

---

## 6. Implementation Checklist

### Phase 1: Critical Tests (Must Have)

- [ ] `StockIntegrityTest` - Stock atomicity and concurrency
- [ ] `WeightedAverageCostTest` - WAC calculations
- [ ] `LandedCostTest` - Cost allocation
- [ ] `FiscalHashChainTest` - Hash verification
- [ ] `DocumentConversionE2ETest` - Full sales flow

### Phase 2: Extended Coverage

- [ ] `StockReservationTest` - Reservation flow
- [ ] `GoodsReceiptTest` - PO receiving
- [ ] `PaymentAllocationTest` - Payment flow
- [ ] `PrepaymentTransferTest` - Prepayment handling

### Phase 3: CI/CD & Monitoring

- [ ] GitHub Actions workflow
- [ ] PHPUnit group configuration
- [ ] Integrity verification command
- [ ] Scheduled checks
- [ ] Alert notifications

---

## 7. Test Data Factories

```php
// database/factories/DocumentFactory.php

class DocumentFactory extends Factory
{
    public function withLines(int $count = 3): self
    {
        return $this->afterCreating(function (Document $document) use ($count) {
            DocumentLine::factory()
                ->count($count)
                ->create(['document_id' => $document->id]);
        });
    }

    public function withAdditionalCosts(array $costs = []): self
    {
        return $this->afterCreating(function (Document $document) use ($costs) {
            foreach ($costs as $type => $amount) {
                DocumentAdditionalCost::create([
                    'document_id' => $document->id,
                    'cost_type' => $type,
                    'amount' => $amount,
                ]);
            }
        });
    }

    public function asPurchaseOrder(): self
    {
        return $this->state([
            'type' => DocumentType::PurchaseOrder,
        ]);
    }

    public function asSalesOrder(): self
    {
        return $this->state([
            'type' => DocumentType::SalesOrder,
        ]);
    }
}
```

---

## Appendix: Quick Reference

### Run Critical Tests Only
```bash
cd apps/api
php artisan test --group=critical
```

### Run Stock Tests
```bash
php artisan test --filter=Stock
```

### Run Full Suite with Coverage
```bash
php artisan test --coverage --min=80
```

### Verify Production Data Integrity
```bash
php artisan integrity:verify --company=019b0cc8-8540-7119-bd0d-f74ec0740074
```
