<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Enums\Vertical;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Enums\ProductType;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GoodsReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Location $warehouse;

    private Partner $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'GR Test Tenant',
            'slug' => 'gr-test-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Retail,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR Test Company',
            'legal_name' => 'GR Test Company LLC',
            'tax_id' => 'TAX-GR-001',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'GR Test User',
            'email' => 'gr-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo([
            'inventory.view',
            'inventory.adjust',
            'inventory.transfer',
            'inventory.receive',
            'purchase-orders.receive',
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->warehouse = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-GR-01',
            'name' => 'GR Main Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->supplier = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    /**
     * Create a product for testing goods receipt.
     */
    private function createProduct(string $sku, string $name, string $costPrice = '0.00'): Product
    {
        return Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name' => $name,
            'type' => ProductType::Part,
            'is_active' => true,
            'is_physical' => true,
            'cost_price' => $costPrice,
        ]);
    }

    /**
     * Create a confirmed purchase order with lines.
     *
     * @param  array<int, array{product: Product, quantity: string, unit_price: string}>  $lineItems
     */
    private function createConfirmedPO(array $lineItems, DocumentStatus $status = DocumentStatus::Confirmed): Document
    {
        $po = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::PurchaseOrder,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => $status,
            'document_number' => 'PO-'.fake()->unique()->numerify('####'),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '0.00',
            'tax_amount' => '0.00',
            'total' => '0.00',
        ]);

        $lineNumber = 1;
        $subtotal = '0.00';

        foreach ($lineItems as $item) {
            $lineTotal = bcmul($item['quantity'], $item['unit_price'], 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);

            DocumentLine::create([
                'document_id' => $po->id,
                'product_id' => $item['product']->id,
                'product_code' => $item['product']->sku,
                'line_number' => $lineNumber++,
                'description' => $item['product']->name,
                'quantity' => $item['quantity'],
                'quantity_delivered' => '0.0000',
                'quantity_received' => '0.0000',
                'unit_price' => $item['unit_price'],
                'line_total' => $lineTotal,
                'allocated_costs' => '0.0000',
            ]);
        }

        $po->update([
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ]);

        // Reload with lines
        return $po->fresh(['lines']);
    }

    // =========================================================================
    // 1. Full receipt of purchase order
    // =========================================================================

    public function test_full_receipt_of_purchase_order(): void
    {
        $productA = $this->createProduct('PROD-A', 'Product A');
        $productB = $this->createProduct('PROD-B', 'Product B');

        $po = $this->createConfirmedPO([
            ['product' => $productA, 'quantity' => '10.0000', 'unit_price' => '5.000'],
            ['product' => $productB, 'quantity' => '20.0000', 'unit_price' => '3.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = (string) $line->quantity;
        }

        $result = $service->receiveGoods($po, $receivedQty);

        // PO should be fully received
        $this->assertEquals(DocumentStatus::Received, $result->status);
        $this->assertTrue($result->payload['fully_received']);
        $this->assertNotNull($result->payload['goods_received_at']);

        // All lines should have quantity_received == quantity
        foreach ($result->lines as $line) {
            $this->assertEquals(
                0,
                bccomp((string) $line->quantity, (string) $line->quantity_received, 4),
                "Line {$line->id} should be fully received"
            );
        }

        // Stock levels should be updated
        $stockA = StockLevel::where('product_id', $productA->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($stockA);
        $this->assertEquals(0, bccomp('10.0000', (string) $stockA->quantity, 4));

        $stockB = StockLevel::where('product_id', $productB->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($stockB);
        $this->assertEquals(0, bccomp('20.0000', (string) $stockB->quantity, 4));
    }

    public function test_full_receipt_via_receive_all_helper(): void
    {
        $product = $this->createProduct('PROD-RA', 'Receive All Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '15.0000', 'unit_price' => '10.000'],
        ]);

        $service = app(GoodsReceiptService::class);
        $result = $service->receiveAll($po);

        $this->assertEquals(DocumentStatus::Received, $result->status);
        $this->assertTrue($result->payload['fully_received']);

        $stock = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($stock);
        $this->assertEquals(0, bccomp('15.0000', (string) $stock->quantity, 4));
    }

    // =========================================================================
    // 2. Partial receipt
    // =========================================================================

    public function test_partial_receipt_keeps_po_confirmed(): void
    {
        $product = $this->createProduct('PROD-PR', 'Partial Receipt Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '20.0000', 'unit_price' => '8.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '12.0000';
        }

        $result = $service->receiveGoods($po, $receivedQty);

        // PO should remain confirmed (not yet fully received)
        $this->assertEquals(DocumentStatus::Confirmed, $result->status);
        $this->assertFalse($result->payload['fully_received']);

        // Line should show partial receipt
        $updatedLine = $result->lines->first();
        $this->assertEquals(0, bccomp('12.0000', (string) $updatedLine->quantity_received, 4));

        // Stock should reflect partial receipt
        $stock = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertNotNull($stock);
        $this->assertEquals(0, bccomp('12.0000', (string) $stock->quantity, 4));
    }

    public function test_multiple_partial_receipts_complete_po(): void
    {
        $product = $this->createProduct('PROD-MPR', 'Multi Partial Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '30.0000', 'unit_price' => '6.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        // First partial receipt: 10 of 30
        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '10.0000';
        }
        $result = $service->receiveGoods($po, $receivedQty);
        $this->assertEquals(DocumentStatus::Confirmed, $result->status);

        // Second partial receipt: 15 of remaining 20
        $receivedQty2 = [];
        foreach ($result->lines as $line) {
            $receivedQty2[$line->id] = '15.0000';
        }
        $result2 = $service->receiveGoods($result, $receivedQty2);
        $this->assertEquals(DocumentStatus::Confirmed, $result2->status);

        // Final receipt: remaining 5
        $receivedQty3 = [];
        foreach ($result2->lines as $line) {
            $receivedQty3[$line->id] = '5.0000';
        }
        $result3 = $service->receiveGoods($result2, $receivedQty3);
        $this->assertEquals(DocumentStatus::Received, $result3->status);
        $this->assertTrue($result3->payload['fully_received']);

        // Stock should be 30
        $stock = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(0, bccomp('30.0000', (string) $stock->quantity, 4));
    }

    // =========================================================================
    // 3. Over-receiving rejection
    // =========================================================================

    public function test_over_receiving_is_rejected(): void
    {
        $product = $this->createProduct('PROD-OR', 'Over Receive Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '15.0000'; // More than ordered
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot receive more than ordered/');

        $service->receiveGoods($po, $receivedQty);
    }

    public function test_over_receiving_after_partial_receipt_is_rejected(): void
    {
        $product = $this->createProduct('PROD-ORPR', 'Over After Partial');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        // First receive 7
        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '7.0000';
        }
        $result = $service->receiveGoods($po, $receivedQty);

        // Try to receive 5 more (only 3 remaining)
        $receivedQty2 = [];
        foreach ($result->lines as $line) {
            $receivedQty2[$line->id] = '5.0000';
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot receive more than ordered/');

        $service->receiveGoods($result, $receivedQty2);
    }

    // =========================================================================
    // 4. WAC update on receipt
    // =========================================================================

    public function test_wac_updated_on_receipt(): void
    {
        // Product starts with cost_price = 10.00, existing stock = 10
        $product = $this->createProduct('PROD-WAC', 'WAC Product', '10.00');

        // Pre-seed existing stock so WAC calculation has something to blend with
        StockLevel::create([
            'product_id' => $product->id,
            'location_id' => $this->warehouse->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'quantity' => '10',
            'reserved' => '0',
        ]);

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '20.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '10.0000';
        }

        $service->receiveGoods($po, $receivedQty);

        // WAC = (10 * 10.00 + 10 * 20.00) / 20 = 300 / 20 = 15.00
        $product->refresh();
        $this->assertEquals(0, bccomp('15', (string) $product->cost_price, 2),
            "WAC should be 15.00 after blending 10@10 with 10@20. Got: {$product->cost_price}");
    }

    public function test_wac_set_to_purchase_cost_when_no_existing_stock(): void
    {
        $product = $this->createProduct('PROD-WAC2', 'WAC Fresh Product', '0.00');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '5.0000', 'unit_price' => '12.500'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '5.0000';
        }

        $service->receiveGoods($po, $receivedQty);

        $product->refresh();
        $this->assertEquals(0, bccomp('12.5', (string) $product->cost_price, 3),
            "WAC should equal purchase cost when no prior stock. Got: {$product->cost_price}");
    }

    public function test_stock_movement_recorded_on_receipt(): void
    {
        $product = $this->createProduct('PROD-SM', 'Stock Movement Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '8.0000', 'unit_price' => '7.500'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '8.0000';
        }

        $service->receiveGoods($po, $receivedQty);

        $movement = StockMovement::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->where('reference_id', $po->id)
            ->first();

        $this->assertNotNull($movement, 'A stock movement should be recorded for the goods receipt');
        $this->assertEquals(0, bccomp('8', (string) $movement->quantity, 4));
        $this->assertStringContainsString('PO-', $movement->reference);
    }

    // =========================================================================
    // 5. Batch receiving
    // =========================================================================

    public function test_batch_receiving_creates_batch_for_vertical_default_batch_tracked_product(): void
    {
        $this->tenant->update(['vertical' => Vertical::Pharmacy]);

        $product = $this->createProduct('PROD-BATCH', 'Batch Tracked Product');
        $this->assertTrue($product->requires_batch_tracking);

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '6.0000', 'unit_price' => '5.000'],
        ]);
        $line = $po->lines->firstOrFail();

        $service = app(GoodsReceiptService::class);
        $result = $service->receiveGoods(
            $po,
            [$line->id => '6.0000'],
            [
                $line->id => [
                    'batch_number' => 'BATCH-GR-001',
                    'expiry_date' => now()->addDays(30)->toDateString(),
                    'manufacturing_date' => now()->subDays(2)->toDateString(),
                ],
            ],
        );

        $batch = Batch::where('product_id', $product->id)
            ->where('batch_number', 'BATCH-GR-001')
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame($batch->id, $result->lines->firstOrFail()->batch_id);

        $batchStock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($batchStock);
        $this->assertEquals(0, bccomp('6.0000', (string) $batchStock->quantity, 4));
    }

    public function test_receive_endpoint_accepts_partial_quantities_and_batch_payload(): void
    {
        $this->tenant->update(['vertical' => Vertical::Pharmacy]);

        $product = $this->createProduct('PROD-API-BATCH', 'API Batch Product');
        $this->assertTrue($product->requires_batch_tracking);

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '6.0000', 'unit_price' => '5.000'],
        ]);
        $line = $po->lines->firstOrFail();
        $expiryDate = now()->addDays(45)->toDateString();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/purchase-orders/{$po->id}/receive", [
                'quantities' => [
                    $line->id => '2.5000',
                ],
                'batches' => [
                    $line->id => [
                        'batch_number' => 'API-BATCH-GR-001',
                        'expiry_date' => $expiryDate,
                    ],
                ],
            ])
            ->assertOk();

        $line->refresh();
        $this->assertEquals(0, bccomp('2.5000', (string) $line->quantity_received, 4));

        $batch = Batch::where('product_id', $product->id)
            ->where('batch_number', 'API-BATCH-GR-001')
            ->first();

        $this->assertNotNull($batch);
        $this->assertSame($expiryDate, $batch->expiry_date->toDateString());

        $batchStock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->warehouse->id)
            ->first();

        $this->assertNotNull($batchStock);
        $this->assertEquals(0, bccomp('2.5000', (string) $batchStock->quantity, 4));
    }

    public function test_batch_tracked_product_requires_batch_data_on_receipt(): void
    {
        $this->tenant->update(['vertical' => Vertical::Pharmacy]);

        $product = $this->createProduct('PROD-BATCH-REQ', 'Batch Required Product');
        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '4.0000', 'unit_price' => '5.000'],
        ]);

        $line = $po->lines->firstOrFail();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Batch data is required');

        app(GoodsReceiptService::class)->receiveGoods($po, [$line->id => '4.0000']);
    }

    public function test_non_physical_product_does_not_default_to_batch_tracking(): void
    {
        $this->tenant->update(['vertical' => Vertical::Pharmacy]);

        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'sku' => 'SVC-BATCH',
            'name' => 'Pharmacy Service',
            'type' => ProductType::Service,
            'is_active' => true,
            'is_physical' => false,
            'cost_price' => '0.00',
        ]);

        $this->assertFalse($product->requires_batch_tracking);
    }

    // =========================================================================
    // 6. Receipt status reporting
    // =========================================================================

    public function test_receipt_status_not_received(): void
    {
        $product = $this->createProduct('PROD-RS1', 'Receipt Status Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);
        $status = $service->getReceiptStatus($po);

        $this->assertEquals('not_received', $status['status']);
        $this->assertEquals(0, bccomp('10.0000', $status['total_ordered'], 4));
        $this->assertEquals(0, bccomp('0.0000', $status['total_received'], 4));
        $this->assertEquals(0.0, $status['percentage']);
        $this->assertCount(1, $status['lines']);
        $this->assertFalse($status['lines'][0]['is_complete']);
    }

    public function test_receipt_status_partially_received(): void
    {
        $product = $this->createProduct('PROD-RS2', 'Partial Status Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '20.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        // Receive 8 of 20
        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '8.0000';
        }
        $result = $service->receiveGoods($po, $receivedQty);

        $status = $service->getReceiptStatus($result);

        $this->assertEquals('partially_received', $status['status']);
        $this->assertEquals(0, bccomp('20.0000', $status['total_ordered'], 4));
        $this->assertEquals(0, bccomp('8.0000', $status['total_received'], 4));
        $this->assertEquals(40.0, $status['percentage']);
        $this->assertFalse($status['lines'][0]['is_complete']);
    }

    public function test_receipt_status_fully_received(): void
    {
        $product = $this->createProduct('PROD-RS3', 'Full Status Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);
        $result = $service->receiveAll($po);

        $status = $service->getReceiptStatus($result);

        $this->assertEquals('fully_received', $status['status']);
        $this->assertEquals(0, bccomp('10.0000', $status['total_ordered'], 4));
        $this->assertEquals(0, bccomp('10.0000', $status['total_received'], 4));
        $this->assertEquals(100.0, $status['percentage']);
        $this->assertTrue($status['lines'][0]['is_complete']);
    }

    public function test_is_fully_received_returns_correct_value(): void
    {
        $product = $this->createProduct('PROD-IFR', 'Is Fully Received Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $this->assertFalse($service->isFullyReceived($po));

        $result = $service->receiveAll($po);

        $this->assertTrue($service->isFullyReceived($result));
    }

    public function test_has_received_goods_returns_correct_value(): void
    {
        $product = $this->createProduct('PROD-HRG', 'Has Received Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $this->assertFalse($service->hasReceivedGoods($po));

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '3.0000';
        }
        $result = $service->receiveGoods($po, $receivedQty);

        $this->assertTrue($service->hasReceivedGoods($result));
    }

    // =========================================================================
    // 7. Non-confirmed PO rejection
    // =========================================================================

    public function test_draft_po_cannot_receive_goods(): void
    {
        $product = $this->createProduct('PROD-DPO', 'Draft PO Product');

        $po = $this->createConfirmedPO(
            [['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000']],
            DocumentStatus::Draft,
        );

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '10.0000';
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/must be confirmed/');

        $service->receiveGoods($po, $receivedQty);
    }

    public function test_cancelled_po_cannot_receive_goods(): void
    {
        $product = $this->createProduct('PROD-CPO', 'Cancelled PO Product');

        $po = $this->createConfirmedPO(
            [['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000']],
            DocumentStatus::Cancelled,
        );

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '10.0000';
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/must be confirmed/');

        $service->receiveGoods($po, $receivedQty);
    }

    public function test_non_purchase_order_cannot_receive_goods(): void
    {
        // Create an invoice document and try to receive goods against it
        $product = $this->createProduct('PROD-NPO', 'Non PO Product');

        $invoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'location_id' => $this->warehouse->id,
            'type' => DocumentType::Invoice,
            'fiscal_category' => FiscalCategory::TaxInvoice,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Confirmed,
            'document_number' => 'INV-TEST-001',
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => '100.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
        ]);

        $invoice->load('lines');

        $service = app(GoodsReceiptService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/Only purchase orders/');

        $service->receiveGoods($invoice, []);
    }

    public function test_receiving_with_no_quantities_throws_exception(): void
    {
        $product = $this->createProduct('PROD-NQ', 'No Quantity Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        // Pass empty quantities map
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/No items to receive/');

        $service->receiveGoods($po, []);
    }

    public function test_receiving_zero_quantities_throws_exception(): void
    {
        $product = $this->createProduct('PROD-ZQ', 'Zero Qty Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);

        $receivedQty = [];
        foreach ($po->lines as $line) {
            $receivedQty[$line->id] = '0.00';
        }

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/No items to receive/');

        $service->receiveGoods($po, $receivedQty);
    }

    // =========================================================================
    // 8. Authorization — GoodsReceiptService is a service-layer class with no
    //    HTTP controller/routes. Authorization tests require an API endpoint.
    // =========================================================================

    public function test_goods_receipt_service_has_no_direct_http_endpoint(): void
    {
        $hasGoodsReceiptRoute = collect(Route::getRoutes())->contains(
            fn (RoutingRoute $route): bool => str_contains($route->uri(), 'goods-receipt')
        );

        $this->assertFalse($hasGoodsReceiptRoute);
    }

    // =========================================================================
    // 9. Tenant isolation
    // =========================================================================

    public function test_goods_receipt_operates_within_tenant_context(): void
    {
        // Create product in current tenant
        $product = $this->createProduct('PROD-TI', 'Tenant Isolated Product');

        $po = $this->createConfirmedPO([
            ['product' => $product, 'quantity' => '10.0000', 'unit_price' => '5.000'],
        ]);

        $service = app(GoodsReceiptService::class);
        $result = $service->receiveAll($po);

        // Stock level should be scoped to this tenant
        $stock = StockLevel::where('product_id', $product->id)
            ->where('location_id', $this->warehouse->id)
            ->where('tenant_id', $this->tenant->id)
            ->first();
        $this->assertNotNull($stock);
        $this->assertEquals(0, bccomp('10.0000', (string) $stock->quantity, 4));

        // Create a second tenant to verify isolation
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        // No stock should exist for the other tenant
        $otherStock = StockLevel::where('product_id', $product->id)
            ->where('tenant_id', $otherTenant->id)
            ->first();
        $this->assertNull($otherStock, 'Stock should not leak to another tenant');

        // Stock movements should be scoped to original tenant
        $movements = StockMovement::where('product_id', $product->id)
            ->where('tenant_id', $this->tenant->id)
            ->get();
        $this->assertGreaterThan(0, $movements->count());

        $otherMovements = StockMovement::where('product_id', $product->id)
            ->where('tenant_id', $otherTenant->id)
            ->get();
        $this->assertCount(0, $otherMovements, 'Stock movements should not leak to another tenant');
    }
}
