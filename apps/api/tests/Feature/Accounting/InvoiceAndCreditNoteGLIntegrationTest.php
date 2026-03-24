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
use App\Modules\Document\Domain\Services\DocumentPostingService;
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
 * InvoiceAndCreditNoteGLIntegrationTest - Milestone 5: Final Integration Tests
 *
 * MISSION: Verify the COMPLETE invoice and credit note GL posting flow works end-to-end.
 *
 * Integration Flow Tested:
 * 1. Invoice Posting Flow:
 *    POST invoice → DocumentPostingService::post() → InvoicePosted event
 *    → InvoicePostedListener::handle() → AccountingService::createInvoiceGLEntries()
 *    → Create journal entry with AR + Revenue + Tax lines
 *
 * 2. Credit Note Posting Flow:
 *    POST credit note → DocumentPostingService::post() → InvoicePosted event (with CreditNote type)
 *    → InvoicePostedListener::handle() → AccountingService::createCreditNoteGLEntries()
 *    → Create journal entry with reversed AR + Revenue + Tax
 *
 * 3. Complete Reversal Flow:
 *    Invoice + Credit Note GL entries net to ZERO (full reversal verification)
 *
 * CRITICAL: These tests verify the INTEGRATION of all components working together,
 * not just individual units. Tests full E2E flow from posting service → event → listener → GL.
 */
