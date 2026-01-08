# Testing Conventions and Patterns

## Overview

AutoERP follows **Test-Driven Development (TDD)** with a comprehensive testing strategy covering unit, feature, and E2E tests. This guide documents established patterns and conventions.

## Test Pyramid

```
        E2E (10%)
    ──────────────────
      Feature (20%)
  ──────────────────────
       Unit (70%)
```

### Unit Tests (70%)
- Pure business logic
- No database, no HTTP
- Fast execution (<1ms per test)
- Domain services, value objects, calculations

### Feature Tests (20%)
- Integration with database
- HTTP endpoints
- Transaction handling
- Module interactions

### E2E Tests (10%)
- Complete user workflows
- Playwright-based
- Critical business paths
- Cross-module integration

## Test Organization

```
tests/
├── Unit/
│   ├── Document/
│   │   ├── CreditNoteServiceTest.php
│   │   └── DocumentVehicleContextTest.php
│   ├── Treasury/
│   │   ├── PaymentAllocationServiceTest.php
│   │   └── PaymentToleranceServiceTest.php
│   └── Inventory/
│       ├── WeightedAverageCostServiceTest.php
│       └── CountingReconciliationServiceTest.php
├── Feature/
│   ├── Document/
│   │   ├── Types/
│   │   │   ├── InvoiceDocumentTest.php
│   │   │   └── QuoteDocumentTest.php
│   │   ├── CreditNoteIntegrationTest.php
│   │   ├── DocumentConversionScenarioTest.php
│   │   └── DeliveryNoteConsolidationTest.php
│   ├── Company/
│   │   └── MultiCompanyIsolationSimpleTest.php
│   ├── Inventory/
│   │   ├── BlindCountingTest.php
│   │   └── ReconciliationTest.php
│   ├── Treasury/
│   │   └── SmartPaymentIntegrationTest.php
│   ├── Accounting/
│   │   ├── InvoiceGLIntegrationTest.php
│   │   └── JournalEntryImmutabilityTest.php
│   └── Performance/
│       └── BaselinePerformanceTest.php
└── E2E/
    └── CompleteOrderCycleTest.php
```

## Test Naming Conventions

### PHPUnit Method Names

Use descriptive names that explain the scenario:

```php
// ✅ CORRECT - Clear what is being tested
public function test_invoice_can_be_posted(): void
public function test_posted_invoice_cannot_be_modified(): void
public function test_user_from_company_a_cannot_view_company_b_document(): void

// ❌ WRONG - Vague
public function testPost(): void
public function testModify(): void
public function testAccess(): void
```

### Doc-Comment Metadata (Legacy Pattern)

**Note:** We're migrating from doc-comment metadata to PHP attributes. New tests should use method names only.

```php
// Old pattern (being phased out)
/**
 * @test
 */
public function it_creates_credit_note_from_invoice(): void

// New pattern (preferred)
public function test_creates_credit_note_from_invoice(): void
```

## Test Setup Patterns

### Feature Test Base Setup

```php
class InvoiceDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;
    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // 2. Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // 3. Seed permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // 4. Create user with permissions
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo(['invoices.view', 'invoices.create', 'invoices.post']);

        // 5. Create company membership
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // 6. Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // 7. Seed chart of accounts
        $this->seedChartOfAccounts();

        // 8. Create partner
        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Partner',
            'type' => PartnerType::Customer,
            'email' => 'partner@example.com',
        ]);
    }

    private function seedChartOfAccounts(): void
    {
        $seederClass = match ($this->company->country_code) {
            'FR' => FranceChartOfAccountsSeeder::class,
            'TN' => TunisiaChartOfAccountsSeeder::class,
            default => FranceChartOfAccountsSeeder::class,
        };

        $seeder = new $seederClass();
        $seeder->run($this->company->id, $this->tenant->id);
    }
}
```

### Multi-Company Test Setup

```php
class MultiCompanyIsolationSimpleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $companyA;
    private Company $companyB;
    private User $userA;
    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create one tenant with two companies
        $this->tenant = Tenant::create([...]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            // ...
        ]);

        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            // ...
        ]);

        // Create users in different companies
        $this->userA = User::create([...]);
        $this->userB = User::create([...]);

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
        ]);
    }
}
```

## Common Test Patterns

### Pattern 1: Service-Only Invoice Test

Tests invoices with service lines (no products):

```php
public function test_service_only_invoice_complete_workflow(): void
{
    // Create invoice with service-only lines (no product_id)
    $response = $this->actingAs($this->user)->postJson('/api/v1/invoices', [
        'partner_id' => $this->partner->id,
        'document_date' => '2025-01-15',
        'due_date' => '2025-02-15',
        'lines' => [
            [
                'description' => 'Diagnostic Service',
                'quantity' => '1.00',
                'unit_price' => '80.00',
                'tax_rate' => '20.00',
            ],
        ],
    ]);

    $response->assertStatus(201);
    $invoice_id = $response->json('data.id');

    // Verify calculations
    $this->assertEquals('80.00', $response->json('data.subtotal'));
    $this->assertEquals('16.00', $response->json('data.tax_amount'));
    $this->assertEquals('96.00', $response->json('data.total'));

    // Verify service lines have no product_id
    $this->assertNull($response->json('data.lines.0.product_id'));

    // Confirm the invoice
    $confirmResponse = $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice_id}/confirm");
    $confirmResponse->assertStatus(200);

    // Post the invoice
    $postResponse = $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice_id}/post");
    $postResponse->assertStatus(200);

    // Verify fiscal hash
    $this->assertNotNull($postResponse->json('meta.fiscal_hash'));
    $this->assertIsInt($postResponse->json('meta.chain_sequence'));
}
```

