<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End-to-end test for fiscal compliance hardening.
 *
 * This test verifies the complete lifecycle of fiscal documents:
 * 1. Document creation with default fiscal status
 * 2. Posting seals the document with hash chain
 * 3. Sealed documents cannot be modified (except balance_due)
 * 4. Hash chain integrity is maintained
 * 5. Cancellation voids the fiscal status
 */
class FiscalHardeningE2ETest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private DocumentPostingService $postingService;

    private FiscalHashService $hashService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->postingService = app(DocumentPostingService::class);
        $this->hashService = app(FiscalHashService::class);
    }

    public function test_complete_fiscal_document_lifecycle(): void
    {
        // Step 1: Create a draft invoice
        $invoice = $this->createInvoice();

        // Verify initial fiscal status
        $this->assertEquals(FiscalCategory::NonFiscal, $invoice->fiscal_category);
        $this->assertEquals(FiscalStatus::Draft, $invoice->fiscal_status);
        $this->assertFalse($invoice->isSealed());
        $this->assertNull($invoice->fiscal_hash);

        // Step 2: Confirm the invoice
        $invoice->update(['status' => DocumentStatus::Confirmed]);
        $this->assertTrue($invoice->isConfirmed());

        // Step 3: Post the invoice (should seal it)
        $postedInvoice = $this->postingService->post($invoice);

        // Verify fiscal sealing
        $this->assertTrue($postedInvoice->isPosted());
        $this->assertEquals(FiscalCategory::TaxInvoice, $postedInvoice->fiscal_category);
        $this->assertEquals(FiscalStatus::Sealed, $postedInvoice->fiscal_status);
        $this->assertTrue($postedInvoice->isSealed());
        $this->assertNotNull($postedInvoice->fiscal_hash);
        $this->assertEquals(1, $postedInvoice->chain_sequence);
        $this->assertNull($postedInvoice->previous_hash); // First in chain

        echo "\n✓ Invoice posted and sealed successfully";
        echo "\n  - Fiscal Hash: ".substr($postedInvoice->fiscal_hash, 0, 16).'...';
        echo "\n  - Chain Sequence: ".$postedInvoice->chain_sequence;
    }

    /**
     * @group postgresql
     */
    public function test_sealed_document_immutability_blocks_total_change(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Immutability trigger only works on PostgreSQL. Run with DB_CONNECTION=pgsql');
        }

        $invoice = $this->createAndPostInvoice();

        // Verify the document is sealed
        $this->assertEquals(FiscalStatus::Sealed, $invoice->fiscal_status);
        $this->assertNotNull($invoice->fiscal_hash);

        // Try to modify the total - this should be blocked by the trigger
        try {
            DB::table('documents')
                ->where('id', $invoice->id)
                ->update(['total' => '9999.99']);

            $this->fail('Expected trigger to block total modification on sealed document');
        } catch (\Illuminate\Database\QueryException $e) {
            // Verify we got the expected trigger error
            $this->assertStringContainsString('sealed', strtolower($e->getMessage()));
            echo "\n✓ Immutability trigger correctly blocked total modification";
            echo "\n  - Error: ".substr($e->getMessage(), 0, 80).'...';
        }
    }

    /**
     * @group postgresql
     */
    public function test_sealed_document_immutability_blocks_subtotal_change(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Immutability trigger only works on PostgreSQL');
        }

        $invoice = $this->createAndPostInvoice();

        try {
            DB::table('documents')
                ->where('id', $invoice->id)
                ->update(['subtotal' => '8000.00']);

            $this->fail('Expected trigger to block subtotal modification');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('sealed', strtolower($e->getMessage()));
            echo "\n✓ Immutability trigger correctly blocked subtotal modification";
        }
    }

    /**
     * @group postgresql
     */
    public function test_sealed_document_immutability_blocks_document_number_change(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Immutability trigger only works on PostgreSQL');
        }

        $invoice = $this->createAndPostInvoice();

        try {
            DB::table('documents')
                ->where('id', $invoice->id)
                ->update(['document_number' => 'FAKE-001']);

            $this->fail('Expected trigger to block document_number modification');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('sealed', strtolower($e->getMessage()));
            echo "\n✓ Immutability trigger correctly blocked document_number modification";
        }
    }

    /**
     * @group postgresql
     */
    public function test_sealed_document_immutability_blocks_fiscal_hash_change(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Immutability trigger only works on PostgreSQL');
        }

        $invoice = $this->createAndPostInvoice();

        try {
            DB::table('documents')
                ->where('id', $invoice->id)
                ->update(['fiscal_hash' => 'tampered_hash_value']);

            $this->fail('Expected trigger to block fiscal_hash modification');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('sealed', strtolower($e->getMessage()));
            echo "\n✓ Immutability trigger correctly blocked fiscal_hash modification";
        }
    }

    public function test_sealed_document_allows_balance_due_updates(): void
    {
        $invoice = $this->createAndPostInvoice();
        $originalTotal = $invoice->total;

        // Update balance_due (simulating payment allocation) - this should work
        $invoice->update(['balance_due' => '500.00']);
        $invoice->refresh();

        $this->assertEquals('500.00', $invoice->balance_due);
        $this->assertEquals($originalTotal, $invoice->total); // Total unchanged

        echo "\n✓ Balance due updated on sealed document: {$invoice->balance_due}";
    }

    public function test_hash_chain_integrity(): void
    {
        // Create and post multiple invoices to build a chain
        $invoice1 = $this->createAndPostInvoice('INV-2025-0001');
        $invoice2 = $this->createAndPostInvoice('INV-2025-0002');
        $invoice3 = $this->createAndPostInvoice('INV-2025-0003');

        // Verify chain linkage
        $this->assertNull($invoice1->previous_hash);
        $this->assertEquals($invoice1->fiscal_hash, $invoice2->previous_hash);
        $this->assertEquals($invoice2->fiscal_hash, $invoice3->previous_hash);

        // Verify chain sequence
        $this->assertEquals(1, $invoice1->chain_sequence);
        $this->assertEquals(2, $invoice2->chain_sequence);
        $this->assertEquals(3, $invoice3->chain_sequence);

        // Verify hash chain integrity using FiscalHashService
        $chainDocuments = Document::where('company_id', $this->company->id)
            ->where('type', DocumentType::Invoice)
            ->where('status', DocumentStatus::Posted)
            ->orderBy('chain_sequence')
            ->get()
            ->map(function (Document $doc) {
                $input = $this->hashService->serializeForHashing([
                    'document_number' => $doc->document_number,
                    'posted_at' => $doc->updated_at->toDateString(),
                    'total' => $doc->total ?? '0.00',
                    'currency' => $doc->currency,
                ]);

                return [
                    'input' => $input,
                    'hash' => $doc->fiscal_hash,
                    'previous_hash' => $doc->previous_hash,
                ];
            })
            ->toArray();

        // Pass the company's genesis seed for chain verification
        $isValid = $this->hashService->verifyChain($chainDocuments, $this->company->fiscal_chain_seed);
        $this->assertTrue($isValid, 'Hash chain integrity check failed');

        echo "\n✓ Hash chain verified for 3 invoices";
        echo "\n  - Chain 1: ".substr($invoice1->fiscal_hash, 0, 16).'...';
        echo "\n  - Chain 2: ".substr($invoice2->fiscal_hash, 0, 16).'... (prev: '.substr($invoice2->previous_hash, 0, 8).'...)';
        echo "\n  - Chain 3: ".substr($invoice3->fiscal_hash, 0, 16).'... (prev: '.substr($invoice3->previous_hash, 0, 8).'...)';
    }

    public function test_cancellation_voids_fiscal_status(): void
    {
        $invoice = $this->createAndPostInvoice();

        // Verify it's sealed
        $this->assertEquals(FiscalStatus::Sealed, $invoice->fiscal_status);

        // Cancel the invoice
        $cancelledInvoice = $this->postingService->cancel($invoice);

        // Verify voided status
        $this->assertTrue($cancelledInvoice->isCancelled());
        $this->assertEquals(FiscalStatus::Voided, $cancelledInvoice->fiscal_status);
        $this->assertTrue($cancelledInvoice->isVoided());

        // Hash should still be present (for audit trail)
        $this->assertNotNull($cancelledInvoice->fiscal_hash);

        echo "\n✓ Invoice cancelled and voided";
        echo "\n  - Fiscal Status: ".$cancelledInvoice->fiscal_status->value;
    }

    public function test_credit_note_has_separate_chain(): void
    {
        // Post an invoice
        $invoice = $this->createAndPostInvoice('INV-2025-0001');

        // Create and post a credit note
        $creditNote = $this->createDocument(DocumentType::CreditNote, 'CN-2025-0001');
        $creditNote->update(['status' => DocumentStatus::Confirmed]);
        $postedCreditNote = $this->postingService->post($creditNote);

        // Verify credit note has its own chain
        $this->assertEquals(FiscalCategory::CreditNote, $postedCreditNote->fiscal_category);
        $this->assertEquals(FiscalStatus::Sealed, $postedCreditNote->fiscal_status);
        $this->assertEquals(1, $postedCreditNote->chain_sequence); // First in credit note chain
        $this->assertNull($postedCreditNote->previous_hash); // Not linked to invoice chain

        // Hashes should be different
        $this->assertNotEquals($invoice->fiscal_hash, $postedCreditNote->fiscal_hash);

        echo "\n✓ Credit note has separate chain from invoices";
        echo "\n  - Invoice chain seq: {$invoice->chain_sequence}";
        echo "\n  - Credit note chain seq: {$postedCreditNote->chain_sequence}";
    }

    public function test_document_data_dto_includes_fiscal_fields(): void
    {
        $invoice = $this->createAndPostInvoice();

        // Use the DTO directly to verify fiscal fields are included
        $dto = \App\Modules\Document\Application\DTOs\DocumentData::fromModel($invoice);

        $this->assertEquals('TAX_INVOICE', $dto->fiscal_category);
        $this->assertEquals('SEALED', $dto->fiscal_status);
        $this->assertTrue($dto->is_sealed);
        $this->assertTrue($dto->is_fiscal);

        echo "\n✓ DocumentData DTO includes fiscal fields correctly";
        echo "\n  - fiscal_category: {$dto->fiscal_category}";
        echo "\n  - fiscal_status: {$dto->fiscal_status}";
        echo "\n  - is_sealed: ".($dto->is_sealed ? 'true' : 'false');
        echo "\n  - is_fiscal: ".($dto->is_fiscal ? 'true' : 'false');
    }

    public function test_company_has_unique_genesis_seed(): void
    {
        // Verify our test company has a genesis seed
        $this->assertNotNull($this->company->fiscal_chain_seed);
        $this->assertEquals(64, strlen($this->company->fiscal_chain_seed)); // 256-bit = 64 hex chars

        // Create another company and verify it has a different seed
        $company2 = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->assertNotNull($company2->fiscal_chain_seed);
        $this->assertNotEquals($this->company->fiscal_chain_seed, $company2->fiscal_chain_seed);

        echo "\n✓ Companies have unique 256-bit genesis seeds";
        echo "\n  - Company 1 seed: ".substr($this->company->fiscal_chain_seed, 0, 16).'...';
        echo "\n  - Company 2 seed: ".substr($company2->fiscal_chain_seed, 0, 16).'...';
    }

    public function test_genesis_seed_used_in_first_document_hash(): void
    {
        // Create and post an invoice
        $invoice = $this->createAndPostInvoice('INV-GENESIS-001');

        // First document should have no previous_hash
        $this->assertNull($invoice->previous_hash);
        $this->assertEquals(1, $invoice->chain_sequence);

        // Manually calculate the expected hash using the genesis seed
        $input = $this->hashService->serializeForHashing([
            'document_number' => $invoice->document_number,
            'posted_at' => $invoice->updated_at->toDateString(),
            'total' => $invoice->total ?? '0.00',
            'currency' => $invoice->currency,
        ]);

        // Hash with genesis seed should match stored hash
        $expectedHash = $this->hashService->calculateHash(
            $input,
            null, // no previous hash
            $this->company->fiscal_chain_seed
        );

        $this->assertEquals($expectedHash, $invoice->fiscal_hash);

        // Verify that WITHOUT genesis seed, the hash would be different
        $wrongHash = $this->hashService->calculateHash($input, null); // no seed
        $this->assertNotEquals($wrongHash, $invoice->fiscal_hash);

        echo "\n✓ Genesis document uses company's unique seed in hash calculation";
        echo "\n  - With seed: ".substr($invoice->fiscal_hash, 0, 16).'...';
        echo "\n  - Without seed would be: ".substr($wrongHash, 0, 16).'...';
    }

    public function test_different_companies_have_different_genesis_hashes(): void
    {
        // Create a second company with the same tenant
        $company2 = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $partner2 = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
        ]);

        // Create identical invoices in both companies
        $invoice1 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'INV-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
        ]);

        $invoice2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'partner_id' => $partner2->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => 'INV-002', // Different number (unique constraint is tenant+type+number)
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '1000.00', // Same amounts to show hash differs due to seed
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
        ]);

        // Post both invoices
        $posted1 = $this->postingService->post($invoice1);
        $posted2 = $this->postingService->post($invoice2);

        // Both are genesis documents (first in their respective chains)
        $this->assertEquals(1, $posted1->chain_sequence);
        $this->assertEquals(1, $posted2->chain_sequence);
        $this->assertNull($posted1->previous_hash);
        $this->assertNull($posted2->previous_hash);

        // But their fiscal hashes MUST be different because of unique genesis seeds
        $this->assertNotEquals($posted1->fiscal_hash, $posted2->fiscal_hash);

        echo "\n✓ Different companies have different genesis hashes for identical documents";
        echo "\n  - Company 1 hash: ".substr($posted1->fiscal_hash, 0, 16).'...';
        echo "\n  - Company 2 hash: ".substr($posted2->fiscal_hash, 0, 16).'...';
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createInvoice(string $documentNumber = 'INV-2025-0001'): Document
    {
        return $this->createDocument(DocumentType::Invoice, $documentNumber);
    }

    private function createDocument(DocumentType $type, string $documentNumber): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'document_number' => $documentNumber,
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '1000.00',
            'tax_amount' => '190.00',
            'total' => '1190.00',
            'balance_due' => '1190.00',
        ]);
    }

    private function createAndPostInvoice(?string $documentNumber = null): Document
    {
        static $counter = 0;
        $counter++;

        $invoice = $this->createInvoice($documentNumber ?? 'INV-2025-'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT));
        $invoice->update(['status' => DocumentStatus::Confirmed]);

        return $this->postingService->post($invoice);
    }
}
