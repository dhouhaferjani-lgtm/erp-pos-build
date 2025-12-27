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
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * InvoiceGLIntegrationTest - TDD RED Phase
 *
 * Tests for invoice GL entry creation following strict TDD principles.
 *
 * Expected behavior when invoice is posted:
 * 1. Create a JournalEntry record linked to the invoice
 * 2. Create JournalLine records with proper debits/credits:
 *    - DR: Accounts Receivable (411) = Invoice total
 *    - CR: Revenue (707) = Line subtotals (one per line or grouped by tax rate)
 *    - CR: Tax Payable (44571) = Tax amounts (grouped by tax rate)
 * 3. Ensure total debits = total credits (balanced entry)
 * 4. Handle multiple tax rates correctly
 * 5. Use correct account codes via SystemAccountPurpose
 *
 * CRITICAL: These tests MUST fail initially (RED phase).
 * Agent 4B will implement the functionality to make them pass (GREEN phase).
 */
class InvoiceGLIntegrationTest extends TestCase
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
            'name' => 'Invoice GL Test Tenant',
            'slug' => 'inv-gl-test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invoice GL Test Company',
            'legal_name' => 'Invoice GL Test Company LLC',
            'tax_id' => 'TAX-GL-TEST-'.uniqid(),
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
            'email' => 'testuser-gl-'.uniqid().'@example.com',
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
            'name' => 'Test Customer GL',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GL-'.uniqid(),
            'name' => 'Main Warehouse GL',
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
            'sku' => 'PROD1-'.uniqid(),
            'name' => 'Product 1 - Standard VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD2-'.uniqid(),
            'name' => 'Product 2 - Reduced VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->service = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SRV1-'.uniqid(),
            'name' => 'Service - Labor',
            'type' => ProductType::Service,
            'selling_price' => '150.00',
            'is_active' => true,
        ]);

        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Test: Complete GL entries created for invoice posting
     *
     * Expected GL Structure:
     * Invoice: €1000 subtotal, €127.50 tax, €1127.50 total
     * - Line 1: €500.00 @ 20% VAT = €100.00 tax
     * - Line 2: €500.00 @ 5.5% VAT = €27.50 tax
     *
     * Expected Journal Entry:
     * - DR: AR (411)            €1127.50
     * - CR: Revenue (707)       €500.00    (Line 1)
     * - CR: Revenue (707)       €500.00    (Line 2)
     * - CR: Tax Payable (44571) €100.00    (20% VAT)
     * - CR: Tax Payable (44571) €27.50     (5.5% VAT)
     */
    public function test_invoice_posting_creates_complete_gl_entries(): void
    {
        // Create invoice with 2 lines, different tax rates
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '500.00',
                'tax_rate' => '20.00', // Standard VAT
                'description' => 'Product 1 with 20% VAT',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '500.00',
                'tax_rate' => '5.50', // Reduced VAT
                'description' => 'Product 2 with 5.5% VAT',
            ],
        ]);

        // ACT: Call the method that should create GL entries
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);

        // ASSERT: Journal Entry created
        $this->assertNotNull($journalEntryId);
        $journalEntry = JournalEntry::find($journalEntryId);
        $this->assertNotNull($journalEntry, 'JournalEntry should be created');
        $this->assertEquals($this->company->id, $journalEntry->company_id);
        $this->assertEquals($this->tenant->id, $journalEntry->tenant_id);
        $this->assertEquals('Document', $journalEntry->source_type);
        $this->assertEquals($invoice->id, $journalEntry->source_id);

        // ASSERT: Journal Lines created
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();
        $this->assertGreaterThanOrEqual(4, $journalLines->count(), 'Should have at least 4 lines: 1 AR + 2 Revenue + 2 Tax (or grouped)');

        // ASSERT: AR debit line
        $arLine = $journalLines->where('account_id', $this->receivableAccount->id)->first();
        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertEquals('1127.50', $arLine->debit, 'AR should be debited for total invoice amount');
        $this->assertEquals('0.00', $arLine->credit);

        // ASSERT: Revenue credit lines (should match line subtotals)
        $revenueLines = $journalLines->where('account_id', $this->productRevenueAccount->id);
        $totalRevenueCredit = $revenueLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(1000.00, $totalRevenueCredit, 'Total revenue credits should equal subtotal');

        // ASSERT: VAT credit lines
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);
        $this->assertGreaterThanOrEqual(1, $vatLines->count(), 'Should have VAT line(s)');
        $totalVatCredit = $vatLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(127.50, $totalVatCredit, 'Total VAT credits should equal tax amount');
    }

    /**
     * Test: GL entry is balanced (total debits = total credits)
     */
    public function test_invoice_gl_entries_are_balanced(): void
    {
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '2',
                'unit_price' => '300.00',
                'tax_rate' => '20.00',
                'description' => 'Test product',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);

        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);

        $this->assertEquals($totalDebits, $totalCredits, 'Debits must equal credits (balanced entry)');
        $this->assertEquals(720.00, $totalDebits, 'Total should be €600 + €120 VAT = €720');
    }

    /**
     * Test: Multiple tax rates handled correctly
     *
     * Expected:
     * - 3 lines with different tax rates: 20%, 10%, 5.5%
     * - Each tax rate should have its own VAT credit line
     */
    public function test_invoice_gl_includes_all_tax_rates(): void
    {
        // Create invoice with 3 different tax rates
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Standard VAT 20%',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '10.00',
                'description' => 'Intermediate VAT 10%',
            ],
            [
                'product' => $this->product2,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '5.50',
                'description' => 'Reduced VAT 5.5%',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Check VAT lines
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);

        // Expected VAT amounts:
        // 20% of 100 = 20.00
        // 10% of 100 = 10.00
        // 5.5% of 100 = 5.50
        // Total VAT = 35.50

        $totalVat = $vatLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(35.50, $totalVat, 'Total VAT should be sum of all tax rates');

        // Should have at least 1 VAT line (may be grouped or separate per rate)
        $this->assertGreaterThanOrEqual(1, $vatLines->count());
    }

    /**
     * Test: Correct account codes used via SystemAccountPurpose
     */
    public function test_invoice_gl_uses_correct_account_codes(): void
    {
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Test',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Verify correct accounts are used
        $accountIds = $journalLines->pluck('account_id')->unique();

        // Should include AR account (411)
        $this->assertTrue($accountIds->contains($this->receivableAccount->id), 'Should use AR account');

        // Should include Revenue account (707)
        $this->assertTrue($accountIds->contains($this->productRevenueAccount->id), 'Should use Product Revenue account');

        // Should include VAT account (44571)
        $this->assertTrue($accountIds->contains($this->vatCollectedAccount->id), 'Should use VAT Collected account');
    }

    /**
     * Test: Service revenue uses different account than product revenue
     */
    public function test_invoice_gl_distinguishes_product_and_service_revenue(): void
    {
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
                'description' => 'Service',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Should have both product revenue and service revenue accounts
        $accountIds = $journalLines->pluck('account_id')->unique();

        $this->assertTrue(
            $accountIds->contains($this->productRevenueAccount->id),
            'Should use Product Revenue account (707)'
        );

        $this->assertTrue(
            $accountIds->contains($this->serviceRevenueAccount->id),
            'Should use Service Revenue account (706)'
        );
    }

    /**
     * Test: Zero tax invoice (tax-exempt)
     */
    public function test_invoice_gl_handles_zero_tax_correctly(): void
    {
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '0.00',
                'description' => 'Tax-exempt product',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $journalLines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        // Should NOT have VAT lines for zero tax
        $vatLines = $journalLines->where('account_id', $this->vatCollectedAccount->id);
        $totalVat = $vatLines->sum(fn ($line) => (float) $line->credit);

        $this->assertEquals(0.00, $totalVat, 'No VAT should be recorded for zero tax rate');

        // Should still be balanced
        $totalDebits = $journalLines->sum(fn ($line) => (float) $line->debit);
        $totalCredits = $journalLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals($totalDebits, $totalCredits);
    }

    /**
     * Test: Entry description includes invoice number
     */
    public function test_invoice_gl_entry_description_includes_invoice_number(): void
    {
        $invoice = $this->createInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Test',
            ],
        ]);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $journalEntry = JournalEntry::find($journalEntryId);

        $this->assertStringContainsString(
            $invoice->document_number,
            $journalEntry->description,
            'Entry description should include invoice number for audit trail'
        );
    }

    /**
     * Test: Multiple invoices create separate GL entries
     */
    public function test_multiple_invoices_create_separate_gl_entries(): void
    {
        $invoice1 = $this->createInvoice([
            ['product' => $this->product1, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Invoice 1'],
        ]);

        $invoice2 = $this->createInvoice([
            ['product' => $this->product2, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '20.00', 'description' => 'Invoice 2'],
        ]);

        $entry1Id = $this->accountingService->createInvoiceGLEntries($invoice1);
        $entry2Id = $this->accountingService->createInvoiceGLEntries($invoice2);

        $this->assertNotEquals($entry1Id, $entry2Id, 'Each invoice should create a separate GL entry');

        $entry1 = JournalEntry::find($entry1Id);
        $entry2 = JournalEntry::find($entry2Id);

        $this->assertEquals($invoice1->id, $entry1->source_id);
        $this->assertEquals($invoice2->id, $entry2->source_id);
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
            'document_number' => 'INV-GL-'.uniqid(),
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
