<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.2.16 — FEFOInventoryService::suggestBatchesForSale variant-aware.
 *
 * Verifies that:
 *  - suggestBatchesForSale(variantId: $id) returns only batches for that variant.
 *  - suggestBatchesForSale(variantId: null) returns only product-level batches
 *    (variant_id IS NULL).
 *  - FEFO ordering (expiry_date ASC) is preserved within each variant slice.
 */
class FEFOInventoryServiceVariantTest extends TestCase
{
    use RefreshDatabase;

    private FEFOInventoryService $service;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FEFOInventoryService::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    /**
     * When variantId is passed, only batches belonging to that variant are returned.
     *
     * Setup: variantA has batchA (expiry +30 days, qty 5);
     *        variantB has batchB (expiry +10 days, qty 5).
     * Requesting variantA should return only batchA regardless of batchB's
     * earlier expiry date.
     */
    public function test_fefo_returns_only_variant_batches_when_variant_passed(): void
    {
        $variantA = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $variantB = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $batchA = $this->createBatchWithStock(
            variantId: $variantA->id,
            expiryDays: 30,
            quantity: 5,
        );

        // batchB expires sooner — must NOT appear in variantA results.
        $this->createBatchWithStock(
            variantId: $variantB->id,
            expiryDays: 10,
            quantity: 5,
        );

        $result = $this->service->suggestBatchesForSale(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: 3,
            variantId: $variantA->id,
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame($batchA->id, $result->suggestions[0]->batch->id);
        $this->assertEquals(3, $result->suggestions[0]->quantity);
    }

    /**
     * When variantId is null (no variant), only product-level batches
     * (variant_id IS NULL) are returned.
     */
    public function test_fefo_product_level_when_no_variant(): void
    {
        // Product-level batch (variant_id null).
        $productBatch = $this->createBatchWithStock(
            variantId: null,
            expiryDays: 20,
            quantity: 10,
        );

        // Variant-scoped batch — must NOT appear when variantId is null.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $this->createBatchWithStock(
            variantId: $variant->id,
            expiryDays: 5,   // Earlier expiry — must stay invisible.
            quantity: 10,
        );

        $result = $this->service->suggestBatchesForSale(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: 5,
            variantId: null,
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        $this->assertSame($productBatch->id, $result->suggestions[0]->batch->id);
    }

    /**
     * FEFO ordering is preserved within a variant's batches.
     * batchEarly (expiry +5) should be suggested before batchLate (expiry +20).
     */
    public function test_fefo_ordering_preserved_within_variant(): void
    {
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $batchLate = $this->createBatchWithStock(
            variantId: $variant->id,
            expiryDays: 20,
            quantity: 5,
        );

        $batchEarly = $this->createBatchWithStock(
            variantId: $variant->id,
            expiryDays: 5,
            quantity: 5,
        );

        $result = $this->service->suggestBatchesForSale(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: 5,
            variantId: $variant->id,
        );

        $this->assertTrue($result->fullyFulfilled);
        $this->assertCount(1, $result->suggestions);
        // Earliest expiry must be first.
        $this->assertSame($batchEarly->id, $result->suggestions[0]->batch->id);
    }

    /**
     * When a variant has insufficient stock across all its batches, report a shortfall.
     */
    public function test_fefo_reports_shortfall_for_variant(): void
    {
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_active' => true,
        ]);

        $this->createBatchWithStock(
            variantId: $variant->id,
            expiryDays: 30,
            quantity: 3,
        );

        $result = $this->service->suggestBatchesForSale(
            productId: $this->product->id,
            locationId: $this->location->id,
            quantity: 10,
            variantId: $variant->id,
        );

        $this->assertFalse($result->fullyFulfilled);
        $this->assertEquals(7, $result->shortfall);
        $this->assertCount(1, $result->suggestions);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a batch (with optional variant_id) and a BatchStock record at the
     * test location.  Returns the persisted Batch.
     */
    private function createBatchWithStock(
        ?string $variantId,
        int $expiryDays,
        float $quantity,
        bool $isRecalled = false,
    ): Batch {
        static $counter = 0;
        $counter++;

        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => $variantId,
            'batch_number' => 'FEFO-VAR-'.$counter,
            'expiry_date' => now()->addDays($expiryDays),
            'is_active' => true,
            'is_expired' => false,
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
