<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

class WeightedAverageCostServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithCurrencyScale;

    private WeightedAverageCostService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test entities first so CompanyContext can be bound before service resolution
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        // Bind CompanyContext so the real CurrencyScaleResolver used inside MarginService has context
        $this->app->make(CompanyContext::class)->setCompanyId($this->company->id);

        $marginService = $this->app->make(MarginService::class);
        $this->service = new WeightedAverageCostService(
            $marginService,
            $this->mockCurrencyScale(),
            new ProductCostLock,
        );

        // Create location manually (no factory exists yet)
        $this->location = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Test Location',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
            'pos_enabled' => false,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'cost_price' => '0',
            'sale_price' => '0',
        ]);
    }

    public function test_calculate_new_wac_with_first_purchase(): void
    {
        $result = $this->service->calculateNewWAC(
            currentQty: 0,
            currentCost: 0,
            newQty: 10,
            newCost: 50.00
        );

        // First purchase: WAC = new cost
        $this->assertEquals(50.00, $result);
    }

    public function test_calculate_new_wac_with_second_purchase(): void
    {
        $result = $this->service->calculateNewWAC(
            currentQty: 10,
            currentCost: 50.00,
            newQty: 10,
            newCost: 60.00
        );

        // (10*50 + 10*60) / 20 = 1100/20 = 55
        $this->assertEquals(55.00, $result);
    }

    public function test_calculate_new_wac_with_different_quantities(): void
    {
        $result = $this->service->calculateNewWAC(
            currentQty: 5,
            currentCost: 40.00,
            newQty: 15,
            newCost: 60.00
        );

        // (5*40 + 15*60) / 20 = (200 + 900)/20 = 1100/20 = 55
        $this->assertEquals(55.00, $result);
    }

    public function test_calculate_new_wac_with_zero_total_quantity(): void
    {
        $result = $this->service->calculateNewWAC(
            currentQty: 0,
            currentCost: 0,
            newQty: 0,
            newCost: 0
        );

        $this->assertEquals(0.00, $result);
    }

    public function test_service_exists(): void
    {
        $this->assertTrue(class_exists(WeightedAverageCostService::class));
    }

    public function test_service_has_record_purchase_method(): void
    {
        $this->assertTrue(method_exists(WeightedAverageCostService::class, 'recordPurchase'));
    }

    public function test_service_has_record_sale_method(): void
    {
        $this->assertTrue(method_exists(WeightedAverageCostService::class, 'recordSale'));
    }

    public function test_service_has_record_return_method(): void
    {
        $this->assertTrue(method_exists(WeightedAverageCostService::class, 'recordReturn'));
    }

    /**
     * recordCostAdjustment capitalizes the additional cost across the
     * COMPANY-WIDE on-hand quantity (sum across every stock_level row for the
     * product within the company), not against any single location's quantity.
     *
     * Product P: 60 at WH-A + 40 at WH-B = 100 company-wide on hand, WAC 5.000000.
     * Capitalizing 100 of freight => 100 / 100 = +1.000000 => 6.000000
     * (NOT 100/60 nor 100/40).
     */
    public function test_cost_adjustment_capitalizes_against_company_wide_on_hand(): void
    {
        $warehouseA = $this->location; // seeded in setUp

        $warehouseB = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Test Location B',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => false,
        ]);

        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $warehouseA->id,
            'quantity' => '60.0000',
        ]);

        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $warehouseB->id,
            'quantity' => '40.0000',
        ]);

        $this->product->forceFill(['cost_price' => '5.0000'])->save();

        $this->service->recordCostAdjustment(
            product: $this->product,
            additionalCost: 100.0,
            reason: 'freight',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
        );

        // 100 / 100 company-wide = +1.00 -> 6 (cost_price casts decimal:6).
        $this->assertEquals('6.000000', $this->product->fresh()->cost_price);
    }

    /**
     * In-transit transfer quantity must count toward the company-wide
     * denominator exactly once: stock that has left the source but has not yet
     * been received still belongs to the company and shares in the capitalized
     * cost.
     *
     * Product P: 60 on hand at WH-A + 40 in_transit = 100 owned, WAC 5.000000.
     * Capitalizing 100 of freight => 100 / 100 = +1.000000 => 6.000000
     * (NOT 100/60 — which would prove in-transit was ignored — and NOT a
     * doubled denominator).
     *
     * NOTE: this exercises the recordCostAdjustment SEAM in isolation. The
     * separate StockTransferService::complete() double-count (received-into-
     * on-hand while the line is still marked InTransit) is a known caller-
     * sequencing issue owned by transfer-v5, NOT this seam.
     */
    public function test_cost_adjustment_includes_in_transit_in_company_wide_denominator(): void
    {
        $warehouseA = $this->location; // seeded in setUp

        $warehouseB = Location::create([
            'id' => Str::uuid()->toString(),
            'company_id' => $this->company->id,
            'name' => 'Test Location B',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
            'pos_enabled' => false,
        ]);

        // On-hand 60 at WH-A.
        StockLevel::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $warehouseA->id,
            'quantity' => '60.0000',
        ]);

        // A user is required as initiated_by_user_id (NOT NULL FK on stock_transfers).
        $user = User::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name' => 'Transfer User',
            'email' => 'transfer-user@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        // Build an isolated in_transit transfer directly (no create->dispatch
        // flow) so it contributes ONLY an in-transit line and does NOT move any
        // stock_level rows. 40 units in transit: WH-A -> WH-B.
        $transfer = StockTransfer::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'transfer_number' => 'TR-IN-TRANSIT-001',
            'transfer_type' => TransferType::Intracompany,
            'status' => TransferStatus::InTransit,
            'source_location_id' => $warehouseA->id,
            'destination_location_id' => $warehouseB->id,
            'transfer_cost' => '0',
            'transfer_cost_distribution' => TransferCostDistribution::ProRataValue,
            'initiated_by_user_id' => $user->id,
            'initiated_at' => now(),
        ]);

        StockTransferLine::create([
            'id' => Str::uuid()->toString(),
            'transfer_id' => $transfer->id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity' => '40.0000',
            'allocated_transfer_cost' => '0',
        ]);

        $this->product->forceFill(['cost_price' => '5.0000'])->save();

        $this->service->recordCostAdjustment(
            product: $this->product,
            additionalCost: 100.0,
            reason: 'freight',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
        );

        // denominator = 60 on_hand + 40 in_transit = 100 -> 5 + 100/100 = 6.
        $fresh = $this->product->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals('6.000000', $fresh->cost_price);
    }

    /**
     * When the company owns nothing (zero on hand, zero in transit), there is
     * no denominator to capitalize against: the adjustment is a no-op — it
     * returns null, leaves cost_price untouched, and records no movement.
     */
    public function test_cost_adjustment_is_noop_when_nothing_owned(): void
    {
        // No stock_level rows and no in-transit lines for this product.
        $this->product->forceFill(['cost_price' => '5.0000'])->save();

        $result = $this->service->recordCostAdjustment(
            product: $this->product,
            additionalCost: 100.0,
            reason: 'freight',
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
        );

        $this->assertNull($result);
        $fresh = $this->product->fresh();
        $this->assertNotNull($fresh);
        $this->assertEquals('5.000000', $fresh->cost_price);
        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $this->product->id,
        ]);
    }

    /**
     * Phase 1.1: Audit Trail Tests
     * These tests verify that reference_type and reference_id are properly populated
     */
    public function test_record_sale_populates_reference_type_and_id(): void
    {
        // Arrange - Create initial stock
        $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 10.0,
            landedUnitCost: 50.0
        );

        $documentId = '019b481c-7eac-7045-8ba2-cfa7eedf2d08';

        // Act - Record sale with audit trail
        $movement = $this->service->recordSale(
            product: $this->product,
            location: $this->location,
            quantity: 5.0,
            reference: 'DN-2025-001',
            referenceType: 'Document',
            referenceId: $documentId
        );

        // Assert
        $this->assertEquals('DN-2025-001', $movement->reference);
        $this->assertEquals('Document', $movement->reference_type);
        $this->assertEquals($documentId, $movement->reference_id);

        // Verify it's stored in database
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'reference' => 'DN-2025-001',
            'reference_type' => 'Document',
            'reference_id' => $documentId,
        ]);
    }

    public function test_record_purchase_populates_reference_type_and_id(): void
    {
        // Arrange
        $documentId = '019b481c-7eac-7045-8ba2-cfa7eedf2d09';

        // Act - Record purchase with audit trail
        $movement = $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 10.0,
            landedUnitCost: 50.0,
            reference: 'PO-2025-001',
            referenceType: 'Document',
            referenceId: $documentId
        );

        // Assert
        $this->assertEquals('PO-2025-001', $movement->reference);
        $this->assertEquals('Document', $movement->reference_type);
        $this->assertEquals($documentId, $movement->reference_id);

        // Verify it's stored in database
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'reference' => 'PO-2025-001',
            'reference_type' => 'Document',
            'reference_id' => $documentId,
        ]);
    }

    public function test_record_return_populates_reference_type_and_id(): void
    {
        // Arrange - Create initial stock
        $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 10.0,
            landedUnitCost: 50.0
        );

        // Sell some stock
        $this->service->recordSale(
            product: $this->product,
            location: $this->location,
            quantity: 5.0
        );

        $documentId = '019b481c-7eac-7045-8ba2-cfa7eedf2d10';

        // Act - Record return with audit trail
        $movement = $this->service->recordReturn(
            product: $this->product,
            location: $this->location,
            quantity: 2.0,
            originalCost: 50.0,
            reference: 'RN-2025-001',
            referenceType: 'Document',
            referenceId: $documentId
        );

        // Assert
        $this->assertEquals('RN-2025-001', $movement->reference);
        $this->assertEquals('Document', $movement->reference_type);
        $this->assertEquals($documentId, $movement->reference_id);

        // Verify it's stored in database
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'reference' => 'RN-2025-001',
            'reference_type' => 'Document',
            'reference_id' => $documentId,
        ]);
    }

    public function test_record_sale_without_reference_type_stores_null(): void
    {
        // Arrange - Create initial stock
        $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 10.0,
            landedUnitCost: 50.0
        );

        // Act - Record sale without audit trail (backward compatibility)
        $movement = $this->service->recordSale(
            product: $this->product,
            location: $this->location,
            quantity: 5.0,
            reference: 'DN-2025-002'
        );

        // Assert - reference_type and reference_id should be null
        $this->assertEquals('DN-2025-002', $movement->reference);
        $this->assertNull($movement->reference_type);
        $this->assertNull($movement->reference_id);
    }

    public function test_can_query_movements_by_reference_type_and_id(): void
    {
        // Arrange - Create stock movements with different references
        $documentId1 = '019b481c-7eac-7045-8ba2-cfa7eedf2d11';
        $documentId2 = '019b481c-7eac-7045-8ba2-cfa7eedf2d12';

        $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 10.0,
            landedUnitCost: 50.0,
            reference: 'DN-001',
            referenceType: 'Document',
            referenceId: $documentId1
        );

        $this->service->recordPurchase(
            product: $this->product,
            location: $this->location,
            quantity: 5.0,
            landedUnitCost: 45.0,
            reference: 'DN-002',
            referenceType: 'Document',
            referenceId: $documentId2
        );

        // Act - Query movements for specific document
        $movements = StockMovement::where('reference_type', 'Document')
            ->where('reference_id', $documentId1)
            ->get();

        // Assert
        $this->assertCount(1, $movements);
        $this->assertEquals('DN-001', $movements->first()->reference);
        $this->assertEquals($documentId1, $movements->first()->reference_id);
    }
}
