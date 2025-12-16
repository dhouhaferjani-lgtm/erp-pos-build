<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Services\FiscalHashService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoiceCancelled;
use App\Modules\Document\Domain\Events\InvoicePosted;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DocumentPostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentPostingService $postingService;

    private FiscalHashService $hashService;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->postingService = app(DocumentPostingService::class);
        $this->hashService = app(FiscalHashService::class);

        // Create test tenant, company, and partner
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_posting_invoice_creates_fiscal_hash(): void
    {
        Event::fake([InvoicePosted::class]);

        $invoice = $this->createConfirmedDocument(DocumentType::Invoice);

        $postedInvoice = $this->postingService->post($invoice);

        $this->assertEquals(DocumentStatus::Posted, $postedInvoice->status);
        $this->assertNotNull($postedInvoice->fiscal_hash);
        $this->assertNull($postedInvoice->previous_hash); // First document has no previous hash
        $this->assertEquals(1, $postedInvoice->chain_sequence);

        Event::assertDispatched(InvoicePosted::class, function (InvoicePosted $event) use ($postedInvoice) {
            return $event->invoiceId === $postedInvoice->id
                && $event->fiscalHash === $postedInvoice->fiscal_hash
                && $event->chainSequence === 1;
        });
    }

    public function test_posting_credit_note_creates_fiscal_hash(): void
    {
        Event::fake([InvoicePosted::class]);

        $creditNote = $this->createConfirmedDocument(DocumentType::CreditNote);

        $postedCreditNote = $this->postingService->post($creditNote);

        $this->assertEquals(DocumentStatus::Posted, $postedCreditNote->status);
        $this->assertNotNull($postedCreditNote->fiscal_hash);
        $this->assertEquals(1, $postedCreditNote->chain_sequence);

        Event::assertDispatched(InvoicePosted::class);
    }

    public function test_posting_non_fiscal_document_does_not_create_hash(): void
    {
        Event::fake([InvoicePosted::class]);

        $quote = $this->createConfirmedDocument(DocumentType::Quote);

        $postedQuote = $this->postingService->post($quote);

        $this->assertEquals(DocumentStatus::Posted, $postedQuote->status);
        $this->assertNull($postedQuote->fiscal_hash);
        $this->assertNull($postedQuote->chain_sequence);

        Event::assertNotDispatched(InvoicePosted::class);
    }

    public function test_sequential_invoices_create_linked_hash_chain(): void
    {
        Event::fake([InvoicePosted::class]);

        // Post first invoice
        $invoice1 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-001');
        $postedInvoice1 = $this->postingService->post($invoice1);

        // Post second invoice
        $invoice2 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-002');
        $postedInvoice2 = $this->postingService->post($invoice2);

        // Post third invoice
        $invoice3 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-003');
        $postedInvoice3 = $this->postingService->post($invoice3);

        // Verify chain linking
        $this->assertNull($postedInvoice1->previous_hash);
        $this->assertEquals(1, $postedInvoice1->chain_sequence);

        $this->assertEquals($postedInvoice1->fiscal_hash, $postedInvoice2->previous_hash);
        $this->assertEquals(2, $postedInvoice2->chain_sequence);

        $this->assertEquals($postedInvoice2->fiscal_hash, $postedInvoice3->previous_hash);
        $this->assertEquals(3, $postedInvoice3->chain_sequence);
    }

    public function test_invoice_and_credit_note_have_separate_chains(): void
    {
        Event::fake([InvoicePosted::class]);

        // Post invoice
        $invoice = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-001');
        $postedInvoice = $this->postingService->post($invoice);

        // Post credit note
        $creditNote = $this->createConfirmedDocument(DocumentType::CreditNote, 'CN-001');
        $postedCreditNote = $this->postingService->post($creditNote);

        // Post second invoice
        $invoice2 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-002');
        $postedInvoice2 = $this->postingService->post($invoice2);

        // Verify separate chains
        $this->assertEquals(1, $postedInvoice->chain_sequence);
        $this->assertEquals(1, $postedCreditNote->chain_sequence); // Separate chain
        $this->assertEquals(2, $postedInvoice2->chain_sequence);

        // Credit note's previous hash should be null (first in its chain)
        $this->assertNull($postedCreditNote->previous_hash);

        // Invoice 2 should link to Invoice 1, not credit note
        $this->assertEquals($postedInvoice->fiscal_hash, $postedInvoice2->previous_hash);
    }

    public function test_hash_chain_is_verifiable(): void
    {
        Event::fake([InvoicePosted::class]);

        // Create a chain of invoices
        $invoice1 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-001');
        $postedInvoice1 = $this->postingService->post($invoice1);

        $invoice2 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-002');
        $postedInvoice2 = $this->postingService->post($invoice2);

        // Get the company's genesis seed for hash verification
        $genesisSeed = $this->company->fiscal_chain_seed;

        // Manually verify the chain
        $input1 = $this->hashService->serializeForHashing([
            'document_number' => $postedInvoice1->document_number,
            'posted_at' => $postedInvoice1->document_date->toDateString(),
            'total' => $postedInvoice1->total ?? '0.00',
            'currency' => $postedInvoice1->currency,
        ]);

        // First document uses genesis seed (no previous hash)
        $expectedHash1 = $this->hashService->calculateHash($input1, null, $genesisSeed);
        $this->assertEquals($expectedHash1, $postedInvoice1->fiscal_hash);

        $input2 = $this->hashService->serializeForHashing([
            'document_number' => $postedInvoice2->document_number,
            'posted_at' => $postedInvoice2->document_date->toDateString(),
            'total' => $postedInvoice2->total ?? '0.00',
            'currency' => $postedInvoice2->currency,
        ]);

        // Subsequent documents use the previous document's hash
        $expectedHash2 = $this->hashService->calculateHash($input2, $postedInvoice1->fiscal_hash);
        $this->assertEquals($expectedHash2, $postedInvoice2->fiscal_hash);
    }

    public function test_posting_unconfirmed_document_throws_exception(): void
    {
        $draftInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-DRAFT',
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '100.00',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only confirmed documents can be posted');

        $this->postingService->post($draftInvoice);
    }

    public function test_cancelling_posted_invoice_dispatches_event(): void
    {
        Event::fake([InvoicePosted::class, InvoiceCancelled::class]);

        $invoice = $this->createConfirmedDocument(DocumentType::Invoice);
        $postedInvoice = $this->postingService->post($invoice);

        $cancelledInvoice = $this->postingService->cancel($postedInvoice);

        $this->assertEquals(DocumentStatus::Cancelled, $cancelledInvoice->status);
        // Fiscal hash should be preserved
        $this->assertNotNull($cancelledInvoice->fiscal_hash);

        Event::assertDispatched(InvoiceCancelled::class, function (InvoiceCancelled $event) use ($cancelledInvoice) {
            return $event->invoiceId === $cancelledInvoice->id
                && $event->originalFiscalHash === $cancelledInvoice->fiscal_hash;
        });
    }

    public function test_cancelling_non_fiscal_document_does_not_dispatch_event(): void
    {
        Event::fake([InvoicePosted::class, InvoiceCancelled::class]);

        $quote = $this->createConfirmedDocument(DocumentType::Quote);
        $postedQuote = $this->postingService->post($quote);

        $cancelledQuote = $this->postingService->cancel($postedQuote);

        $this->assertEquals(DocumentStatus::Cancelled, $cancelledQuote->status);

        Event::assertNotDispatched(InvoiceCancelled::class);
    }

    public function test_cancelling_unposted_document_throws_exception(): void
    {
        $confirmedInvoice = $this->createConfirmedDocument(DocumentType::Invoice);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Only posted documents can be cancelled');

        $this->postingService->cancel($confirmedInvoice);
    }

    public function test_different_companies_have_separate_chains(): void
    {
        Event::fake([InvoicePosted::class]);

        // Create second company
        $company2 = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $partner2 = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
        ]);

        // Post invoice for company 1
        $invoice1 = $this->createConfirmedDocument(DocumentType::Invoice, 'INV-001');
        $postedInvoice1 = $this->postingService->post($invoice1);

        // Post invoice for company 2
        $invoice2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company2->id,
            'partner_id' => $partner2->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'C2-INV-001',
            'document_date' => now(),
            'currency' => 'EUR',
            'total' => '200.00',
        ]);
        $postedInvoice2 = $this->postingService->post($invoice2);

        // Both should be first in their respective chains
        $this->assertEquals(1, $postedInvoice1->chain_sequence);
        $this->assertEquals(1, $postedInvoice2->chain_sequence);
        $this->assertNull($postedInvoice1->previous_hash);
        $this->assertNull($postedInvoice2->previous_hash);
    }

    /**
     * Create a confirmed document for testing.
     */
    private function createConfirmedDocument(DocumentType $type, ?string $documentNumber = null): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $documentNumber ?? $type->value.'-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '100.00',
            'tax_amount' => '20.00',
            'total' => '120.00',
        ]);
    }
}
