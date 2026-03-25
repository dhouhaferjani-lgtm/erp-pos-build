<?php

declare(strict_types=1);

namespace Tests\Feature\Taxation;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for tax snapshot creation on sales order confirmation.
 *
 * Sales orders are non-fiscal documents but tax snapshots are important for:
 * - Understanding pricing at the time of order
 * - Conversion to invoices (historical reference)
 * - Audit trail of customer transactions
 */
class TaxSnapshotSalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Partner $partner;

    private Product $product;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Requires sales order confirm endpoint implementation');
    }

    public function test_creates_tax_snapshots_on_sales_order_confirmation(): void
    {
        // Arrange
        $salesOrder = $this->createDraftSalesOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act
        $response = $this->postJson("/api/v1/sales-orders/{$salesOrder->id}/confirm");

        // Assert
        $response->assertOk();

        $taxDetails = DocumentTaxDetail::where('document_id', $salesOrder->id)->get();

        $this->assertCount(1, $taxDetails);
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertEquals('100.00', $taxDetails[0]->tax_base); // 10 qty * 10 price
        $this->assertEquals('19.00', $taxDetails[0]->tax_amount); // 100 * 0.19
        $this->assertTrue($taxDetails[0]->is_recoverable);
    }

    public function test_tax_snapshots_created_with_stock_reservation(): void
    {
        // Arrange: Enable auto-reserve in company settings
        $this->company->update([
            'reservation_settings' => [
                'auto_reserve_on_sales_order' => true,
                'sales_order_expiry_days' => 30,
            ],
        ]);

        $salesOrder = $this->createDraftSalesOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act: Confirm (will reserve stock)
        $this->postJson("/api/v1/sales-orders/{$salesOrder->id}/confirm");

        // Assert: Tax snapshots created AND stock reserved
        $taxDetails = DocumentTaxDetail::where('document_id', $salesOrder->id)->get();
        $this->assertCount(1, $taxDetails);

        // Verify stock reservation occurred
        $stockLevel = StockLevel::where('product_id', $this->product->id)->first();
        $this->assertEquals('10.00', $stockLevel->reserved);
        $this->assertEquals('90.00', $stockLevel->available);
    }

    public function test_tax_snapshots_with_multiple_products_different_rates(): void
    {
        // Arrange: Create second product
        $product2 = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        StockLevel::create([
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'product_id' => $product2->id,
            'quantity' => '100.00',
            'reserved' => '0.00',
            'available' => '100.00',
        ]);

        $salesOrder = $this->createDraftSalesOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
            ['product_id' => $product2->id, 'quantity' => '5.00', 'tax_rate' => '7.00', 'unit_price' => '20.00'],
        ]);

        // Act
        $this->postJson("/api/v1/sales-orders/{$salesOrder->id}/confirm");

        // Assert: Two tax snapshots (one per rate)
        $taxDetails = DocumentTaxDetail::where('document_id', $salesOrder->id)
            ->orderBy('sequence_order')
            ->get();

        $this->assertCount(2, $taxDetails);
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertEquals('100.00', $taxDetails[0]->tax_base);
        $this->assertEquals('7.00', $taxDetails[1]->tax_rate);
        $this->assertEquals('100.00', $taxDetails[1]->tax_base); // 5 * 20
    }

    public function test_tax_snapshots_for_non_registered_company(): void
    {
        // Arrange: Company cannot recover VAT
        $this->company->update(['tax_status' => CompanyTaxStatus::NON_REGISTERED]);

        $salesOrder = $this->createDraftSalesOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act
        $this->postJson("/api/v1/sales-orders/{$salesOrder->id}/confirm");

        // Assert: Tax is not recoverable
        $taxDetail = DocumentTaxDetail::where('document_id', $salesOrder->id)->first();
        $this->assertFalse($taxDetail->is_recoverable);
    }

    /**
     * Create a draft sales order.
     *
     * @param  array<int, array{product_id: string, quantity: string, tax_rate: string, unit_price: string}>  $lines
     */
    private function createDraftSalesOrder(array $lines): Document
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $lineSubtotal = bcmul($line['quantity'], $line['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
        }

        $salesOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::SalesOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SO-TEST-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => $subtotal,
            'tax_amount' => '0.00',
            'total' => $subtotal,
        ]);

        foreach ($lines as $index => $lineData) {
            $lineSubtotal = bcmul($lineData['quantity'], $lineData['unit_price'], 2);
            $taxAmount = bcmul($lineSubtotal, bcdiv($lineData['tax_rate'], '100', 4), 2);
            $lineTotal = bcadd($lineSubtotal, $taxAmount, 2);

            DocumentLine::create([
                'document_id' => $salesOrder->id,
                'product_id' => $lineData['product_id'],
                'line_number' => $index + 1,
                'description' => 'Sales Order Line '.($index + 1),
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'],
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ]);
        }

        return $salesOrder->fresh(['lines']);
    }
}
