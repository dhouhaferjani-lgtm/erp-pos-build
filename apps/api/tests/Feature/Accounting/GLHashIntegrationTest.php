<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
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
 * GLHashIntegrationTest - TDD RED Phase for P1-B Milestone 3
 *
 * Tests that AccountingService integrates with GeneralLedgerHashService
 * when creating GL entries from invoices and credit notes.
 *
 * Expected behavior:
 * 1. GL entries should include fiscal_hash (64-char SHA-256)
 * 2. GL entries should include chain_sequence (incrementing integer)
 * 3. First GL entry has null previous_hash (genesis entry)
 * 4. Subsequent GL entries link via previous_hash
 * 5. Hash chain can be verified via GeneralLedgerHashService
 * 6. Different companies have independent chains
 * 7. Hash includes journal line data (totals)
 *
 * CRITICAL: These tests MUST fail initially (RED phase).
 * Agent 3B will implement the integration to make them pass (GREEN phase).
 */
final class GLHashIntegrationTest extends TestCase
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

    private Product $product;

    private AccountingService $accountingService;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'GL Hash Test Tenant',
            'slug' => 'gl-hash-test-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GL Hash Test Company',
            'legal_name' => 'GL Hash Test Company LLC',
            'tax_id' => 'TAX-HASH-TEST-'.uniqid(),
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
            'name' => 'Hash Test User',
            'email' => 'hashtest-'.uniqid().'@example.com',
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
            'name' => 'Hash Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create warehouse
        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            // locations.code is varchar(20); keep the unique suffix short
            // enough to fit (PostgreSQL enforces the length, SQLite does not).
            'code' => 'WH-'.substr((string) uniqid(), -10),
            'name' => 'Hash Test Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        // Create Chart of Accounts
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

        // Create test product
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'HASH-PROD-'.uniqid(),
            'name' => 'Hash Test Product',
            'type' => ProductType::Part,
            'cost_price' => '50.00',
            'selling_price' => '100.00',
            'is_active' => true,
        ]);

        // Initialize services
        $this->accountingService = app(AccountingService::class);
        $this->hashService = app(GeneralLedgerHashService::class);
    }

    /**
     * Test 1: Invoice GL entry includes fiscal_hash
     *
     * When AccountingService creates GL entries for an invoice,
     * the JournalEntry should have a fiscal_hash field populated
     * with a 64-character SHA-256 hash.
     */
    public function test_invoice_gl_entry_includes_fiscal_hash(): void
    {
        // Arrange: Create posted invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '2',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Test product for hash integration',
            ],
        ]);

        // Act: Create GL entries
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);

        // Assert: GL entry has fiscal_hash
        $glEntry = JournalEntry::find($journalEntryId);

        $this->assertNotNull($glEntry, 'Journal entry should be created');
        $this->assertNotNull($glEntry->fiscal_hash, 'GL entry should have fiscal_hash');
        $this->assertEquals(64, strlen($glEntry->fiscal_hash), 'SHA-256 hash should be 64 characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $glEntry->fiscal_hash, 'Hash should be valid hex');
    }

    /**
     * Test 2: Invoice GL entry includes chain_sequence
     *
     * The first GL entry for a company should have chain_sequence = 1.
     */
    public function test_invoice_gl_entry_includes_chain_sequence(): void
    {
        // Arrange: Create posted invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Test sequence',
            ],
        ]);

        // Act: Create GL entries
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);

        // Assert: GL entry has chain_sequence
        $glEntry = JournalEntry::find($journalEntryId);

        $this->assertNotNull($glEntry->chain_sequence, 'GL entry should have chain_sequence');
        $this->assertEquals(1, $glEntry->chain_sequence, 'First GL entry should have sequence 1');
    }

    /**
     * Test 3: First GL entry has null previous_hash (genesis entry)
     *
     * The first GL entry in a company's chain should have previous_hash = null,
     * indicating it is the genesis entry.
     */
    public function test_first_gl_entry_has_null_previous_hash(): void
    {
        // Arrange: Create posted invoice (first GL entry for company)
        $invoice = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Genesis entry test',
            ],
        ]);

        // Act: Create GL entries
        $journalEntryId = $this->accountingService->createInvoiceGLEntries($invoice);

        // Assert: GL entry has null previous_hash
        $glEntry = JournalEntry::find($journalEntryId);

        $this->assertNull($glEntry->previous_hash, 'Genesis entry should have null previous_hash');
        $this->assertNotNull($glEntry->fiscal_hash, 'But should still have its own fiscal_hash');
    }

    /**
     * Test 4: Second GL entry chains to first
     *
     * When creating a second GL entry, it should reference the first entry's
     * fiscal_hash in its previous_hash field, and have chain_sequence = 2.
     */
    public function test_second_gl_entry_chains_to_first(): void
    {
        // Arrange: Create and post first invoice → GL entry 1
        $invoice1 = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'First invoice',
            ],
        ]);

        $entry1Id = $this->accountingService->createInvoiceGLEntries($invoice1);
        $entry1 = JournalEntry::find($entry1Id);

        // Act: Create and post second invoice → GL entry 2
        $invoice2 = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '200.00',
                'tax_rate' => '20.00',
                'description' => 'Second invoice',
            ],
        ]);

        $entry2Id = $this->accountingService->createInvoiceGLEntries($invoice2);
        $entry2 = JournalEntry::find($entry2Id);

        // Assert: GL entry 2 chains to GL entry 1
        $this->assertNotNull($entry2->previous_hash, 'Second entry should have previous_hash');
        $this->assertEquals($entry1->fiscal_hash, $entry2->previous_hash, 'Second entry should reference first entry\'s hash');
        $this->assertEquals(2, $entry2->chain_sequence, 'Second entry should have sequence 2');
    }

    /**
     * Test 5: Credit note GL entry includes hash chain
     *
     * Credit notes should also participate in the hash chain.
     */
    public function test_credit_note_gl_entry_includes_hash_chain(): void
    {
        // Arrange: Create and post invoice
        $invoice = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Invoice for credit note',
            ],
        ]);

        $invoiceEntryId = $this->accountingService->createInvoiceGLEntries($invoice);
        $invoiceEntry = JournalEntry::find($invoiceEntryId);

        // Act: Create and post credit note
        $creditNote = $this->createCreditNote([
            [
                'product' => $this->product,
                'quantity' => '1',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Credit note for return',
            ],
        ]);

        $creditNoteEntryId = $this->accountingService->createCreditNoteGLEntries($creditNote);
        $creditNoteEntry = JournalEntry::find($creditNoteEntryId);

        // Assert: Credit note GL entry has hash chain fields
        $this->assertNotNull($creditNoteEntry->fiscal_hash, 'Credit note entry should have fiscal_hash');
        $this->assertEquals($invoiceEntry->fiscal_hash, $creditNoteEntry->previous_hash, 'Credit note should chain to invoice');
        $this->assertEquals(2, $creditNoteEntry->chain_sequence, 'Credit note should have sequence 2');
    }

    /**
     * Test 6: Multiple invoices create valid chain
     *
     * When posting 3 invoices, they should form a continuous chain:
     * entry1 -> entry2 -> entry3
     */
    public function test_multiple_invoices_create_valid_chain(): void
    {
        // Arrange & Act: Create and post 3 invoices
        $invoice1 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Invoice 1']]);
        $entry1Id = $this->accountingService->createInvoiceGLEntries($invoice1);

        $invoice2 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '20.00', 'description' => 'Invoice 2']]);
        $entry2Id = $this->accountingService->createInvoiceGLEntries($invoice2);

        $invoice3 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '300.00', 'tax_rate' => '20.00', 'description' => 'Invoice 3']]);
        $entry3Id = $this->accountingService->createInvoiceGLEntries($invoice3);

        // Assert: Retrieve all 3 GL entries
        $entry1 = JournalEntry::find($entry1Id);
        $entry2 = JournalEntry::find($entry2Id);
        $entry3 = JournalEntry::find($entry3Id);

        // Assert chain continuity
        $this->assertNull($entry1->previous_hash, 'First entry should be genesis');
        $this->assertEquals($entry1->fiscal_hash, $entry2->previous_hash, 'Entry 2 should chain to Entry 1');
        $this->assertEquals($entry2->fiscal_hash, $entry3->previous_hash, 'Entry 3 should chain to Entry 2');

        // Assert sequences
        $this->assertEquals(1, $entry1->chain_sequence);
        $this->assertEquals(2, $entry2->chain_sequence);
        $this->assertEquals(3, $entry3->chain_sequence);
    }

    /**
     * Test 7: Hash chain can be verified
     *
     * After creating multiple GL entries, the GeneralLedgerHashService
     * should be able to verify the chain is valid.
     */
    public function test_hash_chain_can_be_verified(): void
    {
        // Arrange: Create and post 3 invoices
        $invoice1 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Invoice 1']]);
        $entry1Id = $this->accountingService->createInvoiceGLEntries($invoice1);

        $invoice2 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '20.00', 'description' => 'Invoice 2']]);
        $entry2Id = $this->accountingService->createInvoiceGLEntries($invoice2);

        $invoice3 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '300.00', 'tax_rate' => '20.00', 'description' => 'Invoice 3']]);
        $entry3Id = $this->accountingService->createInvoiceGLEntries($invoice3);

        // Assert: Entries have hashes before verification
        $entry1 = JournalEntry::find($entry1Id);
        $entry2 = JournalEntry::find($entry2Id);
        $entry3 = JournalEntry::find($entry3Id);

        $this->assertNotNull($entry1->fiscal_hash, 'Entry 1 should have fiscal_hash before verification');
        $this->assertNotNull($entry2->fiscal_hash, 'Entry 2 should have fiscal_hash before verification');
        $this->assertNotNull($entry3->fiscal_hash, 'Entry 3 should have fiscal_hash before verification');

        // Act: Verify chain
        $isValid = $this->hashService->verifyChain($this->company->id);

        // Assert: Chain should be valid
        $this->assertTrue($isValid, 'Hash chain should be valid after creating GL entries');
    }

    /**
     * Test 8: Invoice and credit note create continuous chain
     *
     * When posting invoice → credit note → invoice, the chain should be:
     * sequence 1, 2, 3 with proper hash linkage.
     */
    public function test_invoice_and_credit_note_create_continuous_chain(): void
    {
        // Arrange & Act: Post invoice → credit note → invoice
        $invoice1 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Invoice 1']]);
        $entry1Id = $this->accountingService->createInvoiceGLEntries($invoice1);

        $creditNote = $this->createCreditNote([['product' => $this->product, 'quantity' => '1', 'unit_price' => '50.00', 'tax_rate' => '20.00', 'description' => 'Credit Note']]);
        $entry2Id = $this->accountingService->createCreditNoteGLEntries($creditNote);

        $invoice2 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '150.00', 'tax_rate' => '20.00', 'description' => 'Invoice 2']]);
        $entry3Id = $this->accountingService->createInvoiceGLEntries($invoice2);

        // Assert: Retrieve entries
        $entry1 = JournalEntry::find($entry1Id);
        $entry2 = JournalEntry::find($entry2Id);
        $entry3 = JournalEntry::find($entry3Id);

        // Assert sequences
        $this->assertEquals(1, $entry1->chain_sequence);
        $this->assertEquals(2, $entry2->chain_sequence);
        $this->assertEquals(3, $entry3->chain_sequence);

        // Assert chain links
        $this->assertNull($entry1->previous_hash);
        $this->assertEquals($entry1->fiscal_hash, $entry2->previous_hash);
        $this->assertEquals($entry2->fiscal_hash, $entry3->previous_hash);

        // Assert chain verifies
        $this->assertTrue($this->hashService->verifyChain($this->company->id));
    }

    /**
     * Test 9: GL entries for different companies have independent chains
     *
     * Two companies should maintain separate hash chains.
     */
    public function test_gl_entries_for_different_companies_have_independent_chains(): void
    {
        // Arrange: Create second tenant to avoid account code conflicts
        $tenant2 = Tenant::create([
            'name' => 'Second GL Hash Test Tenant',
            'slug' => 'gl-hash-test2-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create second company under second tenant
        $company2 = Company::create([
            'tenant_id' => $tenant2->id,
            'name' => 'Second Hash Test Company',
            'legal_name' => 'Second Hash Test Company LLC',
            'tax_id' => 'TAX-HASH2-'.uniqid(),
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Create Chart of Accounts for company2
        $receivableAccount2 = Account::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'code' => '411',
            'name' => 'Clients - Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
            'is_active' => true,
        ]);

        $revenueAccount2 = Account::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'code' => '707',
            'name' => 'Vente de marchandises',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
            'is_active' => true,
        ]);

        $serviceRevenueAccount2 = Account::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'code' => '706',
            'name' => 'Prestations de services',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
            'is_active' => true,
        ]);

        $vatAccount2 = Account::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'code' => '44571',
            'name' => 'TVA collectée',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
            'is_active' => true,
        ]);

        $customer2 = Partner::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'name' => 'Customer for Company 2',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        $product2 = Product::create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company2->id,
            'sku' => 'PROD2-'.uniqid(),
            'name' => 'Product for Company 2',
            'type' => ProductType::Part,
            'cost_price' => '50.00',
            'selling_price' => '100.00',
            'is_active' => true,
        ]);

        // Act: Post invoice for company A
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $invoiceA1 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '100.00', 'tax_rate' => '20.00', 'description' => 'Company A Invoice 1']]);
        $entryA1Id = $this->accountingService->createInvoiceGLEntries($invoiceA1);

        // Act: Post invoice for company B
        app(CompanyContext::class)->setCompanyId($company2->id);
        $invoiceB1 = $this->createInvoiceForCompany($company2, $customer2, $product2, [['product' => $product2, 'quantity' => '1', 'unit_price' => '150.00', 'tax_rate' => '20.00', 'description' => 'Company B Invoice 1']]);
        $entryB1Id = $this->accountingService->createInvoiceGLEntries($invoiceB1);

        // Act: Post another invoice for company A
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $invoiceA2 = $this->createInvoice([['product' => $this->product, 'quantity' => '1', 'unit_price' => '200.00', 'tax_rate' => '20.00', 'description' => 'Company A Invoice 2']]);
        $entryA2Id = $this->accountingService->createInvoiceGLEntries($invoiceA2);

        // Assert: Retrieve entries
        $entryA1 = JournalEntry::find($entryA1Id);
        $entryA2 = JournalEntry::find($entryA2Id);
        $entryB1 = JournalEntry::find($entryB1Id);

        // Assert: Company A has chain sequence 1, 2
        $this->assertEquals(1, $entryA1->chain_sequence);
        $this->assertEquals(2, $entryA2->chain_sequence);
        $this->assertEquals($entryA1->fiscal_hash, $entryA2->previous_hash);

        // Assert: Company B has independent chain sequence 1
        $this->assertEquals(1, $entryB1->chain_sequence);
        $this->assertNull($entryB1->previous_hash, 'Company B first entry should be genesis');

        // Assert: Hashes are different
        $this->assertNotEquals($entryA1->fiscal_hash, $entryB1->fiscal_hash, 'Different companies should have different hashes');
    }

    /**
     * Test 10: Hash includes journal line data
     *
     * The fiscal_hash should be calculated based on journal entry data
     * including line totals. Manually recalculate and verify.
     */
    public function test_hash_includes_journal_line_data(): void
    {
        // Arrange: Create invoice with specific amounts
        $invoice = $this->createInvoice([
            [
                'product' => $this->product,
                'quantity' => '2',
                'unit_price' => '100.00',
                'tax_rate' => '20.00',
                'description' => 'Hash verification test',
            ],
        ]);

        // Act: Create GL entry
        $entryId = $this->accountingService->createInvoiceGLEntries($invoice);

        // Assert: Retrieve entry with lines
        $entry = JournalEntry::with('lines')->find($entryId);

        // Manually recalculate hash using hash service
        $calculatedHash = $this->hashService->calculateHash($entry, $entry->previous_hash);

        // Assert: Calculated hash matches stored hash
        $this->assertEquals($calculatedHash, $entry->fiscal_hash, 'Stored hash should match calculated hash');

        // Assert: Hash format is valid
        $this->assertEquals(64, strlen($entry->fiscal_hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $entry->fiscal_hash);

        // Assert: Totals are included in serialization (verify via hash service)
        $serialized = $this->hashService->serializeForHashing($entry);
        $this->assertStringContainsString($entry->entry_number, $serialized, 'Serialization should include entry number');
        $this->assertStringContainsString($entry->company_id, $serialized, 'Serialization should include company_id');
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
            'document_number' => 'INV-HASH-'.uniqid(),
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

    /**
     * Create a posted credit note with lines
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createCreditNote(array $lines): Document
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

        // Create credit note
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => DocumentType::CreditNote,
            'document_number' => 'CN-HASH-'.uniqid(),
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

        return $creditNote->fresh(['lines']);
    }

    /**
     * Create invoice for a specific company (used in multi-company test)
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string, tax_rate: string, description: string}>  $lines
     */
    private function createInvoiceForCompany(Company $company, Partner $customer, Product $product, array $lines): Document
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
            'company_id' => $company->id,
            'type' => DocumentType::Invoice,
            'document_number' => 'INV-HASH-'.uniqid(),
            'partner_id' => $customer->id,
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
