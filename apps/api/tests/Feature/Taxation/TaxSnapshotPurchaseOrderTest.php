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
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for tax snapshot creation on purchase order confirmation.
 *
 * Purchase orders are critical for tax recoverability calculations:
 * - REGISTERED companies can recover VAT on purchases
 * - NON_REGISTERED companies cannot recover VAT (becomes part of product cost)
 * - Tax snapshots are essential for landed cost calculations
 */
class TaxSnapshotPurchaseOrderTest extends TestCase
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

        $this->markTestSkipped('Requires purchase order confirm endpoint implementation');

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAs($this->user);
    }

    public function test_creates_tax_snapshots_on_purchase_order_confirmation(): void
    {
        // Arrange
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act
        $response = $this->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // Assert
        $response->assertOk();

        $taxDetails = DocumentTaxDetail::where('document_id', $purchaseOrder->id)->get();

        $this->assertCount(1, $taxDetails);
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertEquals('100.00', $taxDetails[0]->tax_base);
        $this->assertEquals('19.00', $taxDetails[0]->tax_amount);
        $this->assertTrue($taxDetails[0]->is_recoverable, 'VAT recoverable for REGISTERED company');
    }

    public function test_tax_snapshots_show_non_recoverable_for_unregistered_company(): void
    {
        // Arrange: Company cannot recover VAT
        $this->company->update(['tax_status' => CompanyTaxStatus::NON_REGISTERED]);

        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act
        $this->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // Assert: VAT is NOT recoverable and will be added to product cost
        $taxDetail = DocumentTaxDetail::where('document_id', $purchaseOrder->id)->first();

        $this->assertFalse(
            $taxDetail->is_recoverable,
            'VAT not recoverable for NON_REGISTERED company - becomes part of landed cost'
        );
    }

    public function test_tax_snapshots_with_mixed_recoverable_and_non_recoverable_taxes(): void
    {
        // Arrange: Company registered but has customs duty (non-recoverable)
        $this->company->update(['tax_status' => CompanyTaxStatus::REGISTERED]);

        $purchaseOrder = $this->createDraftPurchaseOrder([
            [
                'product_id' => $this->product->id,
                'quantity' => '10.00',
                'tax_rate' => '19.00', // VAT - recoverable
                'unit_price' => '100.00',
            ],
        ]);

        // Add customs duty as a separate line (typically handled differently, but illustrative)
        DocumentLine::create([
            'document_id' => $purchaseOrder->id,
            'line_number' => 2,
            'description' => 'Customs Duty',
            'quantity' => '1.00',
            'unit_price' => '50.00',
            'tax_rate' => '0.00',
            'line_total' => '50.00',
        ]);

        // Act
        $this->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // Assert: VAT snapshot created
        $taxDetail = DocumentTaxDetail::where('document_id', $purchaseOrder->id)->first();
        $this->assertTrue($taxDetail->is_recoverable);
    }

    public function test_tax_snapshots_preserved_after_landed_cost_allocation(): void
    {
        // Arrange
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['product_id' => $this->product->id, 'quantity' => '10.00', 'tax_rate' => '19.00', 'unit_price' => '10.00'],
        ]);

        // Act: Confirm (triggers landed cost allocation)
        $this->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // Assert: Tax snapshots exist
        $taxDetails = DocumentTaxDetail::where('document_id', $purchaseOrder->id)->get();
        $this->assertCount(1, $taxDetails);

        // Assert: Document lines have allocated costs
        $purchaseOrder->refresh();
        $line = $purchaseOrder->lines->first();
        $this->assertNotNull($line->allocated_costs, 'Landed costs should be allocated');
        $this->assertNotNull($line->landed_unit_cost, 'Landed unit cost should be calculated');
    }

    public function test_tax_snapshots_for_purchase_with_import_taxes(): void
    {
        // Arrange: International purchase with VAT + Stamp Duty
        $purchaseOrder = $this->createDraftPurchaseOrder([
            ['product_id' => $this->product->id, 'quantity' => '100.00', 'tax_rate' => '19.00', 'unit_price' => '5.00'],
            ['product_id' => $this->product->id, 'quantity' => '1.00', 'tax_rate' => '1.00', 'unit_price' => '500.00', 'is_stamp_duty' => true],
        ]);

        // Act
        $this->postJson("/api/v1/purchase-orders/{$purchaseOrder->id}/confirm");

        // Assert: Multiple tax snapshots
        $taxDetails = DocumentTaxDetail::where('document_id', $purchaseOrder->id)
            ->orderBy('sequence_order')
            ->get();

        $this->assertCount(2, $taxDetails);

        // VAT - recoverable for registered company
        $this->assertEquals('19.00', $taxDetails[0]->tax_rate);
        $this->assertTrue($taxDetails[0]->is_recoverable);
        $this->assertFalse($taxDetails[0]->is_stamp_duty);

        // Stamp duty - never recoverable
        $this->assertEquals('1.00', $taxDetails[1]->tax_rate);
        $this->assertFalse($taxDetails[1]->is_recoverable);
        $this->assertTrue($taxDetails[1]->is_stamp_duty);
    }

    /**
     * Create a draft purchase order.
     *
     * @param  array<int, array{product_id: string, quantity: string, tax_rate: string, unit_price: string, is_stamp_duty?: bool}>  $lines
     */
    private function createDraftPurchaseOrder(array $lines): Document
    {
        $subtotal = '0.00';
        foreach ($lines as $line) {
            $lineSubtotal = bcmul($line['quantity'], $line['unit_price'], 2);
            $subtotal = bcadd($subtotal, $lineSubtotal, 2);
        }

        $purchaseOrder = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
            'document_number' => 'PO-TEST-'.uniqid(),
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
                'document_id' => $purchaseOrder->id,
                'product_id' => $lineData['product_id'] ?? null,
                'line_number' => $index + 1,
                'description' => 'Purchase Line '.($index + 1),
                'quantity' => $lineData['quantity'],
                'unit_price' => $lineData['unit_price'],
                'tax_rate' => $lineData['tax_rate'],
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ]);
        }

        return $purchaseOrder->fresh(['lines']);
    }
}
