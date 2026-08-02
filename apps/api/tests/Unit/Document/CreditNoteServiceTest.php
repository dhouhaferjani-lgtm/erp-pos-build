<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\CreditNoteReason;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditNoteService $service;

    private Company $company;

    private Partner $partner;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CreditNoteService::class);

        // Create tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);

        // Create country
        Country::create([
            'code' => 'TN',
            'name' => 'Tunisia',
            'currency_code' => 'TND',
            'currency_symbol' => 'د.ت',
        ]);

        // Create company
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        // Create partner
        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
            'type' => PartnerType::Customer,
        ]);

        // Pin CompanyContext so service-tier tenant+company scoped reads succeed.
        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    /** @test */
    public function it_creates_credit_note_from_invoice(): void
    {
        // Create a posted invoice
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        // Create credit note
        $creditNote = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '1200.00',
            reason: CreditNoteReason::RETURN,
            notes: 'Full refund - product return'
        );

        $this->assertInstanceOf(Document::class, $creditNote);
        $this->assertEquals(DocumentType::CreditNote, $creditNote->type);
        $this->assertEquals(DocumentStatus::Draft, $creditNote->status);
        $this->assertEquals($invoice->id, $creditNote->source_document_id);
        $this->assertEquals($invoice->partner_id, $creditNote->partner_id);
        $this->assertEquals($invoice->company_id, $creditNote->company_id);
        $this->assertEquals('1200.000', $creditNote->total);
        $this->assertEquals(CreditNoteReason::RETURN, $creditNote->credit_note_reason);
        $this->assertEquals('Full refund - product return', $creditNote->notes);
    }

    /** @test */
    public function it_creates_partial_credit_note(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        // Create partial credit note for 600.00
        $creditNote = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
            notes: 'Partial refund'
        );

        $this->assertEquals('600.000', $creditNote->total);
        $this->assertEquals(CreditNoteReason::PRICE_ADJUSTMENT, $creditNote->credit_note_reason);
    }

    /** @test */
    public function it_validates_credit_note_amount_not_exceeding_invoice(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit note amount cannot exceed invoice total');

        $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '1500.00', // Exceeds invoice total
            reason: CreditNoteReason::RETURN,
        );
    }

    /** @test */
    public function it_validates_credit_note_amount_with_existing_credit_notes(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        // Create first credit note for 600.00
        $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );

        // Try to create second credit note that exceeds remaining balance
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Total credit notes would exceed invoice total');

        $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '700.00', // 600 + 700 = 1300 > 1200
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );
    }

    /** @test */
    public function it_only_creates_credit_notes_for_posted_invoices(): void
    {
        $invoice = $this->createInvoice('INV-001', '1000.00', '200.00', '1200.00', DocumentStatus::Draft);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Credit notes can only be created for posted invoices');

        $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '1200.00',
            reason: CreditNoteReason::RETURN,
        );
    }

    /** @test */
    public function it_generates_sequential_credit_note_numbers(): void
    {
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        $cn1 = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );

        $cn2 = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '400.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );

        $this->assertNotEmpty($cn1->document_number);
        $this->assertNotEmpty($cn2->document_number);
        $this->assertNotEquals($cn1->document_number, $cn2->document_number);
        $this->assertStringStartsWith('CN-', $cn1->document_number);
        $this->assertStringStartsWith('CN-', $cn2->document_number);
    }

    /** @test */
    public function it_materializes_prorated_lines_for_amount_based_credit_note(): void
    {
        // Posted invoice with one real line: qty 2 × 500 = 1000 net, 20% VAT = 200, total 1200.
        $invoice = $this->createInvoice('INV-LINE-001', '1000.00', '200.00', '1200.00', DocumentStatus::Posted);
        $sourceLine = DocumentLine::create([
            'document_id' => $invoice->id,
            'product_id' => null,
            'line_number' => 1,
            'description' => 'Widget',
            'quantity' => '2.0000',
            'unit_price' => '500.000',
            'tax_rate' => '20.00',
            'line_total' => '1000.000',
        ]);

        // Credit half the invoice value.
        $creditNote = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::RETURN,
        );

        // The amount path must materialise lines (previously it created none, so
        // confirm recomputed totals from an empty document → unbalanced GL).
        $lines = $creditNote->lines()->get();
        $this->assertCount(1, $lines, 'Amount-based credit note must materialise prorated lines');

        $line = $lines->first();
        $this->assertSame($sourceLine->description, $line->description);
        $this->assertSame('20.00', $line->tax_rate);
        // Prorated: ratio 600/1200 = 0.5 → qty 2 × 0.5 = 1; net 1 × 500 = 500.
        $this->assertSame('1.0000', $line->quantity);
        $this->assertSame('500.000', $line->line_total);

        // Header total stays the requested credit amount.
        $this->assertSame('600.000', $creditNote->total);
    }

    /** @test */
    public function it_generates_collision_free_numbers_when_a_legacy_format_row_exists(): void
    {
        // Simulate a prior session's legacy-format credit note (CN-{seq}, no year segment)
        // sitting alongside a current-format one (CN-{year}-{seq}, produced by
        // DocumentNumberingService via InvoiceToCreditNoteConverter). The old
        // `/CN-(\d+)/` regex greedily matches the YEAR segment of the current-format
        // row and reissues a number that already exists.
        $invoice = $this->createPostedInvoice('INV-001', '1000.00', '200.00', '1200.00');

        $legacy = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CN-00006',
            'document_date' => now()->subDay(),
            'currency' => 'TND',
            'total' => '0.000',
        ]);

        $currentYear = (int) date('Y');
        $current = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Draft,
            'document_number' => sprintf('CN-%d-0006', $currentYear),
            'document_date' => now(),
            'currency' => 'TND',
            'total' => '0.000',
        ]);

        $creditNote = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );

        $this->assertNotEquals($legacy->document_number, $creditNote->document_number);
        $this->assertNotEquals($current->document_number, $creditNote->document_number);
        $this->assertNotSame('CN-02027', $creditNote->document_number, 'must not misparse the year segment as a sequence');

        // Persisted without a unique-constraint violation.
        $this->assertDatabaseHas('documents', [
            'id' => $creditNote->id,
            'document_number' => $creditNote->document_number,
        ]);

        // A second credit note in the same transaction batch must also be collision-free
        // and monotonically distinct from the first.
        $creditNote2 = $this->service->createCreditNote(
            sourceInvoiceId: $invoice->id,
            amount: '600.00',
            reason: CreditNoteReason::PRICE_ADJUSTMENT,
        );

        $this->assertNotEquals($creditNote->document_number, $creditNote2->document_number);
    }

    private function createPostedInvoice(
        string $number,
        string $subtotal,
        string $taxAmount,
        string $total
    ): Document {
        return $this->createInvoice($number, $subtotal, $taxAmount, $total, DocumentStatus::Posted);
    }

    private function createInvoice(
        string $number,
        string $subtotal,
        string $taxAmount,
        string $total,
        DocumentStatus $status
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => $status,
            'document_number' => $number,
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'currency' => 'TND',
        ]);
    }
}
