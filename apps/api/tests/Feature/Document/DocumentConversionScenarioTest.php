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
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
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

    private DocumentConversionService $conversionService;

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

        $this->conversionService = app(DocumentConversionService::class);
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
        $invoice = $this->conversionService->convertOrderToInvoice($order);

        $this->assertNotNull($invoice);
        $this->assertEquals(DocumentType::Invoice, $invoice->type);
        $this->assertEquals($order->document_number, $invoice->reference);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_invoicing_for_products_only_orders(): void
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

        // Should NOT allow direct conversion - needs delivery note first
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Physical products must be delivered before invoicing');

        $this->conversionService->convertOrderToInvoice($order);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_blocks_direct_invoicing_for_mixed_orders(): void
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

        // Should NOT allow direct conversion - physical items need delivery first
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Physical products in this order must be delivered before invoicing');

        $this->conversionService->convertOrderToInvoice($order);
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
        $delivery = $this->conversionService->convertOrderToDelivery($order);
        $this->assertNotNull($delivery);

        // Refresh order to get updated payload
        $order->refresh();

        // Now invoicing should be allowed
        $invoice = $this->conversionService->convertOrderToInvoice($order);

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
        $invoice1 = $this->conversionService->convertOrderToInvoice($order);
        $this->assertNotNull($invoice1);

        // Refresh order to get updated payload
        $order->refresh();

        // Second conversion should fail
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Sales order has already been fully invoiced');

        $this->conversionService->convertOrderToInvoice($order);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_allows_invoicing_manual_lines_without_product(): void
    {
        // Create sales order with manual lines (no product_id)
        $order = $this->createConfirmedOrder([
            ['product_id' => null, 'description' => 'Custom Service'],
        ]);

        // Manual lines without product_id should be treated as services
        $invoice = $this->conversionService->convertOrderToInvoice($order);

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
        $delivery = $this->conversionService->convertOrderToDelivery($order);
        $this->assertNotNull($delivery);

        // Verify DN is not yet marked as invoiced
        $delivery->refresh();
        $this->assertNull($delivery->payload['invoiced_at'] ?? null);

        // Refresh order and convert to invoice
        $order->refresh();
        $invoice = $this->conversionService->convertOrderToInvoice($order);
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
        $delivery = $this->conversionService->convertOrderToDelivery($order);
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
        $invoice = $this->conversionService->convertOrderToInvoice($order);

        // Query again for uninvoiced delivery notes
        $uninvoicedDnsAfter = Document::where('type', DocumentType::DeliveryNote)
            ->where('partner_id', $this->partner->id)
            ->where('status', DocumentStatus::Confirmed)
            ->get()
            ->filter(fn ($dn) => empty($dn->payload['invoiced_at']));

        // After converting SO to invoice, DN should NOT be available for consolidation
        $this->assertCount(0, $uninvoicedDnsAfter);
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
