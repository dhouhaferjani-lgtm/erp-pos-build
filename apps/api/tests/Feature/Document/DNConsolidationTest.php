<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentConversionService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for DN → Invoice consolidation (Tunisia model).
 *
 * Tunisia compliance requires:
 * - Multiple delivery notes can be consolidated into a single invoice
 * - All DNs must be invoiced by fiscal year end
 * - Track which DNs have been invoiced (invoiced_at, invoice_id)
 * - Invoice references all source DNs
 */
class DNConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private DocumentConversionService $conversionService;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->conversionService = app(DocumentConversionService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_can_create_invoice_from_single_delivery_note(): void
    {
        $dn = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn]);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
        $this->assertEquals(DocumentStatus::Draft, $invoice->status);
        $this->assertCount(1, $invoice->lines);

        // Verify totals match DN
        $this->assertEquals('500.00', $invoice->subtotal);
        $this->assertEquals('95.00', $invoice->tax_amount); // 500 * 19%
        $this->assertEquals('595.00', $invoice->total);
    }

    public function test_can_consolidate_multiple_delivery_notes_into_single_invoice(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00', 'tax_rate' => '19.00'],
        ]);

        $dn3 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product C', 'quantity' => '2.00', 'unit_price' => '200.00', 'tax_rate' => '19.00'],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2, $dn3]);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);

        // All 3 DN lines should be in the invoice
        $this->assertCount(3, $invoice->lines);

        // Verify consolidated totals
        // DN1: 500.00 + DN2: 500.00 + DN3: 400.00 = 1400.00 subtotal
        // Tax: 1400 * 19% = 266.00
        // Total: 1666.00
        $this->assertEquals('1400.00', $invoice->subtotal);
        $this->assertEquals('266.00', $invoice->tax_amount);
        $this->assertEquals('1666.00', $invoice->total);
    }

    public function test_invoice_references_all_source_delivery_notes(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);

        // Payload should contain all source DN IDs
        $payload = $invoice->payload ?? [];
        $this->assertArrayHasKey('source_delivery_note_ids', $payload);
        $this->assertCount(2, $payload['source_delivery_note_ids']);
        $this->assertContains($dn1->id, $payload['source_delivery_note_ids']);
        $this->assertContains($dn2->id, $payload['source_delivery_note_ids']);
    }

    public function test_delivery_notes_are_marked_as_invoiced(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $this->assertNull($dn1->fresh()->payload['invoiced_at'] ?? null);
        $this->assertNull($dn2->fresh()->payload['invoiced_at'] ?? null);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);

        // Both DNs should now be marked as invoiced
        $dn1->refresh();
        $dn2->refresh();

        $this->assertNotNull($dn1->payload['invoiced_at']);
        $this->assertNotNull($dn2->payload['invoiced_at']);
        $this->assertEquals($invoice->id, $dn1->payload['invoice_id']);
        $this->assertEquals($invoice->id, $dn2->payload['invoice_id']);
    }

    public function test_cannot_invoice_already_invoiced_delivery_note(): void
    {
        $dn = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        // First invoice creation should succeed
        $this->conversionService->createInvoiceFromDeliveryNotes([$dn]);

        // Second attempt should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Delivery note has already been invoiced');

        $this->conversionService->createInvoiceFromDeliveryNotes([$dn->fresh()]);
    }

    public function test_cannot_create_invoice_from_draft_delivery_note(): void
    {
        $dn = $this->createDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ], DocumentStatus::Draft);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Delivery note must be confirmed before invoicing');

        $this->conversionService->createInvoiceFromDeliveryNotes([$dn]);
    }

    public function test_cannot_create_invoice_from_empty_delivery_notes_array(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one delivery note is required');

        $this->conversionService->createInvoiceFromDeliveryNotes([]);
    }

    public function test_delivery_notes_must_belong_to_same_partner(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        // Create DN for different partner
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ], $otherPartner);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('All delivery notes must belong to the same partner');

        $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);
    }

    public function test_delivery_notes_must_belong_to_same_company(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        // Create DN for different company
        $otherCompany = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'partner_id' => $otherPartner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-' . time() . '-OTHER',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
        ]);

        DocumentLine::create([
            'document_id' => $dn2->id,
            'line_number' => 1,
            'description' => 'Product B',
            'quantity' => '10.00',
            'unit_price' => '50.00',
            'line_total' => '500.00',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('All delivery notes must belong to the same company');

        $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);
    }

    public function test_delivery_notes_must_have_same_currency(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        // Create DN with different currency
        $dn2 = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'DN-' . time() . '-EUR',
            'document_date' => now(),
            'currency' => 'EUR', // Different currency
            'subtotal' => '500.00',
            'tax_amount' => '0.00',
            'total' => '500.00',
        ]);

        DocumentLine::create([
            'document_id' => $dn2->id,
            'line_number' => 1,
            'description' => 'Product B',
            'quantity' => '10.00',
            'unit_price' => '50.00',
            'line_total' => '500.00',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('All delivery notes must have the same currency');

        $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);
    }

    public function test_invoice_lines_link_to_source_delivery_note_lines(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);

        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);

        // Each invoice line should link to its source DN line
        foreach ($invoice->lines as $invLine) {
            $this->assertNotNull($invLine->source_line_id);

            // Find the source line
            $sourceLine = DocumentLine::find($invLine->source_line_id);
            $this->assertNotNull($sourceLine);

            // Verify it came from one of our DNs
            $this->assertContains($sourceLine->document_id, [$dn1->id, $dn2->id]);
        }
    }

    public function test_can_query_uninvoiced_delivery_notes(): void
    {
        // Create 3 DNs
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);
        $dn3 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product C', 'quantity' => '2.00', 'unit_price' => '200.00'],
        ]);

        // Invoice only dn1
        $this->conversionService->createInvoiceFromDeliveryNotes([$dn1]);

        // Query uninvoiced DNs
        $uninvoiced = Document::where('type', DocumentType::DeliveryNote)
            ->where('company_id', $this->company->id)
            ->where('status', DocumentStatus::Confirmed)
            ->where(function ($query) {
                $query->whereNull('payload->invoiced_at')
                    ->orWhereJsonContains('payload', ['invoiced_at' => null]);
            })
            ->get();

        // Should find dn2 and dn3
        $this->assertCount(2, $uninvoiced);
        $uninvoicedIds = $uninvoiced->pluck('id')->toArray();
        $this->assertContains($dn2->id, $uninvoicedIds);
        $this->assertContains($dn3->id, $uninvoicedIds);
        $this->assertNotContains($dn1->id, $uninvoicedIds);
    }

    public function test_invoice_preserves_dn_line_details(): void
    {
        $dn = $this->createConfirmedDeliveryNote([
            [
                'description' => 'Premium Service',
                'quantity' => '3.00',
                'unit_price' => '150.00',
                'tax_rate' => '19.00',
                'notes' => 'Special handling required',
            ],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn]);

        $invLine = $invoice->lines->first();
        $this->assertEquals('Premium Service', $invLine->description);
        $this->assertEquals('3.0000', $invLine->quantity);
        $this->assertEquals('150.00', $invLine->unit_price);
        $this->assertEquals('19.00', $invLine->tax_rate);
        $this->assertEquals('Special handling required', $invLine->notes);
    }

    public function test_consolidated_invoice_has_proper_reference(): void
    {
        $dn1 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product A', 'quantity' => '5.00', 'unit_price' => '100.00'],
        ]);
        $dn2 = $this->createConfirmedDeliveryNote([
            ['description' => 'Product B', 'quantity' => '10.00', 'unit_price' => '50.00'],
        ]);

        $invoice = $this->conversionService->createInvoiceFromDeliveryNotes([$dn1, $dn2]);

        // Reference should include both DN numbers
        $this->assertStringContainsString($dn1->document_number, $invoice->reference);
        $this->assertStringContainsString($dn2->document_number, $invoice->reference);
    }

    /**
     * Create a delivery note with the given lines.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string, notes?: string}>  $lines
     */
    private function createDeliveryNote(array $lines, DocumentStatus $status = DocumentStatus::Draft, ?Partner $partner = null): Document
    {
        $partner ??= $this->partner;

        $dn = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => $status,
            'document_number' => 'DN-' . time() . '-' . random_int(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        $subtotal = '0.00';
        $taxAmount = '0.00';

        foreach ($lines as $index => $lineData) {
            $lineTotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineTotal, 2);

            $taxRate = $lineData['tax_rate'] ?? '0.00';
            if (bccomp($taxRate, '0.00', 2) > 0) {
                $lineTax = bcmul($lineTotal, bcdiv($taxRate, '100', 4), 2);
                $taxAmount = bcadd($taxAmount, $lineTax, 2);
            }

            DocumentLine::create([
                'document_id' => $dn->id,
                'line_number' => $index + 1,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'notes' => $lineData['notes'] ?? null,
            ]);
        }

        $total = bcadd($subtotal, $taxAmount, 2);
        $dn->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);

        return $dn->fresh(['lines']);
    }

    /**
     * Create a confirmed delivery note with the given lines.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string, notes?: string}>  $lines
     */
    private function createConfirmedDeliveryNote(array $lines, ?Partner $partner = null): Document
    {
        return $this->createDeliveryNote($lines, DocumentStatus::Confirmed, $partner);
    }
}
