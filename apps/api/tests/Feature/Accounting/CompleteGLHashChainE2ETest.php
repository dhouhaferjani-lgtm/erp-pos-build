<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Accounting\Application\Services\GeneralLedgerHashService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1-B Milestone 5: Complete E2E Integration Test.
 *
 * This test verifies the COMPLETE GL hash chain implementation
 * through a realistic business cycle scenario:
 * 1. Post invoice → GL entry 1 with hash
 * 2. Post credit note → GL entry 2 chained to entry 1
 * 3. Post another invoice → GL entry 3 chained to entry 2
 * 4. Verify complete chain integrity
 * 5. Verify immutability enforcement
 * 6. Verify tamper detection
 *
 * Success criteria:
 * - All GL entries have proper hash chain (fiscal_hash, previous_hash, chain_sequence)
 * - Chain verification passes
 * - Entries cannot be modified (immutability enforced)
 * - Tampering is detected
 */
final class CompleteGLHashChainE2ETest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $customer;

    private Product $product;

    private Account $cashAccount;

    private Account $revenueAccount;

    private Account $receivablesAccount;

    private Account $vatCollectedAccount;

    private AccountingService $accountingService;

    private GeneralLedgerHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'E2E Test Tenant',
            'slug' => 'e2e-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'E2E Test Company',
            'legal_name' => 'E2E Test Company LLC',
            'tax_id' => 'E2E123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Create customer
        $this->customer = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create product
        $this->product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
            'type' => ProductType::Part,
            'code' => 'TEST-PROD',
            'sku' => 'SKU-TEST',
            'sale_price' => '100.00',
        ]);

        // Create accounts
        $this->cashAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1100',
            'name' => 'Cash',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::Bank,
        ]);

        $this->receivablesAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
        ]);

        $this->revenueAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
        ]);

        $this->vatCollectedAccount = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4450',
            'name' => 'VAT Collected',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
        ]);

        // Service revenue account (needed by AccountingService)
        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => '4001',
            'name' => 'Service Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
        ]);

        // Inject services
        $this->accountingService = app(AccountingService::class);
        $this->hashService = app(GeneralLedgerHashService::class);
    }

    public function test_complete_business_cycle_maintains_valid_hash_chain(): void
    {
        // ACT 1: Post first invoice → GL entry 1 (genesis)
        $invoice1 = $this->createAndPostInvoice('INV-001', '1000.00');

        // ASSERT: GL entry 1 has proper hash chain data
        $entry1 = JournalEntry::where('source_id', $invoice1->id)->first();
        $this->assertNotNull($entry1, 'Invoice should create GL entry');
        $this->assertNotNull($entry1->fiscal_hash, 'Entry should have fiscal_hash');
        $this->assertNull($entry1->previous_hash, 'Genesis entry should have null previous_hash');
        $this->assertEquals(1, $entry1->chain_sequence, 'Genesis entry should have sequence 1');

        // ACT 2: Post credit note → GL entry 2 chained to entry 1
        $creditNote = $this->createAndPostCreditNote($invoice1, 'CN-001', '500.00');

        // ASSERT: GL entry 2 chains to entry 1
        $entry2 = JournalEntry::where('source_id', $creditNote->id)->first();
        $this->assertNotNull($entry2, 'Credit note should create GL entry');
        $this->assertNotNull($entry2->fiscal_hash, 'Entry should have fiscal_hash');
        $this->assertEquals($entry1->fiscal_hash, $entry2->previous_hash, 'Entry 2 should chain to entry 1');
        $this->assertEquals(2, $entry2->chain_sequence, 'Entry 2 should have sequence 2');

        // ACT 3: Post second invoice → GL entry 3 chained to entry 2
        $invoice2 = $this->createAndPostInvoice('INV-002', '2000.00');

        // ASSERT: GL entry 3 chains to entry 2
        $entry3 = JournalEntry::where('source_id', $invoice2->id)->first();
        $this->assertNotNull($entry3, 'Second invoice should create GL entry');
        $this->assertNotNull($entry3->fiscal_hash, 'Entry should have fiscal_hash');
        $this->assertEquals($entry2->fiscal_hash, $entry3->previous_hash, 'Entry 3 should chain to entry 2');
        $this->assertEquals(3, $entry3->chain_sequence, 'Entry 3 should have sequence 3');

        // VERIFY: Complete chain is valid
        $isChainValid = $this->hashService->verifyChain($this->company->id);
        $this->assertTrue($isChainValid, 'Complete hash chain should be valid');

        // VERIFY: Chain sequence is continuous (1, 2, 3)
        $allEntries = JournalEntry::where('company_id', $this->company->id)
            ->whereNotNull('fiscal_hash')
            ->orderBy('chain_sequence')
            ->get();

        $this->assertCount(3, $allEntries, 'Should have exactly 3 chained entries');
        $this->assertEquals([1, 2, 3], $allEntries->pluck('chain_sequence')->toArray());

        // VERIFY: Each entry's hash is correctly calculated
        $recalculatedHash1 = $this->hashService->calculateHash($entry1->fresh('lines'), null);
        $this->assertEquals($entry1->fiscal_hash, $recalculatedHash1, 'Entry 1 hash should be correct');

        $recalculatedHash2 = $this->hashService->calculateHash($entry2->fresh('lines'), $entry1->fiscal_hash);
        $this->assertEquals($entry2->fiscal_hash, $recalculatedHash2, 'Entry 2 hash should be correct');

        $recalculatedHash3 = $this->hashService->calculateHash($entry3->fresh('lines'), $entry2->fiscal_hash);
        $this->assertEquals($entry3->fiscal_hash, $recalculatedHash3, 'Entry 3 hash should be correct');
    }

    public function test_immutability_is_enforced_on_chained_entries(): void
    {
        // Arrange: Create and post invoice to get chained GL entry
        $invoice = $this->createAndPostInvoice('INV-003', '1500.00');
        $entry = JournalEntry::where('source_id', $invoice->id)->first();

        // Assert: Entry is chained
        $this->assertNotNull($entry->fiscal_hash, 'Entry should have fiscal_hash');
        $this->assertTrue($entry->isChained(), 'Entry should be chained');

        // Act & Assert: Attempting to modify should throw exception
        $this->expectException(ImmutableJournalEntryException::class);
        $this->expectExceptionMessage('Cannot update journal entry');

        $entry->update(['description' => 'Modified description']);
    }

    public function test_tampering_is_detected(): void
    {
        // Arrange: Create valid chain with 3 entries
        $invoice1 = $this->createAndPostInvoice('INV-004', '1000.00');
        $creditNote = $this->createAndPostCreditNote($invoice1, 'CN-002', '500.00');
        $invoice2 = $this->createAndPostInvoice('INV-005', '2000.00');

        // Verify chain is valid before tampering
        $this->assertTrue($this->hashService->verifyChain($this->company->id), 'Chain should be valid initially');

        // Act: Tamper with middle entry (simulate database tampering)
        $entry2 = JournalEntry::where('source_id', $creditNote->id)->first();
        $tamperedHash = str_repeat('f', 64); // Invalid hash

        // Use raw SQL to bypass immutability protection
        DB::table('journal_entries')
            ->where('id', $entry2->id)
            ->update(['fiscal_hash' => $tamperedHash]);

        // Assert: Chain should now be INVALID
        $isChainValid = $this->hashService->verifyChain($this->company->id);
        $this->assertFalse($isChainValid, 'Tampered chain should fail verification');
    }

    public function test_performance_is_acceptable(): void
    {
        // Measure time to create 50 chained GL entries
        $start = microtime(true);

        for ($i = 1; $i <= 50; $i++) {
            $this->createAndPostInvoice("INV-PERF-{$i}", '100.00');
        }

        $duration = microtime(true) - $start;

        // Assert: 50 entries should be created in less than 10 seconds
        $this->assertLessThan(10.0, $duration, "Creating 50 chained entries took {$duration}s (should be < 10s)");

        // Assert: Average per entry should be < 200ms
        $avgPerEntry = $duration / 50;
        $this->assertLessThan(0.2, $avgPerEntry, "Average per entry: {$avgPerEntry}s (should be < 0.2s)");

        // Verify chain is valid
        $this->assertTrue($this->hashService->verifyChain($this->company->id), 'Chain of 50 entries should be valid');
    }

    public function test_different_companies_have_independent_chains(): void
    {
        // Create second company
        $company2 = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Second Company',
            'legal_name' => 'Second Company LLC',
            'tax_id' => 'SEC123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => \App\Modules\Company\Domain\Enums\CompanyStatus::Active,
        ]);

        // Create accounts for company 2
        $receivablesAccount2 = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'code' => '1200',
            'name' => 'Accounts Receivable',
            'type' => AccountType::Asset,
            'system_purpose' => SystemAccountPurpose::CustomerReceivable,
        ]);

        $revenueAccount2 = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'code' => '4000',
            'name' => 'Sales Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ProductRevenue,
        ]);

        $vatAccount2 = Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'code' => '4450',
            'name' => 'VAT Collected',
            'type' => AccountType::Liability,
            'system_purpose' => SystemAccountPurpose::VatCollected,
        ]);

        Account::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'code' => '4001',
            'name' => 'Service Revenue',
            'type' => AccountType::Revenue,
            'system_purpose' => SystemAccountPurpose::ServiceRevenue,
        ]);

        // Create customer for company 2
        $customer2 = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'name' => 'Customer 2',
            'type' => PartnerType::Customer,
            'is_active' => true,
        ]);

        // Create product for company 2
        $product2 = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'name' => 'Product 2',
            'type' => ProductType::Part,
            'code' => 'PROD2',
            'sku' => 'SKU-PROD2',
            'sale_price' => '100.00',
        ]);

        // Act: Post invoice for company 1 (sequence 1)
        $invoice1 = $this->createAndPostInvoice('INV-C1-001', '1000.00');
        $entry1 = JournalEntry::where('source_id', $invoice1->id)->first();

        // Act: Post invoice for company 2 (also sequence 1, but independent chain)
        $invoice2 = $this->createInvoiceForCompany($company2, $customer2, $product2, 'INV-C2-001', '2000.00');
        $this->accountingService->createInvoiceGLEntries($invoice2->fresh('lines'));
        $entry2 = JournalEntry::where('source_id', $invoice2->id)->first();

        // Assert: Both are genesis entries in their respective chains
        $this->assertEquals(1, $entry1->chain_sequence, 'Company 1 should have sequence 1');
        $this->assertEquals(1, $entry2->chain_sequence, 'Company 2 should also have sequence 1');
        $this->assertNull($entry1->previous_hash, 'Company 1 genesis should have null previous_hash');
        $this->assertNull($entry2->previous_hash, 'Company 2 genesis should have null previous_hash');

        // Assert: Chains are independent (different hashes)
        $this->assertNotEquals($entry1->fiscal_hash, $entry2->fiscal_hash, 'Different companies should have different hashes');

        // Assert: Both chains are valid
        $this->assertTrue($this->hashService->verifyChain($this->company->id), 'Company 1 chain should be valid');
        $this->assertTrue($this->hashService->verifyChain($company2->id), 'Company 2 chain should be valid');
    }

    // ========================================================================
    // HELPER METHODS
    // ========================================================================

    private function createAndPostInvoice(string $documentNumber, string $amount): Document
    {
        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'subtotal' => $amount,
            'tax_amount' => bcmul($amount, '0.20', 2), // 20% VAT
            'total' => bcmul($amount, '1.20', 2),
            'status' => 'posted',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'product_id' => $this->product->id,
            'description' => 'Test product line',
            'quantity' => '1.00',
            'unit_price' => $amount,
            'tax_rate' => '20.00',
            'line_total' => $amount,
            'line_order' => 1,
            'line_number' => 1,
        ]);

        // Create GL entries (triggers hash chain logic)
        $this->accountingService->createInvoiceGLEntries($invoice->fresh('lines'));

        return $invoice->fresh();
    }

    private function createAndPostCreditNote(Document $invoice, string $documentNumber, string $amount): Document
    {
        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $invoice->partner_id,
            'type' => DocumentType::CreditNote,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'subtotal' => $amount,
            'tax_amount' => bcmul($amount, '0.20', 2), // 20% VAT
            'total' => bcmul($amount, '1.20', 2),
            'status' => 'posted',
            'source_document_id' => $invoice->id,
        ]);

        DocumentLine::create([
            'document_id' => $creditNote->id,
            'product_id' => $this->product->id,
            'description' => 'Credit note line',
            'quantity' => '1.00',
            'unit_price' => $amount,
            'tax_rate' => '20.00',
            'line_total' => $amount,
            'line_order' => 1,
            'line_number' => 1,
        ]);

        // Create GL entries (triggers hash chain logic)
        $this->accountingService->createCreditNoteGLEntries($creditNote->fresh('lines'));

        return $creditNote->fresh();
    }

    private function createInvoiceForCompany(
        Company $company,
        Partner $customer,
        Product $product,
        string $documentNumber,
        string $amount
    ): Document {
        $invoice = Document::create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => DocumentType::Invoice,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'subtotal' => $amount,
            'total_tax' => bcmul($amount, '0.20', 2),
            'total' => bcmul($amount, '1.20', 2),
            'status' => 'posted',
        ]);

        DocumentLine::create([
            'document_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Test product',
            'quantity' => '1.00',
            'unit_price' => $amount,
            'tax_rate' => '20.00',
            'line_total' => $amount,
            'line_order' => 1,
            'line_number' => 1,
        ]);

        return $invoice->fresh('lines');
    }
}
