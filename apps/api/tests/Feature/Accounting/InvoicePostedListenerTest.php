<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * InvoicePostedListenerTest - TDD RED Phase
 *
 * Tests for the InvoicePosted event listener that triggers GL entry creation.
 *
 * Expected behavior:
 * 1. When InvoicePosted event is dispatched, a listener captures it
 * 2. The listener calls AccountingService::createInvoiceGLEntries()
 * 3. GL entries (journal_entries + journal_lines) are created
 * 4. GL entries have proper debits/credits matching the invoice
 *
 * CRITICAL: These tests MUST fail initially (RED phase).
 * Agent 5B will implement the listener to make them pass (GREEN phase).
 */
class InvoicePostedListenerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $customer;

    private Location $warehouse;

    private Account $receivableAccount;

    private Account $productRevenueAccount;

    private Account $serviceRevenueAccount;

    private Account $vatCollectedAccount;

    private Product $product1;

    private Product $product2;

    private Product $service;

    private AccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Invoice Listener Test Tenant',
            'slug' => 'inv-listener-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invoice Listener Test Company',
            'legal_name' => 'Invoice Listener Test Company LLC',
            'tax_id' => 'TAX-LISTENER-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Setup permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        // Create user with permissions
        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'testuser-listener-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'invoices.view',
            'invoices.create',
            'invoices.post',
            'journal.view',
            'journal.create',
            'journal.post',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        // Set company context
        app(CompanyContext::class)->setCompanyId($this->company->id);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer Listener',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-LISTENER-'.uniqid(),
            'name' => 'Main Warehouse Listener',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Create Chart of Accounts with SystemAccountPurpose
        $this->receivableAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '411',
            'name' => 'Clients - Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $this->productRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '707',
            'name' => 'Vente de marchandises',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $this->serviceRevenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '706',
            'name' => 'Prestations de services',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);

        $this->vatCollectedAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '44571',
            'name' => 'TVA collectée',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        // Create test products
        $this->product1 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD1-LISTENER-'.uniqid(),
            'name' => 'Product 1 - Listener Test',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD2-LISTENER-'.uniqid(),
            'name' => 'Product 2 - Listener Test',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->service = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SRV-LISTENER-'.uniqid(),
            'name' => 'Service - Listener Test',
            'type' => ProductType::Service,
            'selling_price' => '150.00',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Test: InvoicePosted event listener is registered
     *
     * Verifies that the listener for InvoicePosted event is properly
     * registered in the event dispatcher.
     */
    public function test_invoice_posted_listener_is_registered(): void
    {
        // Get the event dispatcher
        $dispatcher = app(\Illuminate\Events\Dispatcher::class);

        // Check that InvoicePosted event has listeners
        $listeners = $dispatcher->getListeners(InvoicePosted::class);

        // ASSERT: At least one listener is registered for InvoicePosted
        $this->assertNotEmpty($listeners, 'InvoicePosted event should have at least one registered listener');

        // ASSERT: Check that the listener count is reasonable (expect at least 1)
        $this->assertGreaterThanOrEqual(1, count($listeners), 'Should have listener(s) for InvoicePosted');
    }

    /**
     * Test: InvoicePosted event triggers GL entry creation
     *
     * When an InvoicePosted event is dispatched, the listener should
     * call AccountingService::createInvoiceGLEntries() to create GL entries.
     *
     * Expected GL Structure:
     * - Invoice: €500 subtotal, €100 tax @ 20%, €600 total
     * - DR: AR (411)         €600.00
     * - CR: Revenue (707)    €500.00
     * - CR: Tax (44571)      €100.00
     */
    public function test_invoice_posted_event_creates_gl_entries(): void
    {
        // Arrange: Create invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '500.00',
                'tax_rate' => '20.00',
                'description' => 'Test product for listener',
            ],
        ]);

        // Ensure we start with no journal entries
        $initialCount = JournalEntry::where('company_id', $this->company->id)->count();

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entries were created
        $journalEntries = JournalEntry::where('company_id', $this->company->id)
            ->where('source_id', $invoice->id)
            ->get();

        $this->assertGreaterThan(
            $initialCount,
            JournalEntry::where('company_id', $this->company->id)->count(),
            'GL entry count should increase after InvoicePosted event'
        );

        $this->assertGreaterThanOrEqual(1, $journalEntries->count(), 'Should have created at least one journal entry');

        // Assert: First journal entry has correct properties
        $entry = $journalEntries->first();
        $this->assertNotNull($entry);
        $this->assertEquals($this->company->id, $entry->company_id);
        $this->assertEquals($this->tenant->id, $entry->tenant_id);
        $this->assertEquals('Document', $entry->source_type);
        $this->assertEquals($invoice->id, $entry->source_id);

        // Assert: Entry has journal lines
        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
        $this->assertGreaterThanOrEqual(2, $lines->count(), 'Should have at least 2 lines (AR debit + Revenue credit + VAT credit)');

        // Assert: AR line exists with debit
        $arLine = $lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertEquals('600.000', $arLine->debit, 'AR should be debited for total invoice amount');
        $this->assertEquals('0.000', $arLine->credit);

        // Assert: Revenue line exists with credit
        $revenueLine = $lines->where('account_id', $this->productRevenueAccount->id)->first();
        $this->assertNotNull($revenueLine, 'Revenue line should exist');
        $this->assertEquals('0.000', $revenueLine->debit);
        $this->assertEquals('500.000', $revenueLine->credit);

        // Assert: VAT line exists with credit
        $vatLine = $lines->where('account_id', $this->vatCollectedAccount->id)->first();
        $this->assertNotNull($vatLine, 'VAT line should exist');
        $this->assertEquals('0.000', $vatLine->debit);
        $this->assertEquals('100.000', $vatLine->credit);
    }

    /**
     * Test: GL entries are balanced (debits = credits) after InvoicePosted
     */
    public function test_invoice_posted_event_creates_balanced_entries(): void
    {
        // Arrange: Create invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '2',
                'unit_price' => '300.00',
                'tax_rate' => '20.00',
                'description' => 'Test for balanced GL',
            ],
        ]);

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entries balance
        $entry = JournalEntry::where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

        $totalDebits = $lines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $lines->sum(fn ($line) => (float) $line->credit);

        $this->assertEquals($totalDebits, $totalCredits, 'Debits must equal credits (balanced entry)');
        // Expected: €600 + €120 = €720 total
        $this->assertEquals(720.00, $totalDebits, 'Total should be €600 + €120 VAT = €720');
    }

    /**
     * Test: Multiple InvoicePosted events create separate GL entries
     *
     * When two InvoicePosted events are dispatched for different invoices,
     * separate GL entries should be created for each.
     */
    public function test_multiple_invoice_posted_events_create_separate_gl_entries(): void
    {
        // Arrange: Create two invoices
        $invoice1 = $this->createInvoice([
            ['product' => $this->product1, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Invoice 1'],
        ]);

        $invoice2 = $this->createInvoice([
            ['product' => $this->product2, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '20.00', 'description' => 'Invoice 2'],
        ]);

        // Act: Dispatch events for both invoices
        $event1 = new InvoicePosted(
            invoiceId: $invoice1->id,
            tenantId: $invoice1->tenant_id,
            companyId: $invoice1->company_id,
            documentNumber: $invoice1->document_number,
            documentType: $invoice1->type->value,
            partnerId: $invoice1->partner_id,
            total: $invoice1->total,
            currency: $invoice1->currency,
            fiscalHash: 'hash1-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        $event2 = new InvoicePosted(
            invoiceId: $invoice2->id,
            tenantId: $invoice2->tenant_id,
            companyId: $invoice2->company_id,
            documentNumber: $invoice2->document_number,
            documentType: $invoice2->type->value,
            partnerId: $invoice2->partner_id,
            total: $invoice2->total,
            currency: $invoice2->currency,
            fiscalHash: 'hash2-'.uniqid(),
            chainSequence: 2,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event1);
        Event::dispatch($event2);

        // Assert: Separate GL entries created
        $entry1 = JournalEntry::where('source_id', $invoice1->id)->first();
        $entry2 = JournalEntry::where('source_id', $invoice2->id)->first();

        $this->assertNotNull($entry1, 'GL entry for invoice 1 should exist');
        $this->assertNotNull($entry2, 'GL entry for invoice 2 should exist');
        $this->assertNotEquals($entry1->id, $entry2->id, 'Each invoice should have separate GL entry');

        // Assert: Entry 1 has correct AR amount
        $ar1 = JournalLine::where('journal_entry_id', $entry1->id)
            ->where('account_id', $this->receivableAccount->id)
            ->first();
        $this->assertEquals('120.000', $ar1->debit, 'Invoice 1 AR should be €100 + €20 VAT = €120');

        // Assert: Entry 2 has correct AR amount
        $ar2 = JournalLine::where('journal_entry_id', $entry2->id)
            ->where('account_id', $this->receivableAccount->id)
            ->first();
        $this->assertEquals('240.000', $ar2->debit, 'Invoice 2 AR should be €200 + €40 VAT = €240');
    }

    /**
     * Test: InvoicePosted event with multiple tax rates creates correct GL entries
     *
     * When an invoice has lines with different tax rates, the listener
     * should create separate VAT lines for each rate.
     */
    public function test_invoice_posted_event_with_multiple_tax_rates(): void
    {
        // Arrange: Create invoice with 2 different tax rates
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Product with 20% VAT',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '5.50',
                'description' => 'Product with 5.5% VAT',
            ],
        ]);

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entry created
        $entry = JournalEntry::where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

        // Assert: VAT lines created
        $vatLines = $lines->where('account_id', $this->vatCollectedAccount->id);
        $this->assertGreaterThanOrEqual(1, $vatLines->count(), 'Should have VAT line(s)');

        // Assert: Total VAT is correct
        // 20% of €100 = €20
        // 5.5% of €100 = €5.50
        // Total VAT = €25.50
        $totalVat = $vatLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(25.50, $totalVat, 'Total VAT should be €20 + €5.50 = €25.50');

        // Assert: Entry is balanced
        $totalDebits = $lines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $lines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals($totalDebits, $totalCredits, 'Entry should be balanced');
    }

    /**
     * Test: InvoicePosted listener handles invoice with no tax correctly
     */
    public function test_invoice_posted_event_with_zero_tax(): void
    {
        // Arrange: Create tax-exempt invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '0.00',
                'description' => 'Tax-exempt item',
            ],
        ]);

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entry created
        $entry = JournalEntry::where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

        // Assert: No VAT lines for zero-tax invoice
        $vatLines = $lines->where('account_id', $this->vatCollectedAccount->id);
        $totalVat = $vatLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(0.00, $totalVat, 'No VAT should be recorded for zero-tax invoice');

        // Assert: AR line reflects invoice total
        $arLine = $lines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertEquals('100.000', $arLine->debit, 'AR should be €100 (no tax)');
    }

    /**
     * Test: GL entry description includes invoice number
     *
     * For audit trail purposes, the GL entry description should
     * reference the invoice document number.
     */
    public function test_invoice_posted_event_gl_entry_includes_invoice_number(): void
    {
        // Arrange: Create invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Test',
            ],
        ]);

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entry includes invoice number in description
        $entry = JournalEntry::where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $this->assertStringContainsString(
            $invoice->document_number,
            $entry->description,
            'Entry description should include invoice number for audit trail'
        );
    }

    /**
     * Test: InvoicePosted listener distinguishes product vs service revenue
     *
     * When an invoice contains both products and services, the listener
     * should create separate revenue lines for each type.
     */
    public function test_invoice_posted_event_distinguishes_product_and_service_revenue(): void
    {
        // Arrange: Create invoice with product and service
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Physical product',
            ],
            [
                'product' => $this->service,
                'quantity' => '1',
                'unit_price' => '150.00',
                'tax_rate' => '20.00',
                'description' => 'Service labor',
            ],
        ]);

        // Act: Dispatch InvoicePosted event
        $event = new InvoicePosted(
            invoiceId: $invoice->id,
            tenantId: $invoice->tenant_id,
            companyId: $invoice->company_id,
            documentNumber: $invoice->document_number,
            documentType: $invoice->type->value,
            partnerId: $invoice->partner_id,
            total: $invoice->total,
            currency: $invoice->currency,
            fiscalHash: 'test-hash-'.uniqid(),
            chainSequence: 1,
            postedAt: now()->toIso8601String(),
        );

        Event::dispatch($event);

        // Assert: GL entry created
        $entry = JournalEntry::where('source_id', $invoice->id)->first();
        $this->assertNotNull($entry);

        $lines = JournalLine::where('journal_entry_id', $entry->id)->get();
        $accountIds = $lines->pluck('account_id')->unique();

        // Assert: Both product and service revenue accounts are used
        $this->assertTrue(
            $accountIds->contains($this->productRevenueAccount->id),
            'Should use Product Revenue account (707)'
        );

        $this->assertTrue(
            $accountIds->contains($this->serviceRevenueAccount->id),
            'Should use Service Revenue account (706)'
        );

        // Assert: Product revenue credit
        $productRevenueLine = $lines->where('account_id', $this->productRevenueAccount->id)->first();
        $this->assertEquals('100.000', $productRevenueLine->credit, 'Product revenue should be €100');

        // Assert: Service revenue credit
        $serviceRevenueLine = $lines->where('account_id', $this->serviceRevenueAccount->id)->first();
        $this->assertEquals('150.000', $serviceRevenueLine->credit, 'Service revenue should be €150');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Create a posted invoice with lines
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createInvoice(array $lines): Document
    {
        // Calculate totals
        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $line) {
            $lineSubtotal = bcmul($line['quantity'], $line['unit_price'], 2);
            $lineTax = bcmul($lineSubtotal, bcdiv($line['tax_rate'], '100', 4), 2);

            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
            $taxAmount = bcadd($taxAmount, $lineTax, 2);
        }

        $total = bcadd($subtotal, $taxAmount, 2);

        // Create invoice
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-LISTENER-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);

        // Create lines
        $lineNumber = 1;
        foreach ($lines as $lineData) {
            $lineSubtotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);

            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $invoice->id,
                'product_id' => $lineData['product']->id,
                'line_number' => $lineNumber++,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'],
                'line_total' => $lineSubtotal,
            ]);
        }

        return $invoice->fresh(['lines']);
    }
}