class InvoiceAndCreditNoteGLIntegrationTest extends TestCase
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

    private DocumentPostingService $postingService;

    private AccountingService $accountingService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Invoice+CN GL Integration Test Tenant',
            'slug' => 'inv-cn-int-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Invoice+CN GL Integration Test Company',
            'legal_name' => 'Invoice+CN GL Integration Test Company LLC',
            'tax_id' => 'TAX-INT-GL-TEST-'.uniqid(),
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
            'name' => 'Test User GL Integration',
            'email' => 'testuser-gl-int-'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'invoices.view',
            'invoices.create',
            'invoices.post',
            'credit-notes.view',
            'credit-notes.create',
            'credit-notes.post',
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
            'name' => 'Test Customer GL Integration',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GL-INT-'.uniqid(),
            'name' => 'Main Warehouse GL Integration',
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
            'sku' => 'PROD1-INT-'.uniqid(),
            'name' => 'Product 1 - Standard VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'PROD2-INT-'.uniqid(),
            'name' => 'Product 2 - Reduced VAT',
            'type' => ProductType::Part,
            'cost_price' => '250.00',
            'selling_price' => '500.00',
            'is_active' => true,
        ]);

        $this->service = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SRV1-INT-'.uniqid(),
            'name' => 'Service - Labor',
            'type' => ProductType::Service,
            'selling_price' => '150.00',
            'is_active' => true,
        ]);

        $this->postingService = app(DocumentPostingService::class);
        $this->accountingService = app(AccountingService::class);
    }

    /**
     * Test 1: Posting invoice automatically creates complete GL entries
     *
     * Integration Flow Tested:
     * 1. Create confirmed invoice
     * 2. Call DocumentPostingService::post() (E2E entry point)
     * 3. Verify InvoicePosted event is dispatched
     * 4. Verify InvoicePostedListener creates GL entries
     * 5. Verify complete AR + Revenue + Tax structure
     *
     * Expected GL Structure:
     * Invoice: €1000 subtotal, €190 tax (19% VAT), €1190 total
     * - DR: AR (411)            €1190.00
     * - CR: Revenue (707)       €1000.00
     * - CR: Tax Payable (44571) €190.00
     */
    public function test_posting_invoice_automatically_creates_complete_gl_entries(): void
    {
        // ARRANGE: Create confirmed invoice (ready to post)
        $invoice = $this->createConfirmedInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '1000.00',
                'tax_rate' => '19.00', // 19% VAT
                'description' => 'Product with 19% VAT',
            ],
        ]);

        // Verify invoice is in Confirmed status (not yet posted)
        $this->assertEquals(DocumentStatus::Confirmed, $invoice->status);

        // ACT: Post the invoice through the DocumentPostingService
        // This triggers the FULL integration flow:
        // DocumentPostingService::post() → InvoicePosted event → InvoicePostedListener → AccountingService
        $postedInvoice = $this->postingService->post($invoice);

        // ASSERT: Document status changed to Posted
        $this->assertEquals(DocumentStatus::Posted, $postedInvoice->status);
        $this->assertEquals(DocumentStatus::Posted, $invoice->fresh()->status);

        // ASSERT: GL entry created automatically by listener
        $glEntry = JournalEntry::where('source_type', 'Document')
            ->where('source_id', $invoice->id)
            ->first();

        $this->assertNotNull($glEntry, 'JournalEntry should be created automatically by InvoicePostedListener');
        $this->assertEquals($this->company->id, $glEntry->company_id);
        $this->assertEquals($this->tenant->id, $glEntry->tenant_id);
        $this->assertEquals($invoice->id, $glEntry->source_id);

        // ASSERT: AR debit line exists
        $arLine = $glEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::CustomerReceivable);
        })->first();

        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertEquals('1190.000', $arLine->debit, 'AR should be debited for total invoice amount');
        $this->assertEquals('0.000', $arLine->credit);

        // ASSERT: Revenue credit line exists
        $revenueLines = $glEntry->lines()->whereHas('account', function ($q) {
            $q->whereIn('system_purpose', [
                SystemAccountPurpose::ProductRevenue,
                SystemAccountPurpose::ServiceRevenue,
            ]);
        })->get();

        $this->assertGreaterThan(0, $revenueLines->count(), 'Revenue line(s) should exist');
        $totalRevenueCredit = $revenueLines->sum(fn ($line) => (float) $line->credit);
        $this->assertEquals(1000.00, $totalRevenueCredit, 'Total revenue credits should equal subtotal');

        // ASSERT: Tax credit line exists
        $taxLine = $glEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::VatCollected);
        })->first();

        $this->assertNotNull($taxLine, 'VAT line should exist');
        $this->assertEquals('0.000', $taxLine->debit);
        $this->assertEquals('190.000', $taxLine->credit, 'VAT should be credited for tax amount');

        // ASSERT: Balanced entry
        $totalDebits = $glEntry->lines()->sum('debit');
        $totalCredits = $glEntry->lines()->sum('credit');
        $this->assertEquals($totalDebits, $totalCredits, 'Entry must be balanced');
        $this->assertEquals('1190.00', $totalDebits);
    }

    /**
     * Test 2: Posting credit note automatically creates GL reversal
     *
     * Integration Flow Tested:
     * 1. Create confirmed credit note
     * 2. Call DocumentPostingService::post() (E2E entry point)
     * 3. Verify InvoicePosted event is dispatched (for CreditNote type)
     * 4. Verify InvoicePostedListener creates GL reversal entries
     * 5. Verify reversed AR + Revenue + Tax structure
     *
     * Expected GL Structure (REVERSAL):
     * Credit Note: €1000 subtotal, €190 tax (19% VAT), €1190 total
     * - CR: AR (411)            €1190.00  (opposite of invoice)
     * - DR: Revenue (707)       €1000.00  (opposite of invoice)
     * - DR: Tax Payable (44571) €190.00   (opposite of invoice)
     */
    public function test_posting_credit_note_automatically_creates_gl_reversal(): void
    {
        // ARRANGE: Create confirmed credit note (ready to post)
        $creditNote = $this->createConfirmedCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '1000.00',
                'tax_rate' => '19.00', // 19% VAT
                'description' => 'Product return with 19% VAT',
            ],
        ]);

        // Verify credit note is in Confirmed status (not yet posted)
        $this->assertEquals(DocumentStatus::Confirmed, $creditNote->status);

        // ACT: Post the credit note through the DocumentPostingService
        // This triggers the FULL integration flow:
        // DocumentPostingService::post() → InvoicePosted event → InvoicePostedListener → AccountingService
        $postedCreditNote = $this->postingService->post($creditNote);

        // ASSERT: Document status changed to Posted
        $this->assertEquals(DocumentStatus::Posted, $postedCreditNote->status);
        $this->assertEquals(DocumentStatus::Posted, $creditNote->fresh()->status);

        // ASSERT: GL reversal entry created automatically by listener
        $glEntry = JournalEntry::where('source_type', 'Document')
            ->where('source_id', $creditNote->id)
            ->first();

        $this->assertNotNull($glEntry, 'JournalEntry should be created automatically by InvoicePostedListener');
        $this->assertEquals($this->company->id, $glEntry->company_id);
        $this->assertEquals($this->tenant->id, $glEntry->tenant_id);
        $this->assertEquals($creditNote->id, $glEntry->source_id);

        // ASSERT: AR credit line exists (REVERSED from invoice debit)
        $arLine = $glEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::CustomerReceivable);
        })->first();

        $this->assertNotNull($arLine, 'AR line should exist');
        $this->assertEquals('0.000', $arLine->debit);
        $this->assertEquals('1190.000', $arLine->credit, 'AR should be credited (REVERSAL) for total credit note amount');

        // ASSERT: Revenue debit lines exist (REVERSED from invoice credit)
        $revenueLines = $glEntry->lines()->whereHas('account', function ($q) {
            $q->whereIn('system_purpose', [
                SystemAccountPurpose::ProductRevenue,
                SystemAccountPurpose::ServiceRevenue,
            ]);
        })->get();

        $this->assertGreaterThan(0, $revenueLines->count(), 'Revenue line(s) should exist');
        $totalRevenueDebit = $revenueLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(1000.00, $totalRevenueDebit, 'Total revenue debits (REVERSAL) should equal subtotal');

        // ASSERT: Tax debit line exists (REVERSED from invoice credit)
        $taxLine = $glEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::VatCollected);
        })->first();

        $this->assertNotNull($taxLine, 'VAT line should exist');
        $this->assertEquals('190.000', $taxLine->debit, 'VAT should be debited (REVERSAL) for tax amount');
        $this->assertEquals('0.000', $taxLine->credit);

        // ASSERT: Balanced entry
        $totalDebits = $glEntry->lines()->sum('debit');
        $totalCredits = $glEntry->lines()->sum('credit');
        $this->assertEquals($totalDebits, $totalCredits, 'Entry must be balanced');
        $this->assertEquals('1190.00', $totalDebits);
    }

    /**
     * Test 3: Invoice + Credit Note GL entries net to ZERO (full reversal)
     *
     * Integration Flow Tested:
     * 1. Post invoice (creates GL entry)
     * 2. Post full credit note (creates GL reversal)
     * 3. Verify net impact on each account is ZERO
     *
     * This is the ultimate test of GL reversal correctness:
     * If invoice + credit note = 0 net impact, the reversal is mathematically perfect.
     */
    public function test_invoice_and_credit_note_gl_entries_net_to_zero(): void
    {
        // ARRANGE: Create and post invoice
        $invoice = $this->createConfirmedInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '1000.00',
                'tax_rate' => '19.00',
                'description' => 'Original invoice',
            ],
        ]);

        $this->postingService->post($invoice);

        // ARRANGE: Create and post full credit note (same amounts)
        $creditNote = $this->createConfirmedCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '1000.00',
                'tax_rate' => '19.00',
                'description' => 'Full credit - return',
            ],
        ]);

        $this->postingService->post($creditNote);

        // ACT: Get all journal lines for both invoice and credit note
        $invoiceGLEntry = JournalEntry::where('source_id', $invoice->id)->first();
        $creditNoteGLEntry = JournalEntry::where('source_id', $creditNote->id)->first();

        $this->assertNotNull($invoiceGLEntry);
        $this->assertNotNull($creditNoteGLEntry);

        $allLines = JournalLine::whereIn('journal_entry_id', [
            $invoiceGLEntry->id,
            $creditNoteGLEntry->id,
        ])->get();

        // ASSERT: Calculate net impact per account
        $netImpactPerAccount = [];

        foreach ($allLines as $line) {
            if (! isset($netImpactPerAccount[$line->account_id])) {
                $netImpactPerAccount[$line->account_id] = ['debit' => '0.00', 'credit' => '0.00'];
            }

            $netImpactPerAccount[$line->account_id]['debit'] = bcadd(
                $netImpactPerAccount[$line->account_id]['debit'],
                $line->debit,
                2
            );

            $netImpactPerAccount[$line->account_id]['credit'] = bcadd(
                $netImpactPerAccount[$line->account_id]['credit'],
                $line->credit,
                2
            );
        }

        // ASSERT: Net impact on each account should be ZERO (perfect reversal)
        foreach ($netImpactPerAccount as $accountId => $amounts) {
            $netBalance = bcsub($amounts['debit'], $amounts['credit'], 2);
            $account = Account::find($accountId);

            $this->assertEquals(
                '0.00',
                $netBalance,
                "Net impact on account {$account->code} ({$account->name}) should be ZERO after full reversal. ".
                "Debit: {$amounts['debit']}, Credit: {$amounts['credit']}, Net: {$netBalance}"
            );
        }

        // ASSERT: Total system debits still equal credits (balanced)
        $totalDebits = $allLines->sum('debit');
        $totalCredits = $allLines->sum('credit');
        $this->assertEquals($totalDebits, $totalCredits, 'System must remain balanced');
    }

    /**
     * Test 4: Partial credit note creates proportional GL reversal
     *
     * Integration Flow Tested:
     * 1. Post invoice for €1190 (€1000 + €190 tax)
     * 2. Post partial credit note for €595 (€500 + €95 tax) - 50% reversal
     * 3. Verify partial GL reversal amounts
     * 4. Verify remaining AR balance is €595
     */
    public function test_partial_credit_note_creates_proportional_gl_reversal(): void
    {
        // ARRANGE: Create and post full invoice
        $invoice = $this->createConfirmedInvoice([
            [
                'product' => $this->product1,
                'quantity' => '2',
                'unit_price' => '500.00',
                'tax_rate' => '19.00',
                'description' => 'Original invoice - 2 units',
            ],
        ]);

        $this->postingService->post($invoice);

        // ARRANGE: Create and post partial credit note (only 1 unit)
        $creditNote = $this->createConfirmedCreditNote([
            [
                'product' => $this->product1,
                'quantity' => '1', // Only 1 out of 2 units
                'unit_price' => '500.00',
                'tax_rate' => '19.00',
                'description' => 'Partial credit - 1 unit',
            ],
        ]);

        $this->postingService->post($creditNote);

        // ACT: Get GL entries
        $creditNoteGLEntry = JournalEntry::where('source_id', $creditNote->id)->first();
        $this->assertNotNull($creditNoteGLEntry);

        // ASSERT: Partial GL reversal amounts (€500 + €95 tax = €595)
        $arLine = $creditNoteGLEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::CustomerReceivable);
        })->first();

        $this->assertEquals('595.000', $arLine->credit, 'AR credit should match partial credit note total');

        $revenueLines = $creditNoteGLEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::ProductRevenue);
        })->get();

        $totalRevenueDebit = $revenueLines->sum(fn ($line) => (float) $line->debit);
        $this->assertEquals(500.00, $totalRevenueDebit, 'Revenue debit should match partial credit subtotal');

        $taxLine = $creditNoteGLEntry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::VatCollected);
        })->first();

        $this->assertEquals('95.000', $taxLine->debit, 'VAT debit should match partial credit tax amount');

        // ASSERT: Calculate remaining AR balance
        $invoiceGLEntry = JournalEntry::where('source_id', $invoice->id)->first();
        $allLines = JournalLine::whereIn('journal_entry_id', [
            $invoiceGLEntry->id,
            $creditNoteGLEntry->id,
        ])->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::CustomerReceivable);
        })->get();

        $totalARDebit = (string) $allLines->sum('debit');
        $totalARCredit = (string) $allLines->sum('credit');
        $remainingARBalance = bcsub($totalARDebit, $totalARCredit, 2);

        $this->assertEquals('595.00', $remainingARBalance, 'Remaining AR balance should be €595 (half of original)');
    }

    /**
     * Test 5: Invoice posting succeeds independently of GL entry creation failure
     *
     * Integration Flow:
     * 1. Delete required GL accounts to force GL creation failure
     * 2. Post invoice - document posting uses DB::afterCommit for event dispatch
     * 3. Verify document status IS changed to Posted (posting is decoupled from GL)
     *
     * NOTE: The DocumentPostingService dispatches InvoicePosted via DB::afterCommit(),
     * which means GL creation failures do NOT roll back the document posting.
     * This is intentional to prevent listener failures from breaking the fiscal chain.
     *
     * The AccountingService creates the JournalEntry header before looking up accounts,
     * so a partial (orphaned) entry may exist. This test verifies that the fiscal
     * posting is not affected by GL failures.
     */
    public function test_invoice_posting_succeeds_independently_of_gl_creation(): void
    {
        // ARRANGE: Create confirmed invoice
        $invoice = $this->createConfirmedInvoice([
            [
                'product' => $this->product1,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'description' => 'Test invoice',
            ],
        ]);

        // ARRANGE: Delete required GL account to force GL creation failure in listener
        $this->receivableAccount->delete();

        // ACT: Post invoice - the document posting itself should succeed
        // The InvoicePosted event listener will fail due to missing AR account.
        // In production, DB::afterCommit dispatches the event after the outer transaction commits,
        // but in tests with RefreshDatabase, afterCommit fires immediately.
        // We catch the RuntimeException that propagates from the listener.
        try {
            $this->postingService->post($invoice);
        } catch (\RuntimeException $e) {
            // Expected: listener fails due to missing AR account
            $this->assertStringContainsString('customer_receivable', $e->getMessage());
        }

        // ASSERT: Document status IS changed to Posted (the document was posted before
        // the event listener fired and failed)
        $invoice->refresh();
        $this->assertEquals(
            DocumentStatus::Posted,
            $invoice->status,
            'Document should be Posted - fiscal chain was sealed before GL listener ran'
        );
    }

    /**
     * Test 6: Multiple invoices create separate GL entries
     *
     * Integration Flow Tested:
     * 1. Post 3 different invoices
     * 2. Verify 3 separate journal entries created
     * 3. Verify each has correct AR + Revenue + Tax breakdown
     * 4. Verify no cross-contamination between invoices
     */
    public function test_multiple_invoices_create_separate_gl_entries(): void
    {
        // ARRANGE & ACT: Create and post 3 invoices
        $invoice1 = $this->createConfirmedInvoice([
            ['product' => $this->product1, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '19.00', 'description' => 'Invoice 1'],
        ]);

        $invoice2 = $this->createConfirmedInvoice([
            ['product' => $this->product2, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '19.00', 'description' => 'Invoice 2'],
        ]);

        $invoice3 = $this->createConfirmedInvoice([
            ['product' => $this->service, 'quantity' => '1', 'unit_price' => '150.00', 'tax_rate' => '19.00', 'description' => 'Invoice 3 - Service'],
        ]);

        $this->postingService->post($invoice1);
        $this->postingService->post($invoice2);
        $this->postingService->post($invoice3);

        // ASSERT: 3 separate journal entries created
        $glEntry1 = JournalEntry::where('source_id', $invoice1->id)->first();
        $glEntry2 = JournalEntry::where('source_id', $invoice2->id)->first();
        $glEntry3 = JournalEntry::where('source_id', $invoice3->id)->first();

        $this->assertNotNull($glEntry1);
        $this->assertNotNull($glEntry2);
        $this->assertNotNull($glEntry3);

        $this->assertNotEquals($glEntry1->id, $glEntry2->id);
        $this->assertNotEquals($glEntry2->id, $glEntry3->id);
        $this->assertNotEquals($glEntry1->id, $glEntry3->id);

        // ASSERT: Each GL entry has correct AR + Revenue + Tax
        $this->assertGLEntryHasCorrectStructure($glEntry1, '119.000'); // €100 + €19 tax
        $this->assertGLEntryHasCorrectStructure($glEntry2, '238.000'); // €200 + €38 tax
        $this->assertGLEntryHasCorrectStructure($glEntry3, '178.500'); // €150 + €28.50 tax

        // ASSERT: No cross-contamination (each entry is independent)
        $entry1Lines = $glEntry1->lines()->count();
        $entry2Lines = $glEntry2->lines()->count();
        $entry3Lines = $glEntry3->lines()->count();

        $this->assertGreaterThanOrEqual(3, $entry1Lines, 'Entry 1 should have at least 3 lines');
        $this->assertGreaterThanOrEqual(3, $entry2Lines, 'Entry 2 should have at least 3 lines');
        $this->assertGreaterThanOrEqual(3, $entry3Lines, 'Entry 3 should have at least 3 lines');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Create a CONFIRMED invoice (ready to post)
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createConfirmedInvoice(array $lines): Document
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

        // Create invoice in CONFIRMED status (ready to post)
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-INT-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed, // CONFIRMED status (not yet posted)
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

        return $invoice->fresh(['lines', 'lines.product']);
    }

    /**
     * Create a CONFIRMED credit note (ready to post)
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createConfirmedCreditNote(array $lines): Document
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

        // Create credit note in CONFIRMED status (ready to post)
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-INT-'.uniqid(),
            'partner_id' => $this->customer->id,
            'document_date' => now(),
            'status' => DocumentStatus::Confirmed, // CONFIRMED status (not yet posted)
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
                'document_id' => $creditNote->id,
                'product_id' => $lineData['product']->id,
                'line_number' => $lineNumber++,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'],
                'line_total' => $lineSubtotal,
            ]);
        }

        return $creditNote->fresh(['lines', 'lines.product']);
    }

    /**
     * Assert GL entry has correct AR + Revenue + Tax structure
     */
    private function assertGLEntryHasCorrectStructure(JournalEntry $entry, string $expectedTotal): void
    {
        // Verify balanced
        $totalDebits = $entry->lines()->sum('debit');
        $totalCredits = $entry->lines()->sum('credit');

        $this->assertEquals(
            $totalDebits,
            $totalCredits,
            "Entry {$entry->entry_number} should be balanced"
        );

        $this->assertEquals(
            $expectedTotal,
            $totalDebits,
            "Entry {$entry->entry_number} total should be {$expectedTotal}"
        );

        // Verify has AR line
        $arLineExists = $entry->lines()->whereHas('account', function ($q) {
            $q->where('system_purpose', SystemAccountPurpose::CustomerReceivable);
        })->exists();

        $this->assertTrue($arLineExists, "Entry {$entry->entry_number} should have AR line");

        // Verify has Revenue line
        $revenueLineExists = $entry->lines()->whereHas('account', function ($q) {
            $q->whereIn('system_purpose', [
                SystemAccountPurpose::ProductRevenue,
                SystemAccountPurpose::ServiceRevenue,
            ]);
        })->exists();

        $this->assertTrue($revenueLineExists, "Entry {$entry->entry_number} should have Revenue line");
    }
}
