<?php

declare(strict_types=1);

namespace Tests\Unit\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FEFOInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private FEFOInventoryService $service;

    private Tenant $tenant;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new FEFOInventoryService;

        // Create test data
        $this->tenant = Tenant::factory()->create();
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_fefo_suggests_earliest_expiry_first(): void
    {
        // Create batches with different expiry dates
        $batch1 = $this->createBatchWithStock(expiryDays: 30, quantity: 10);
        $batch2 = $this->createBatchWithStock(expiryDays: 15, quantity: 5);  // Expires soonest
        $batch3 = $this->createBatchWithStock(expiryDays: 45, quantity: 8);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 5
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        $this->assertEquals($batch2->id, $result->suggestions[0]->batch->id); // Earliest expiry
        $this->assertEquals(5, $result->suggestions[0]->quantity);
    }

    public function test_fefo_skips_expired_batches(): void
    {
        // Create expired batch and active batch
        $expiredBatch = $this->createBatchWithStock(expiryDays: -5, quantity: 10);  // Expired
        $activeBatch = $this->createBatchWithStock(expiryDays: 30, quantity: 5);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 5,
            includeExpired: false
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        $this->assertEquals($activeBatch->id, $result->suggestions[0]->batch->id);
    }

    public function test_fefo_skips_recalled_batches(): void
    {
        // Create recalled batch and active batch
        $recalledBatch = $this->createBatchWithStock(expiryDays: 30, quantity: 10, isRecalled: true);
        $activeBatch = $this->createBatchWithStock(expiryDays: 45, quantity: 5);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 5
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        $this->assertEquals($activeBatch->id, $result->suggestions[0]->batch->id);
    }

    public function test_fefo_handles_partial_fulfillment(): void
    {
        // Create batches with limited quantities
        $batch1 = $this->createBatchWithStock(expiryDays: 15, quantity: 3);
        $batch2 = $this->createBatchWithStock(expiryDays: 30, quantity: 5);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 10  // Request more than available
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(2, $result->suggestions);
        $this->assertEquals(3, $result->suggestions[0]->quantity); // First batch depleted
        $this->assertEquals(7, $result->suggestions[1]->quantity); // Remaining from second batch
    }

    public function test_fefo_returns_shortfall_when_insufficient_stock(): void
    {
        // Create batch with insufficient quantity
        $batch = $this->createBatchWithStock(expiryDays: 30, quantity: 5);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 10
        );

        $this->assertFalse($result->fullyFulfilled);
        $this->assertEquals(5, $result->shortfall);
        $this->assertCount(1, $result->suggestions);
        $this->assertEquals(5, $result->suggestions[0]->quantity);
    }

    public function test_fefo_respects_reserved_quantities(): void
    {
        // Create batch with reservations
        $batch = Batch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'expiry_date' => now()->addDays(30),
            'is_active' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => 10,
            'reserved_quantity' => 7,  // 7 reserved, only 3 available
        ]);

        $result = $this->service->suggestBatchesForSale(
            $this->product->id,
            $this->location->id,
            quantity: 5
        );

        $this->assertFalse($result->fullyFulfilled);
        $this->assertEquals(2, $result->shortfall);  // Only 3 available, need 5
        $this->assertEquals(3, $result->suggestions[0]->quantity);
    }

    public function test_get_expiring_products_returns_batches_within_threshold(): void
    {
        $companyId = $this->tenant->id;

        // Create batches expiring at different times
        $this->createBatchWithStock(expiryDays: 20, quantity: 5);  // Within 30 days
        $this->createBatchWithStock(expiryDays: 25, quantity: 3);  // Within 30 days
        $this->createBatchWithStock(expiryDays: 90, quantity: 10); // Outside 30 days

        $batches = $this->service->getExpiringProducts($companyId, daysThreshold: 30);

        $this->assertCount(2, $batches);
    }

    public function test_get_total_available_quantity_excludes_expired_and_recalled(): void
    {
        // Create various batches
        $this->createBatchWithStock(expiryDays: 30, quantity: 10);     // Available
        $this->createBatchWithStock(expiryDays: 45, quantity: 5);      // Available
        $this->createBatchWithStock(expiryDays: -5, quantity: 3);      // Expired
        $this->createBatchWithStock(expiryDays: 30, quantity: 7, isRecalled: true); // Recalled

        $total = $this->service->getTotalAvailableQuantity($this->product->id, $this->location->id);

        $this->assertEquals(15, $total); // Only non-expired, non-recalled batches
    }

    private function createBatchWithStock(int $expiryDays, float $quantity, bool $isRecalled = false): Batch
    {
        $batch = Batch::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->tenant->id,
            'product_id' => $this->product->id,
            'expiry_date' => now()->addDays($expiryDays),
            'is_active' => true,
            'is_recalled' => $isRecalled,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => 0,
        ]);

        return $batch;
    }
}
