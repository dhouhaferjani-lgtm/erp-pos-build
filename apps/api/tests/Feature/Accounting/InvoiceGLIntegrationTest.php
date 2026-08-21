<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnbalancedJournalEntryException;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
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
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
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
#[UsesFrozenSeederFixture]
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

    private Account $roundingIncomeAccount;

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

        // Gate finding I-7 — the chart is the REAL FranceChartOfAccountsSeeder
        // output, not a hand-rolled one. The previous fixture hand-seeded a
        // `SalesStampDutyPayable` account that the PCG seeder never produces, so
        // the entire FR/Generic behaviour of the residual guard was unexercised
        // and a green run proved nothing about it.
        (new FranceChartOfAccountsSeeder)->run($this->company->id, $this->tenant->id);

        $this->receivableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::CustomerReceivable);
        $this->productRevenueAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::ProductRevenue);
        $this->serviceRevenueAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::ServiceRevenue);
        $this->vatCollectedAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatCollected);
        $this->roundingIncomeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SalesRoundingDifferenceIncome);

        // The PCG has no timbre: a French chart must NOT carry a sales stamp-duty
        // account, which is precisely why it needs a rounding-difference account.
        $this->assertNull(
            Account::findByPurpose($this->company->id, SystemAccountPurpose::SalesStampDutyPayable),
            'FranceChartOfAccountsSeeder must not seed a sales stamp-duty account.'
        );

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
        $this->assertEquals('1127.500', $arLine->debit, 'AR should be debited for total invoice amount');
        $this->assertEquals('0.000', $arLine->credit);

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

    /**
     * W-6 D1a, the reviewer's CONCRETE case (gate C-2) — an ordinary two-line
     * French invoice whose only imbalance is per-line tax truncation must POST.
     *
     * `groupTaxByRate()` truncates each line's tax at the currency scale, while
     * `TaxCalculationService` accumulates at scale+1 and truncates once per rate
     * bucket. Two lines of net `12.13` at 20% EUR (scale 2):
     *
     *   GL:     trunc2(2.426) = 2.42  x2  = 4.84
     *   header: trunc2(4.852)          = 4.85
     *   total 29.11  vs  Σcr 24.26 + 4.84 = 29.10   ->  residual +0.01
     *
     * Before the narrowing, this hard-failed on any chart without a 4375 account —
     * i.e. every French invoice with an odd number of truncating lines. Now the
     * 0.01 is booked to the PCG 758 rounding-difference account and the entry
     * balances.
     */
    public function test_a_two_line_french_invoice_with_a_tax_truncation_residual_posts(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-ROUND-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '24.26',
            // Header tax: the per-BUCKET truncation, trunc2(12.13 * 2 * 0.20) = 4.85.
            'tax_amount' => '4.85',
            'total' => '29.11',
            'balance_due' => '29.11',
            'currency' => 'EUR',
        ]);
        foreach ([1, 2] as $lineNumber) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $invoice->id,
                'product_id' => $this->product1->id,
                'line_number' => $lineNumber,
                'description' => 'Truncating line '.$lineNumber,
                'quantity' => '1',
                'unit_price' => '12.13',
                'tax_rate' => '20.00',
                'line_total' => '12.13',
            ]);
        }
        $invoice = $invoice->fresh(['lines']);

        // Pre-flight agrees: this document is postable.
        $this->accountingService->assertDocumentGlIsPostable($invoice);

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $roundingLine = $lines->firstWhere('account_id', $this->roundingIncomeAccount->id);
        $this->assertNotNull($roundingLine, 'the truncation residual must be credited to the rounding-difference account');
        $this->assertSame(0, bccomp((string) $roundingLine->credit, '0.01', 2));
        $this->assertSame(0, bccomp((string) $roundingLine->debit, '0', 2));

        $debits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 2), '0');
        $credits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 2), '0');
        $this->assertSame(0, bccomp($debits, $credits, 2), 'entry must balance');
        $this->assertSame(0, bccomp($debits, '29.11', 2));
    }

    /**
     * W-6 D1a — a POSITIVE residual larger than per-line truncation can explain,
     * on a chart with no document-level charge account, is REFUSED rather than
     * buried in the rounding account.
     *
     * The `1.00` here is a timbre-shaped document-level charge. The PCG has no
     * timbre, so on a French chart it is unexplained money and must not be
     * silently absorbed. (On the Tunisian chart the same shape books to 4375 —
     * asserted in the TN case below.)
     */
    public function test_a_positive_residual_beyond_rounding_tolerance_is_refused_on_a_chart_without_a_timbre_account(): void
    {
        $invoice = $this->documentWithResidual('INV-BIGRESID-', '100.00', '1', '120.00');

        try {
            $this->accountingService->assertDocumentGlIsPostable($invoice);
            $this->fail('a positive residual beyond rounding tolerance must be refused pre-seal');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::ResidualExceedsRoundingTolerance, $e->refusal);
        }
    }

    /**
     * W-6 D1a — the Tunisian chart keeps its existing behaviour EXACTLY: the
     * document-level timbre is credited to the dedicated 4375 liability, not
     * lumped into VAT, not dropped, and at any size.
     *
     * Bug #5A: previously the AR debit carried the timbre (in `total`) with no
     * credit leg, so every TN invoice posted an unbalanced JE. This is the
     * regression guard for that fix under the narrowed residual rules.
     */
    public function test_the_tunisian_chart_still_credits_the_timbre_to_4375_and_balances(): void
    {
        [$company, $partner, $product] = $this->tunisianFixture();

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-TN-STAMP-'.uniqid(),
            'partner_id' => $partner->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '21.000',  // 20 VAT + 1.000 timbre
            'total' => '121.000',
            'balance_due' => '121.000',
            'currency' => 'TND',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'product_id' => $product->id,
            'line_number' => 1,
            'description' => 'Product with VAT + timbre',
            'quantity' => '1',
            'unit_price' => '100.000',
            'tax_rate' => '20.00',
            'line_total' => '100.000',
        ]);
        $invoice = $invoice->fresh(['lines']);

        $stampAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::SalesStampDutyPayable);
        $vatAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::VatCollected);

        $this->accountingService->assertDocumentGlIsPostable($invoice);
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $stampLine = $lines->firstWhere('account_id', $stampAccount->id);
        $this->assertNotNull($stampLine, 'the timbre must reach 4375, at any size');
        $this->assertSame(0, bccomp((string) $stampLine->credit, '1', 3));

        $vat = $lines->where('account_id', $vatAccount->id)
            ->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($vat, '20', 3), 'VAT stays line VAT only');

        $debits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $credits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($debits, $credits, 3));
        $this->assertSame(0, bccomp($debits, '121', 3));
    }

    /**
     * W-6 D1a — a NEGATIVE residual is refused BEFORE the document is sealed.
     *
     * Shape reproduced: the `MTP-DOC-06` probe — header `total` 100.00 (its
     * `tax_amount` was zeroed by the confirm-zeroes-VAT defect closed in
     * `7258a409f`) while its line still carries `tax_rate 20.00`. The posting
     * would debit AR 100.00 and credit 100.00 revenue + 20.00 VAT, a NEGATIVE
     * residual of -20.00 that no absorbing account may take: the header
     * understates its own lines, which is a bug, never a rounding artefact.
     *
     * Gate C-1: this must be a pre-flight refusal (`UnpostableDocumentGlException`,
     * 422 BUSINESS_ERROR) so the document is never sealed — see
     * `DocumentGlPreflightTest` for the end-to-end proof through
     * `DocumentPostingService`.
     */
    public function test_a_negative_residual_is_refused_by_the_preflight(): void
    {
        $invoice = $this->documentWithResidual('INV-UNBAL-', '100.00', '20.00', '100.00');

        try {
            $this->accountingService->assertDocumentGlIsPostable($invoice);
            $this->fail('a negative residual must be refused pre-seal');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::NegativeResidual, $e->refusal);
            $this->assertStringContainsString($invoice->document_number, $e->getMessage());
        }
    }

    /**
     * W-6 D1a — defence in depth. If the pre-flight is ever bypassed (a caller
     * that writes the GL directly), the posting itself still refuses and rolls
     * back: no journal entry, no consumed chain sequence.
     */
    public function test_invoice_gl_refuses_to_post_an_unbalanced_entry(): void
    {
        $entriesBefore = JournalEntry::query()->count();
        $invoice = $this->documentWithResidual('INV-UNBAL-DD-', '100.00', '20.00', '100.00');

        try {
            $this->accountingService->createInvoiceGLEntries($invoice);
            $this->fail('createInvoiceGLEntries() must refuse an unbalanced entry, not post it.');
        } catch (UnbalancedJournalEntryException $e) {
            // Deliberately NOT UnpostableDocumentGlException: reaching the posting
            // means the seal already happened, so a 422 would be a lie. This stays
            // an unmapped RuntimeException — a 500 plus an alert (gate I-6).
            $this->assertStringContainsString($invoice->document_number, $e->getMessage());
        }

        // Fail CLOSED: nothing sealed into the hash chain.
        $this->assertSame($entriesBefore, JournalEntry::query()->count());
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_id', $invoice->id)->count(),
            'No journal entry may survive for an unbalanced invoice posting.'
        );
    }

    /**
     * O-26 RE-PIN (owner ruling 2026-08-21, repo LEDGER row O-26). Posting a
     * document with NO lines is now REFUSED at the pre-flight; what this test
     * still pins is the LEGACY WRITE shape behind that refusal, because
     * `reverseDocumentGl()` has to mirror exactly this when a document posted
     * BEFORE the ruling is cancelled.
     *
     * On the Tunisian chart that shape is: the whole `total` swept into `4375` as
     * if it were collected timbre — nonsense, but BALANCED. (The first cut of the
     * lineless carve-out returned no absorbing account at all, which downgraded
     * that to a ONE-LEGGED, unbalanced, hash-chained entry on every chart —
     * strictly worse, and a regression the L1 lane introduced and then fixed.)
     * `createInvoiceGLEntries()` is called DIRECTLY here on purpose: after O-26
     * no posting path can reach it with a lineless document.
     * `docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md`
     */
    public function test_a_lineless_document_is_refused_at_preflight_but_its_legacy_entry_still_sweeps_to_the_timbre_account(): void
    {
        [$company, $partner] = $this->tunisianFixture();

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-TN-NOLINES-'.uniqid(),
            'partner_id' => $partner->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'total' => '119.000',
            'balance_due' => '119.000',
            'currency' => 'TND',
        ]);
        $invoice = $invoice->fresh(['lines']);
        $this->assertCount(0, $invoice->lines);

        // O-26: the pre-flight now REFUSES this shape, on every chart — including
        // the one where the legacy entry happened to balance.
        try {
            $this->accountingService->assertDocumentGlIsPostable($invoice);
            $this->fail('O-26: a lineless document must be refused at the pre-flight.');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::LinelessDocument, $e->refusal);
        }

        // …and the LEGACY write shape, which only pre-O-26 data can now have, is
        // unchanged — this is what a cancellation of such a document mirrors.
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $stampAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::SalesStampDutyPayable);
        $stampLine = $lines->firstWhere('account_id', $stampAccount->id);
        $this->assertNotNull($stampLine, 'pre-lane behaviour: the whole total is swept to 4375');
        $this->assertSame(0, bccomp((string) $stampLine->credit, '119', 3));

        $debits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->debit, 3), '0');
        $credits = $lines->reduce(fn (string $c, JournalLine $l): string => bcadd($c, (string) $l->credit, 3), '0');
        $this->assertSame(0, bccomp($debits, $credits, 3), 'the entry must still balance, as it did before this lane');
    }

    /**
     * The other half of the same shape, O-26 RE-PIN: on a chart with NO timbre
     * account the pre-flight refuses just the same, and the legacy write behind it
     * is the lone AR leg. It must still not fall back to the rounding-difference
     * account — sweeping an entire invoice total into "écart d'arrondi" would be a
     * silent misstatement, and that fallback must not appear now that the shape is
     * refused either.
     */
    public function test_a_lineless_document_is_refused_and_its_legacy_entry_never_touches_the_rounding_account(): void
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-FR-NOLINES-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
            'balance_due' => '120.00',
            'currency' => 'EUR',
        ]);
        $invoice = $invoice->fresh(['lines']);

        try {
            $this->accountingService->assertDocumentGlIsPostable($invoice);
            $this->fail('O-26: a lineless document must be refused at the pre-flight.');
        } catch (UnpostableDocumentGlException $e) {
            $this->assertSame(GlResidualRefusal::LinelessDocument, $e->refusal);
        }

        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $lines = JournalLine::where('journal_entry_id', $journalEntryId)->get();

        $this->assertNull(
            $lines->firstWhere('account_id', $this->roundingIncomeAccount->id),
            'a whole invoice total must never be booked as a rounding difference'
        );
        $this->assertCount(1, $lines, 'pre-lane behaviour on a chart without 4375: the lone AR leg');
    }

    /**
     * Build a single-line EUR invoice with a known residual.
     *
     * `total − line_total − trunc(line_total x rate)` is the residual under test.
     */
    private function documentWithResidual(
        string $numberPrefix,
        string $lineTotal,
        string $taxRate,
        string $total,
    ): Document {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => $numberPrefix.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Posted,
            'subtotal' => $lineTotal,
            'tax_amount' => bcsub($total, $lineTotal, 2),
            'total' => $total,
            'balance_due' => $total,
            'currency' => 'EUR',
        ]);
        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $invoice->id,
            'product_id' => $this->product1->id,
            'line_number' => 1,
            'description' => 'Residual probe line',
            'quantity' => '1',
            'unit_price' => $lineTotal,
            'tax_rate' => $taxRate,
            'line_total' => $lineTotal,
        ]);

        /** @var Document */
        return $invoice->fresh(['lines']);
    }

    /**
     * A second company on the REAL Tunisian chart, for the timbre case.
     *
     * @return array{0: Company, 1: Partner, 2: Product}
     */
    private function tunisianFixture(): array
    {
        $company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'TN GL Test Company',
            'legal_name' => 'TN GL Test Company SARL',
            'tax_id' => 'TAX-TN-GL-'.uniqid(),
            'country_code' => 'TN',
            'locale' => 'fr_FR',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        (new TunisiaChartOfAccountsSeeder)->run($company->id, $this->tenant->id);

        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'name' => 'TN Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'sku' => 'TNPROD-'.uniqid(),
            'name' => 'TN Product',
            'type' => ProductType::Part,
            'cost_price' => '50.000',
            'selling_price' => '100.000',
            'is_active' => true,
        ]);

        return [$company, $partner, $product];
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