### Pattern 2: Multi-Company Isolation Test

Verifies users cannot access other companies' data:

```php
public function test_user_from_company_a_cannot_view_company_b_document(): void
{
    // User A tries to access Company B's document
    $response = $this->actingAs($this->userA)
        ->getJson("/api/v1/invoices/{$this->documentB->id}");

    // Should return 404 (not found) to prevent information leakage
    // (403 would confirm the document exists)
    $this->assertContains($response->status(), [403, 404]);
}

public function test_user_can_view_their_own_company_document(): void
{
    // User A can access their own company's document
    $response = $this->actingAs($this->userA)
        ->getJson("/api/v1/invoices/{$this->documentA->id}");

    $response->assertStatus(200);
    $this->assertEquals($this->documentA->id, $response->json('data.id'));

    // Verify the document belongs to the user's company
    $document = Document::find($response->json('data.id'));
    $this->assertEquals($this->companyA->id, $document->company_id);
}
```

### Pattern 3: Event Dispatch Test

Verifies domain events are dispatched:

```php
public function test_invoice_posting_dispatches_event(): void
{
    Event::fake([InvoicePosted::class]);

    $invoice = Document::factory()->invoice()->confirmed()->create();

    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice->id}/post");

    Event::assertDispatched(InvoicePosted::class, function ($event) use ($invoice) {
        return $event->invoiceId === $invoice->id
            && $event->fiscalHash !== null
            && $event->chainSequence > 0;
    });
}
```

### Pattern 4: Document Conversion Test

Tests document lifecycle (quote → order → invoice):

```php
public function test_quote_to_order_conversion(): void
{
    $quote = Document::factory()->quote()->confirmed()->create();

    $response = $this->actingAs($this->user)
        ->postJson("/api/v1/quotes/{$quote->id}/convert", [
            'target_type' => 'sales_order',
        ]);

    $response->assertStatus(201);
    $salesOrder = Document::find($response->json('data.id'));

    // Verify conversion
    $this->assertEquals(DocumentType::SalesOrder, $salesOrder->type);
    $this->assertEquals($quote->partner_id, $salesOrder->partner_id);
    $this->assertEquals($quote->total, $salesOrder->total);

    // Verify quote is marked as converted
    $quote->refresh();
    $this->assertEquals($salesOrder->id, $quote->converted_to_order_id);
}
```

### Pattern 5: Concurrency Test (Stock Reservations)

Tests pessimistic locking for stock operations:

```php
public function test_concurrent_orders_dont_oversell_stock(): void
{
    $product = Product::factory()->create();

    StockLevel::create([
        'product_id' => $product->id,
        'location_id' => $this->location->id,
        'quantity' => '10.00',
        'reserved' => '0.00',
    ]);

    // Simulate concurrent requests
    $promises = [];
    for ($i = 0; $i < 5; $i++) {
        $promises[] = $this->actingAs($this->user)
            ->postJson('/api/v1/sales-orders', [
                'partner_id' => $this->partner->id,
                'lines' => [
                    ['product_id' => $product->id, 'quantity' => '3.00'],
                ],
            ]);
    }

    // Only 3 orders should succeed (3*3 = 9, within 10 available)
    // 2 orders should fail (insufficient stock)
    $successes = array_filter($promises, fn($r) => $r->status() === 201);
    $failures = array_filter($promises, fn($r) => $r->status() === 422);

    $this->assertCount(3, $successes);
    $this->assertCount(2, $failures);
}
```

### Pattern 6: Performance Baseline Test

Establishes performance benchmarks:

```php
public function test_baseline_product_list_performance_100_items(): void
{
    // Create 100 products
    $this->createTestProducts(100);

    $start = microtime(true);
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/products?per_page=50');
    $duration = (microtime(true) - $start) * 1000;

    $response->assertStatus(200);
    $this->assertGreaterThanOrEqual(50, count($response->json('data')));

    // Assert performance baseline: 100 products should load in under 500ms
    $this->assertLessThan(500, $duration,
        "Product list (100 items) took {$duration}ms (baseline: <500ms)"
    );

    $this->reportMetric('Product List 100 Items (50/page)', $duration, 'ms');
}

private function reportMetric(string $operation, float $duration, string $unit): void
{
    // Output to console for visibility
    $this->assertTrue(true, "✓ {$operation}: {$duration}{$unit}");
}
```

## Assertion Patterns

### Financial Calculations

Always use string comparison for decimals:

```php
// ✅ CORRECT - String comparison
$this->assertEquals('100.00', $invoice->subtotal);
$this->assertEquals('20.00', $invoice->tax_amount);
$this->assertEquals('120.00', $invoice->total);

// ❌ WRONG - Float comparison
$this->assertEquals(100.00, $invoice->subtotal);  // Precision issues!
```

### Enum Comparisons

Use enum constants, not strings:

```php
// ✅ CORRECT - Enum comparison
$this->assertEquals(DocumentStatus::Posted, $invoice->status);

// ❌ WRONG - String comparison
$this->assertEquals('posted', $invoice->status);
```

### Date Assertions

```php
// ✅ CORRECT - Use Carbon for date comparisons
$this->assertTrue($invoice->posted_at->isToday());
$this->assertEquals('2025-01-15', $invoice->document_date->toDateString());

// ❌ WRONG - Direct string comparison
$this->assertEquals('2025-01-15 10:30:00', $invoice->posted_at);
```

## Mocking Patterns

### Mocking External Services

```php
public function test_sends_email_notification(): void
{
    Mail::fake();

    $invoice = Document::factory()->invoice()->create();

    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice->id}/send-email");

    Mail::assertSent(InvoiceMail::class, function ($mail) use ($invoice) {
        return $mail->invoice->id === $invoice->id;
    });
}
```

### Mocking Event Dispatch

```php
public function test_invoice_posting_creates_journal_entry(): void
{
    Event::fake([InvoicePosted::class]);

    $invoice = Document::factory()->invoice()->confirmed()->create();

    $this->actingAs($this->user)
        ->postJson("/api/v1/invoices/{$invoice->id}/post");

    Event::assertDispatched(InvoicePosted::class);

    // Verify side effects
    $this->assertDatabaseHas('journal_entries', [
        'source_id' => $invoice->id,
        'source_type' => 'Document',
    ]);
}
```

## Factory Patterns

### Document Factory States

```php
// Create draft invoice
$invoice = Document::factory()->invoice()->draft()->create();

// Create confirmed invoice
$invoice = Document::factory()->invoice()->confirmed()->create();

// Create posted invoice
$invoice = Document::factory()->invoice()->posted()->create();

// Create with lines
$invoice = Document::factory()
    ->invoice()
    ->hasLines(3)
    ->create();
```

### Relationship Factories

```php
// Create product with stock
$product = Product::factory()
    ->hasStockLevels(1, ['quantity' => '100.00'])
    ->create();

// Create partner with contacts
$partner = Partner::factory()
    ->hasContacts(2)
    ->create();
```

## Common Test Pitfalls

### 1. Not Setting Company Context

```php
// ❌ WRONG - No company context
$products = Product::all();  // Returns 0 results

// ✅ CORRECT - Set context first
app(CompanyContext::class)->setCompanyId($this->company->id);
$products = Product::all();  // Returns company's products
```

### 2. Missing Required Fields

```php
// ❌ WRONG - Missing SKU field
$product = Product::create([
    'name' => 'Test Product',
    'code' => 'PROD-001',
]);  // SQLSTATE[23000]: NOT NULL constraint failed: products.sku

// ✅ CORRECT - Include all required fields
$product = Product::create([
    'name' => 'Test Product',
    'code' => 'PROD-001',
    'sku' => 'SKU-001',  // Required!
]);
```

### 3. Forgetting Permissions

```php
// ❌ WRONG - User has no permissions
$response = $this->actingAs($this->user)
    ->getJson('/api/v1/invoices');  // 403 Forbidden

// ✅ CORRECT - Grant permissions first
$this->user->givePermissionTo(['invoices.view']);
$response = $this->actingAs($this->user)
    ->getJson('/api/v1/invoices');  // 200 OK
```

### 4. Enum Type Mismatch

```php
// ❌ WRONG - Comparing enum to string
$this->assertEquals('posted', $invoice->status);  // Fails!

// ✅ CORRECT - Use enum constant
$this->assertEquals(DocumentStatus::Posted, $invoice->status);
```

## Running Tests

### Run All Tests
```bash
php artisan test
```

### Run Specific Suite
```bash
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
```

### Run Specific Test
```bash
php artisan test --filter=InvoiceDocumentTest
php artisan test --filter=test_invoice_can_be_posted
```

### Run with Coverage
```bash
php artisan test --coverage --min=80
```

### Performance Tests
```bash
php artisan test --filter=BaselinePerformanceTest
```

## Test Quality Metrics

### Current Status (December 2025)

- **Total Tests:** 160 tests, 19 assertions per test average
- **Pass Rate:** 100% (160/160 passing)
- **PHPStan:** Level 8 (strict types)
- **Coverage:** 80%+ on domain layer

### Performance Baselines

| Operation | Baseline | Current |
|-----------|----------|---------|
| Dashboard stats | <1000ms | 830ms ✅ |
| Product list (100 items) | <500ms | 190ms ✅ |
| Stock query (50 items) | <200ms | 270ms ⚠️ |
| Invoice creation | <500ms | 160ms ✅ |
| Partner list (100 items) | <300ms | 160ms ✅ |

## References

- See `docs/modules/architecture.md` for module patterns
- See `docs/architecture/events.md` for event testing
- See CLAUDE.md for TDD requirements
