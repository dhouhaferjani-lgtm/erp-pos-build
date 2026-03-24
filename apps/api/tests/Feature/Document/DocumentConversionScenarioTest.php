<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Company\Domain\Location;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Vehicle\Domain\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tests for Tunisia fiscal compliance: 3-scenario invoicing.
 *
 * - Services only: Can be invoiced directly from SO
 * - Products only: Must have delivery notes before invoicing
 * - Mixed: Physical items must be delivered before invoicing
 */
class DocumentConversionScenarioTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private DocumentConverterRegistry $converterRegistry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
        ]);
        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        // Create default location (required for SO→Invoice and SO→DN conversions)
        Location::create([
            'company_id' => $this->company->id,
            'name' => 'Main Warehouse',
            'type' => \App\Modules\Company\Domain\Enums\LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->converterRegistry = app(DocumentConverterRegistry::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_direct_invoicing_for_services_only_orders(): void
    {
        // Create a service product (non-physical)
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create sales order with services only
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        // Should allow direct conversion to invoice
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
        $this->assertEquals($order->document_number, $invoice->reference);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_auto_creates_delivery_note_for_products_only_orders(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products only
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Should auto-create a delivery note and proceed with invoicing
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_auto_creates_delivery_note_for_mixed_orders(): void
    {
        // Create both service and physical product
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create mixed sales order
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
            ['product_id' => $part->id, 'description' => 'Oil Filter'],
        ]);

        // Should auto-create a delivery note for physical items and proceed
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_invoicing_after_delivery_for_products(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note first
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);
        $this->assertNotNull($delivery);

        // Refresh order to get updated payload
        $order->refresh();

        // Now invoicing should be allowed
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_invoicing(): void
    {
        // Create a service product (non-physical)
        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create and invoice a services-only order
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change Service'],
        ]);

        // First conversion should succeed
        $invoice1 = $this->converterRegistry->convert($order, DocumentType::Invoice);
        $this->assertNotNull($invoice1);

        // Refresh order to get updated payload
        $order->refresh();

        // Second conversion should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales order has already been fully invoiced');

        $this->converterRegistry->convert($order, DocumentType::Invoice);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_invoicing_manual_lines_without_product(): void
    {
        // Create sales order with manual lines (no product_id)
        $order = $this->createConfirmedOrder([
            ['product_id' => null, 'description' => 'Custom Service'],
        ]);

        // Manual lines without product_id should be treated as services
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_marks_delivery_notes_as_invoiced_when_converting_order_to_invoice(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note first (required for physical products)
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);
        $this->assertNotNull($delivery);

        // Verify DN is not yet marked as invoiced
        $delivery->refresh();
        $this->assertNull($delivery->payload['invoiced_at'] ?? null);

        // Refresh order and convert to invoice
        $order->refresh();
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);
        $this->assertNotNull($invoice);

        // Verify DN is now marked as invoiced
        $delivery->refresh();
        $this->assertNotNull($delivery->payload['invoiced_at'] ?? null);
        $this->assertEquals($invoice->id, $delivery->payload['invoice_id'] ?? null);
        $this->assertEquals('order_conversion', $delivery->payload['invoiced_via'] ?? null);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_does_not_show_invoiced_dns_in_consolidation_after_order_conversion(): void
    {
        // Create a physical product
        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create sales order with products
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        // Create delivery note
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);
        $delivery->update(['status' => DocumentStatus::Confirmed]);

        // Query for uninvoiced delivery notes (simulating DN consolidation page query)
        $uninvoicedDns = Document::where('type', DocumentType::DeliveryNote)
            ->where('partner_id', $this->partner->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get()
            ->filter(fn ($dn) => empty($dn->payload['invoiced_at']));

        // Before converting SO to invoice, DN should be available for consolidation
        $this->assertCount(1, $uninvoicedDns);

        // Convert order to invoice
        $order->refresh();
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Query again for uninvoiced delivery notes
        $uninvoicedDnsAfter = Document::where('type', DocumentType::DeliveryNote)
            ->where('partner_id', $this->partner->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get()
            ->filter(fn ($dn) => empty($dn->payload['invoiced_at']));

        // After converting SO to invoice, DN should NOT be available for consolidation
        $this->assertCount(0, $uninvoicedDnsAfter);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function quote_to_order_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create quote with vehicle context
        $quote = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::Quote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'QT-'.time().'-'.rand(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);

        DocumentLine::create([
            'id' => Str::uuid()->toString(),
            'document_id' => $quote->id,
            'line_number' => 1,
            'product_id' => $service->id,
            'description' => 'Oil Change',
            'quantity' => '1.00',
            'unit_price' => '100.00',
            'tax_rate' => '19.00',
            'line_total' => '100.00',
        ]);

        DocumentVehicleContext::create([
            'document_id' => $quote->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $quote->refresh();

        // Convert quote to order
        $order = $this->converterRegistry->convert($quote, DocumentType::SalesOrder);

        // Verify vehicle context was NOT preserved (conversion service doesn't copy it yet)
        $this->assertNotNull($quote->vehicle_id);
        $this->assertEquals($vehicle->id, $quote->vehicle_id);

        // The order should have vehicle context copied from quote
        $this->assertNotNull($order->vehicleContext);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function order_to_delivery_note_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $part = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Part,
            'is_physical' => true,
        ]);

        // Create order with vehicle context
        $order = $this->createConfirmedOrder([
            ['product_id' => $part->id, 'description' => 'Brake Pads'],
        ]);

        DocumentVehicleContext::create([
            'document_id' => $order->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $order->refresh();

        // Convert to delivery note
        $delivery = $this->converterRegistry->convert($order, DocumentType::DeliveryNote);

        // Verify vehicle context was preserved
        $this->assertNotNull($order->vehicle_id);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
        $this->assertNotNull($delivery->vehicle_id);
        $this->assertEquals($vehicle->id, $delivery->vehicle_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function order_to_invoice_preserves_vehicle_context(): void
    {
        $vehicle = Vehicle::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
        ]);

        $service = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => ProductType::Service,
            'is_physical' => false,
        ]);

        // Create services-only order with vehicle context
        $order = $this->createConfirmedOrder([
            ['product_id' => $service->id, 'description' => 'Oil Change'],
        ]);

        DocumentVehicleContext::create([
            'document_id' => $order->id,
            'vehicle_id' => $vehicle->id,
        ]);

        $order->refresh();

        // Convert to invoice (allowed for services-only orders)
        $invoice = $this->converterRegistry->convert($order, DocumentType::Invoice);

        // Verify vehicle context was preserved
        $this->assertNotNull($order->vehicle_id);
        $this->assertEquals($vehicle->id, $order->vehicle_id);
        $this->assertNotNull($invoice->vehicle_id);
        $this->assertEquals($vehicle->id, $invoice->vehicle_id);
    }

    /**
     * Create a confirmed sales order with the given lines.
     *
     * @param  array<int, array{product_id: string|null, description: string}>  $lines
     */
    private function createConfirmedOrder(array $lines): Document
    {
        $order = Document::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'SO-'.time().'-'.rand(1000, 9999),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '19.00',
            'total' => '119.00',
            'balance_due' => '119.00',
        ]);

        foreach ($lines as $index => $lineData) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $order->id,
                'line_number' => $index + 1,
                'product_id' => $lineData['product_id'],
                'description' => $lineData['description'],
                'quantity' => '1.00',
                'unit_price' => '100.00',
                'tax_rate' => '19.00',
                'line_total' => '100.00',
            ]);
        }

        return $order;
    }
}
