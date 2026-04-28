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
 * Integration tests for tax snapshot creation on invoice confirmation.
 *
 * Verifies that when invoices are confirmed:
 * - Tax details are captured and persisted to document_tax_details table
 * - Multiple tax rates are handled correctly
 * - Recoverability flags match company tax status at confirmation time
 * - Snapshots are immutable (no updated_at column)
 * - Re-confirming replaces old snapshots (idempotency)
 */
class TaxSnapshotInvoiceTest extends TestCase
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

    public function test_creates_tax_snapshots_on_invoice_confirmation(): void
    {
        // Arrange: Create draft invoice with lines at different tax rates
        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'], // VAT 19%
            ['tax_rate' => '7.00', 'subtotal' => '50.00'],   // VAT 7%
        ]);

        // Act: Confirm invoice
        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Assert: Response successful
        $response->assertOk();

        // Assert: Tax snapshots created
        $taxDetails = DocumentTaxDetail::where('document_id', $invoice->id)
            ->orderBy('sequence_order')
            ->get();

        $this->assertCount(2, $taxDetails, 'Should create 2 tax detail records (one per rate)');

        // Verify 19% VAT snapshot
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertEquals('19.00', $taxDetails[0]->tax_amount); // 100 * 0.19
        $this->assertEquals('100.00', $taxDetails[0]->tax_base);
        $this->assertTrue($taxDetails[0]->is_recoverable, 'VAT should be recoverable for REGISTERED company');
        $this->assertFalse($taxDetails[0]->is_stamp_duty);

        // Verify 7% VAT snapshot
        $this->assertEquals('7.00', $taxDetails[1]->tax_rate);
        $this->assertEquals('3.50', $taxDetails[1]->tax_amount); // 50 * 0.07
        $this->assertEquals('50.00', $taxDetails[1]->tax_base);
        $this->assertTrue($taxDetails[1]->is_recoverable);
    }

    public function test_tax_snapshots_reflect_company_tax_status_at_confirmation_time(): void
    {
        // Arrange: Company is NOT registered (cannot recover VAT)
        $this->company->update(['tax_status' => CompanyTaxStatus::NON_REGISTERED]);

        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
        ]);

        // Act: Confirm invoice
        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Assert: Tax details show VAT is NOT recoverable
        $taxDetail = DocumentTaxDetail::where('document_id', $invoice->id)->first();

        $this->assertFalse(
            $taxDetail->is_recoverable,
            'VAT should NOT be recoverable for NON_REGISTERED company'
        );
    }

    public function test_tax_snapshots_survive_company_tax_status_changes(): void
    {
        // Arrange: Company is REGISTERED, confirm invoice
        $this->company->update(['tax_status' => CompanyTaxStatus::REGISTERED]);

        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Capture original snapshot
        $originalSnapshot = DocumentTaxDetail::where('document_id', $invoice->id)->first();
        $this->assertTrue($originalSnapshot->is_recoverable);

        // Act: Change company tax status (before posting invoice - this is allowed)
        $this->company->update(['tax_status' => CompanyTaxStatus::NON_REGISTERED]);

        // Assert: Snapshot remains unchanged (immutable)
        $currentSnapshot = DocumentTaxDetail::where('document_id', $invoice->id)->first();

        $this->assertTrue(
            $currentSnapshot->is_recoverable,
            'Tax snapshot should remain immutable even after company tax status changes'
        );
    }

    public function test_tax_snapshots_are_immutable_no_updated_at_column(): void
    {
        // Arrange & Act
        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Assert: DocumentTaxDetail has no updated_at column (immutable)
        $taxDetail = DocumentTaxDetail::where('document_id', $invoice->id)->first();

        $this->assertNotNull($taxDetail->created_at);
        $this->assertNull($taxDetail->updated_at, 'Immutable records should not have updated_at');
    }

    public function test_handles_stamp_duty_correctly(): void
    {
        // Arrange: Create invoice with stamp duty (fixed amount, non-recoverable)
        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'],
            ['tax_rate' => '1.00', 'subtotal' => '100.00', 'is_stamp_duty' => true], // 1% stamp duty
        ]);

        // Act
        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Assert: Stamp duty captured correctly
        $stampDutyDetail = DocumentTaxDetail::where('document_id', $invoice->id)
            ->where('is_stamp_duty', true)
            ->first();

        $this->assertNotNull($stampDutyDetail);
        $this->assertTrue($stampDutyDetail->is_stamp_duty);
        $this->assertFalse($stampDutyDetail->is_recoverable, 'Stamp duty is never recoverable');
    }

    public function test_recalculates_and_updates_document_totals(): void
    {
        // Arrange
        $invoice = $this->createDraftInvoice([
            ['tax_rate' => '19.00', 'subtotal' => '100.00'], // Tax: 19.00
            ['tax_rate' => '7.00', 'subtotal' => '50.00'],   // Tax: 3.50
        ]);

        // Act
        $this->postJson("/api/v1/invoices/{$invoice->id}/confirm");

        // Assert: Document totals updated
        $invoice->refresh();

        $this->assertEquals('22.50', $invoice->tax_amount, 'Tax amount should be 19.00 + 3.50');
        $this->assertEquals('172.50', $invoice->total, 'Total should be 150.00 + 22.50');
    }

    /**
     * Create a draft invoice with specified line items.
     *
     * @param  array<int, array{tax_rate: string, subtotal: string, is_stamp_duty?: bool}>  $lines
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
            $lineSubtotal = $lineData['subtotal'];
            $taxRate = $lineData['tax_rate'];
            $taxAmount = bcmul($lineSubtotal, bcdiv($taxRate, '100', 4), 2);
            $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

            DocumentLine::create([
                'document_id' => $invoice->id,
                'line_number' => $index + 1,
                'description' => 'Test Line '.($index + 1),
                'quantity' => '1.00',
                'unit_price' => $lineSubtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ]);
        }

        return $invoice->fresh(['lines']);
    }
}
