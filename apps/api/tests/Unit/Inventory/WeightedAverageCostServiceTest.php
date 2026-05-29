<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\WeightedAverageCostService;
use App\Modules\Inventory\Domain\StockMovement;
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
        $this->service = new WeightedAverageCostService($marginService, $this->mockCurrencyScale());

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
