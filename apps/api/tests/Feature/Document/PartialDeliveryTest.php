<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DeliveryStatus;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for partial delivery functionality.
 *
 * Tunisia compliance requires:
 * - Multiple delivery notes can be created from a single sales order
 * - Each DN delivers partial quantities of SO lines
 * - Line-level tracking of delivered vs remaining quantities
 * - All DNs must be invoiced by fiscal year end
 */
class PartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private DocumentConverterRegistry $converterRegistry;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->converterRegistry = app(DocumentConverterRegistry::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_can_create_partial_delivery_with_specific_quantities(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
            ['description' => 'Product B', 'quantity' => '20.00', 'unit_price' => '50.00'],
        ]);

        $line1 = $order->lines->firstWhere('description', 'Product A');
        $line2 = $order->lines->firstWhere('description', 'Product B');

        // Deliver partial quantities: 5 of Product A, 10 of Product B
        $deliveryQuantities = [
            $line1->id => '5.00',
            $line2->id => '10.00',
        ];

        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => $deliveryQuantities]);

        $this->assertNotNull($delivery);
        $this->assertEquals(DocumentType::DeliveryNote, $delivery->type);
        $this->assertEquals(DocumentStatus::Draft, $delivery->status);

        // Verify delivery note lines have the partial quantities
        $dnLines = $delivery->lines;
        $this->assertCount(2, $dnLines);

        $dnLine1 = $dnLines->firstWhere('description', 'Product A');
        $dnLine2 = $dnLines->firstWhere('description', 'Product B');

        $this->assertEquals('5.0000', $dnLine1->quantity);
        $this->assertEquals('10.0000', $dnLine2->quantity);
    }

    public function test_partial_delivery_updates_source_line_delivered_quantities(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // Initially, nothing is delivered
        $this->assertEquals('0.0000', $line->quantity_delivered);

        // Deliver 5 units
        $deliveryQuantities = [$line->id => '5.00'];
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => $deliveryQuantities]);

        // Reload the line
        $line->refresh();
        $this->assertEquals('5.0000', $line->quantity_delivered);
    }

    public function test_multiple_partial_deliveries_accumulate_delivered_quantities(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // First delivery: 3 units
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '3.00']]);
        $line->refresh();
        $this->assertEquals('3.0000', $line->quantity_delivered);

        // Second delivery: 4 units
        $order->refresh();
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '4.00']]);
        $line->refresh();
        $this->assertEquals('7.0000', $line->quantity_delivered);

        // Third delivery: 3 units (completing the order)
        $order->refresh();
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '3.00']]);
        $line->refresh();
        $this->assertEquals('10.0000', $line->quantity_delivered);
    }

    public function test_cannot_deliver_more_than_remaining_quantity(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // First delivery: 8 units
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '8.00']]);

        // Try to deliver 5 more (only 2 remaining)
        $order->refresh();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot deliver more than remaining quantity');

        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '5.00']]);
    }

    public function test_delivery_note_lines_link_to_source_order_lines(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $orderLine = $order->lines->first();

        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [
            $orderLine->id => '5.00',
        ]]);

        $deliveryLine = $delivery->lines->first();

        // Verify link to source line
        $this->assertEquals($orderLine->id, $deliveryLine->source_line_id);
    }

    public function test_order_delivery_status_not_delivered_initially(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $this->assertEquals(DeliveryStatus::NotDelivered, $order->getDeliveryStatus());
    }

    public function test_order_delivery_status_partially_delivered(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '5.00']]);

        $order->refresh();
        $this->assertEquals(DeliveryStatus::PartiallyDelivered, $order->getDeliveryStatus());
    }

    public function test_order_delivery_status_fully_delivered(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
            ['description' => 'Product B', 'quantity' => '5.00', 'unit_price' => '50.00'],
        ]);

        $line1 = $order->lines->firstWhere('description', 'Product A');
        $line2 = $order->lines->firstWhere('description', 'Product B');

        // Deliver all quantities
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [
            $line1->id => '10.00',
            $line2->id => '5.00',
        ]]);

        $order->refresh();
        $this->assertEquals(DeliveryStatus::FullyDelivered, $order->getDeliveryStatus());
    }

    public function test_cannot_create_delivery_for_fully_delivered_order(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // Fully deliver the order
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '10.00']]);

        $order->refresh();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales order has already been fully delivered');

        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '1.00']]);
    }

    public function test_line_remaining_quantity_calculation(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // Initially all quantity is remaining
        $this->assertEquals('10.0000', $line->getQuantityRemaining());

        // After partial delivery
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '3.00']]);
        $line->refresh();
        $this->assertEquals('7.0000', $line->getQuantityRemaining());

        // After full delivery
        $order->refresh();
        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '7.00']]);
        $line->refresh();
        $this->assertEquals('0.0000', $line->getQuantityRemaining());
    }

    public function test_partial_delivery_totals_calculated_correctly(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00', 'tax_rate' => '19.00'],
        ]);

        $line = $order->lines->first();

        // Deliver 3 units @ 100.00 = 300.00 subtotal + 57.00 tax = 357.00 total
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [
            $line->id => '3.00',
        ]]);

        $this->assertEquals('300.000', $delivery->subtotal);
        $this->assertEquals('57.000', $delivery->tax_amount);
        $this->assertEquals('357.000', $delivery->total);
    }

    public function test_delivery_notes_array_tracks_all_partial_deliveries(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        // Create three partial deliveries
        $dn1 = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '3.00']]);
        $order->refresh();

        $dn2 = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '4.00']]);
        $order->refresh();

        $dn3 = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [$line->id => '3.00']]);
        $order->refresh();

        // Verify all DNs are tracked
        $payload = $order->payload ?? [];
        $deliveryNoteIds = $payload['delivery_note_ids'] ?? [];

        $this->assertCount(3, $deliveryNoteIds);
        $this->assertContains($dn1->id, $deliveryNoteIds);
        $this->assertContains($dn2->id, $deliveryNoteIds);
        $this->assertContains($dn3->id, $deliveryNoteIds);
    }

    public function test_full_delivery_still_works_via_legacy_method(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
            ['description' => 'Product B', 'quantity' => '5.00', 'unit_price' => '50.00'],
        ]);

        // Use the existing convert method for full delivery
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

        $this->assertCount(2, $delivery->lines);

        // Verify full quantities delivered
        $order->refresh();
        foreach ($order->lines as $line) {
            $this->assertEquals($line->quantity, $line->quantity_delivered);
        }

        $this->assertEquals(DeliveryStatus::FullyDelivered, $order->getDeliveryStatus());
    }

    public function test_cannot_deliver_zero_quantities(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
        ]);

        $line = $order->lines->first();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('At least one line must have a quantity greater than zero');

        $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [
            $line->id => '0.00',
        ]]);
    }

    public function test_only_lines_with_positive_quantities_included_in_delivery(): void
    {
        $order = $this->createConfirmedSalesOrder([
            ['description' => 'Product A', 'quantity' => '10.00', 'unit_price' => '100.00'],
            ['description' => 'Product B', 'quantity' => '5.00', 'unit_price' => '50.00'],
        ]);

        $line1 = $order->lines->firstWhere('description', 'Product A');
        $line2 = $order->lines->firstWhere('description', 'Product B');

        // Only deliver Product A, skip Product B
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote, ['delivery_quantities' => [
            $line1->id => '5.00',
            $line2->id => '0.00',
        ]]);

        // Only one line should be in the delivery note
        $this->assertCount(1, $delivery->lines);
        $this->assertEquals('Product A', $delivery->lines->first()->description);
    }

    /**
     * Create a confirmed sales order with the given lines.
     *
     * @param  array<int, array{description: string, quantity: string, unit_price: string, tax_rate?: string}>  $lines
     */
    private function createConfirmedSalesOrder(array $lines): Document
    {
        $order = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.time().'-'.random_int(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
            'balance_due' => '0.00',
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
                'document_id' => $order->id,
                'line_number' => $index + 1,
                'description' => $lineData['description'],
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $taxRate,
                'line_total' => $lineTotal,
                'quantity_delivered' => '0.00',
            ]);
        }

        $total = bcadd($subtotal, $taxAmount, 2);
        $order->update([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'balance_due' => $total,
        ]);

        return $order->fresh(['lines']);
    }
}
