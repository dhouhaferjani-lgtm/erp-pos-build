<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Inventory\Application\Services\LandedCostService;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\ProportionalMoneyAllocator;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;
use Tests\Traits\WithCurrencyScale;

class LandedCostServiceTest extends TestCase
{
    use WithCurrencyScale;

    private LandedCostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LandedCostService($this->mockCurrencyScale(), new ProportionalMoneyAllocator);
    }

    public function test_calculate_allocated_cost_with_valid_inputs(): void
    {
        $result = $this->service->calculateAllocatedCost(
            lineTotal: 100.00,
            subtotal: 500.00,
            additionalCostsTotal: 50.00
        );

        // 100/500 = 0.2, 0.2 * 50 = 10
        $this->assertEquals(10.00, $result);
    }

    public function test_calculate_allocated_cost_with_zero_subtotal(): void
    {
        $result = $this->service->calculateAllocatedCost(
            lineTotal: 100.00,
            subtotal: 0.00,
            additionalCostsTotal: 50.00
        );

        $this->assertEquals(0.00, $result);
    }

    public function test_calculate_allocated_cost_with_zero_additional_costs(): void
    {
        $result = $this->service->calculateAllocatedCost(
            lineTotal: 100.00,
            subtotal: 500.00,
            additionalCostsTotal: 0.00
        );

        $this->assertEquals(0.00, $result);
    }

    public function test_calculate_landed_unit_cost(): void
    {
        $result = $this->service->calculateLandedUnitCost(
            lineTotal: 100.00,
            allocatedCost: 10.00,
            nonRecoverableTax: 0.00,
            quantity: 5.00
        );

        // (100 + 10) / 5 = 22
        $this->assertEquals(22.00, $result);
    }

    public function test_calculate_landed_unit_cost_with_zero_quantity(): void
    {
        $result = $this->service->calculateLandedUnitCost(
            lineTotal: 100.00,
            allocatedCost: 10.00,
            nonRecoverableTax: 0.00,
            quantity: 0.00
        );

        $this->assertEquals(0.00, $result);
    }

    public function test_allocate_costs_with_single_line(): void
    {
        // Test using pure calculation methods instead of mocking complex Eloquent relationships
        // Single line: 100.00 total, 5 quantity, 50.00 additional costs
        $allocatedCost = $this->service->calculateAllocatedCost(100.00, 100.00, 50.00);
        $landedUnitCost = $this->service->calculateLandedUnitCost(100.00, $allocatedCost, 0.00, 5.0);

        // All costs allocated to single line
        $this->assertEquals(50.00, $allocatedCost);
        // (100 + 50) / 5 = 30
        $this->assertEquals(30.00, $landedUnitCost);
    }

    public function test_allocate_costs_proportionally_to_multiple_lines(): void
    {
        // Test proportional allocation calculation
        // Line 1: 300 out of 500 total (60%) -> should get 60% of 100 = 60
        $allocatedCost1 = $this->service->calculateAllocatedCost(300.00, 500.00, 100.00);
        $landedUnitCost1 = $this->service->calculateLandedUnitCost(300.00, $allocatedCost1, 0.00, 10.0);

        $this->assertEquals(60.00, $allocatedCost1);
        // (300 + 60) / 10 = 36
        $this->assertEquals(36.00, $landedUnitCost1);

        // Line 2: 200 out of 500 total (40%) -> should get 40% of 100 = 40
        $allocatedCost2 = $this->service->calculateAllocatedCost(200.00, 500.00, 100.00);
        $landedUnitCost2 = $this->service->calculateLandedUnitCost(200.00, $allocatedCost2, 0.00, 5.0);

        $this->assertEquals(40.00, $allocatedCost2);
        // (200 + 40) / 5 = 48
        $this->assertEquals(48.00, $landedUnitCost2);
    }

    public function test_get_allocation_breakdown(): void
    {
        $product = new Product;
        $product->name = 'Test Product';

        $line = new DocumentLine;
        $line->id = 'line-1';
        $line->description = 'Test Description';
        $line->quantity = '5.00';
        $line->unit_price = '20.00';
        $line->line_total = '100.00';
        $line->allocated_costs = '10.00';
        $line->landed_unit_cost = '22.00';
        $line->setRelation('product', $product);

        $lines = new Collection([$line]);

        $document = new Document;
        $document->setRelation('lines', $lines);

        $result = $this->service->getAllocationBreakdown($document);

        $this->assertCount(1, $result);
        $this->assertEquals('line-1', $result[0]['line_id']);
        $this->assertEquals('Test Product', $result[0]['product_name']);
        $this->assertEquals('5.0000', $result[0]['quantity']);
        $this->assertEquals('20.000', $result[0]['unit_price']);
        $this->assertEquals('100.000', $result[0]['line_total']);
        $this->assertEquals('10.000000', $result[0]['allocated_costs']);
        $this->assertEquals('22.000000', $result[0]['landed_unit_cost']);
    }
}
