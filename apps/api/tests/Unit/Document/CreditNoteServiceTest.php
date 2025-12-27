<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Models\Country;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Application\Services\CreditNoteService;
use App\Modules\Document\Domain\Document;
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
            'domain' => 'test',
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
        $this->assertEquals('1200.00', $creditNote->total);
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

        $this->assertEquals('600.00', $creditNote->total);
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
