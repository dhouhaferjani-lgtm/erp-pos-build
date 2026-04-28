<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for tax snapshot creation on credit note confirmation.
 *
 * Credit notes are fiscal documents that must capture tax details for:
 * - Audit trail and compliance
 * - Forensic investigation of returns and refunds
 * - Tax recoverability tracking
 */
class TaxSnapshotCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Requires document confirm endpoint implementation');
    }

    public function test_creates_tax_snapshots_on_credit_note_confirmation(): void
    {
        // Arrange
        $creditNote = $this->createDraftCreditNote([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
            ['tax_rate' => '7.00', 'subtotal' => '50.00'],
        ]);

        // Act
        $response = $this->postJson("/api/v1/credit-notes/{$creditNote->id}/confirm");

        // Assert
        $response->assertOk();

        $taxDetails = DocumentTaxDetail::where('document_id', $creditNote->id)
            ->orderBy('sequence_order')
            ->get();

        $this->assertCount(2, $taxDetails);
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertEquals('7.00', $taxDetails[1]->tax_rate);
        $this->assertTrue($taxDetails[0]->is_recoverable);
    }

    public function test_credit_note_snapshots_reflect_negative_amounts(): void
    {
        // Arrange: Credit notes typically have negative amounts
        $creditNote = $this->createDraftCreditNote([
            ['tax_rate' => '19.00', 'subtotal' => '-100.00'], // Negative for refund
        ]);

        // Act
        $this->postJson("/api/v1/credit-notes/{$creditNote->id}/confirm");

        // Assert: Tax amounts should be negative
        $taxDetail = DocumentTaxDetail::where('document_id', $creditNote->id)->first();

        $this->assertEquals('-100.00', $taxDetail->tax_base);
        $this->assertEquals('-19.00', $taxDetail->tax_amount);
    }

    public function test_credit_note_snapshots_independent_of_original_invoice(): void
    {
        // Arrange: Create invoice, then credit note (without linking for simplicity)
        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
        ]);
        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Change company tax status
        $this->company->update(['tax_status' => CompanyTaxStatus::NON_REGISTERED]);

        // Create credit note after status change
        $creditNote = $this->createDraftCreditNote([
            ['tax_rate' => '19.00', 'subtotal' => '-100.00'],
        ]);

        // Act
        $this->postJson("/api/v1/credit-notes/{$creditNote->id}/confirm");

        // Assert: Credit note snapshot reflects current status (NON_REGISTERED)
        $cnTaxDetail = DocumentTaxDetail::where('document_id', $creditNote->id)->first();
        $invTaxDetail = DocumentTaxDetail::where('document_id', $invoice->id)->first();

        $this->assertFalse($cnTaxDetail->is_recoverable, 'Credit note uses current status');
        $this->assertTrue($invTaxDetail->is_recoverable, 'Original invoice snapshot unchanged');
    }

    /**
     * Create a draft credit note.
     *
     * @param  array<int, array{tax_rate: string, subtotal: string}>  $lines
     */
    private function createDraftCreditNote(array $lines): Document
    {
        $subtotal = array_sum(array_column($lines, 'subtotal'));

        $creditNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::CreditNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'CN-TEST-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => (string) $subtotal,
            'tax_amount' => '0.00',
            'total' => (string) $subtotal,
        ]);

        foreach ($lines as $index => $lineData) {
            $lineSubtotal = $lineData['subtotal'];
            $taxRate = $lineData['tax_rate'];
            $taxAmount = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), 2);
            $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

            DocumentLine::create([
                'document_id' => $creditNote->id,
                'line_number' => $index + 1,
                'description' => 'Credit Line '.($index + 1),
                'quantity' => '1.00',
                'unit_price' => $lineSubtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ]);
        }

        return $creditNote->fresh(['lines']);
    }

    /**
     * Create a draft invoice (helper for testing relationships).
     *
     * @param  array<int, array{tax_rate: string, subtotal: string}>  $lines
     */
    private function createDraftInvoice(array $lines): Document
    {
        $subtotal = array_sum(array_column($lines, 'subtotal'));

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-TEST-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => (string) $subtotal,
            'tax_amount' => '0.00',
            'total' => (string) $subtotal,
        ]);

        foreach ($lines as $index => $lineData) {
            DocumentLine::create([
                'document_id' => $invoice->id,
                'line_number' => $index + 1,
                'description' => 'Test Line '.($index + 1),
                'quantity' => '1.00',
                'unit_price' => $lineData['subtotal'],
                'tax_rate' => $lineData['tax_rate'],
                'line_total' => $lineData['subtotal'],
            ]);
        }

        return $invoice->fresh(['lines']);
    }
}
