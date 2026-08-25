<?php

declare(strict_types=1);

namespace Tests\Unit\BatchExpiry;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TDD — Task B1.
 *
 * Verifies that FEFOInventoryService::getExpiredBatchesWithStock:
 *   1. Excludes lots whose entire quantity is reserved (available_quantity = 0).
 *   2. Includes lots that ARE expired AND have available_quantity > 0.
 *   3. Exposes both `quantity` and `reserved_quantity` on the returned batch_stock rows.
 */
class GetExpiredBatchesWithStockTest extends TestCase
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

        // Gate r4 R4-4: the service now constructor-injects ProductVariantLookup
        // (a variant-bearing product must never get a product-level DEFAULT lot),
        // so it is container-resolved rather than newed up.
        $this->service = app(FEFOInventoryService::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->for($this->tenant)->for($this->company)->create();
    }

    /**
     * A lot whose entire on-hand quantity is reserved (available_quantity = 0)
     * must be EXCLUDED from the expired-with-stock list.
     *
     * Before the fix, the method filtered by `quantity > 0`, so a lot with
     * quantity=5 and reserved_quantity=5 (available=0) was incorrectly included.
     */
    public function test_fully_reserved_expired_lot_is_excluded(): void
    {
        // Expired batch — fully reserved (available_quantity = 0)
        $this->createExpiredBatchWithStock(
            batchNumber: 'RESERVED-EXPIRED-01',
            quantity: '5.0000',
            reservedQuantity: '5.0000', // available = 0 → should be excluded
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(0, $result, 'Fully-reserved expired lot must not appear in write-off candidates');
    }

    /**
     * A lot with expired date, positive quantity, and some reserved stock is
     * still writable if available_quantity > 0.
     */
    public function test_partially_reserved_expired_lot_is_included(): void
    {
        // Expired batch — partially reserved (available_quantity = 3)
        $batch = $this->createExpiredBatchWithStock(
            batchNumber: 'PARTIAL-RESERVED-EXPIRED-01',
            quantity: '5.0000',
            reservedQuantity: '2.0000', // available = 3 → should be included
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(1, $result);
        $this->assertSame($batch->id, $result->first()->id);
    }

    /**
     * Batch stock rows returned for an expired lot must expose both
     * `quantity` (on-hand total) and `reserved_quantity` (held back for
     * pending fulfilments), so the caller can inform the operator of the
     * true write-off picture.
     */
    public function test_batch_stock_exposes_quantity_and_reserved_quantity(): void
    {
        $this->createExpiredBatchWithStock(
            batchNumber: 'STOCK-FIELDS-EXPIRED-01',
            quantity: '8.0000',
            reservedQuantity: '3.0000', // available = 5
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(1, $result);
        $stockRow = $result->first()->batchStock->first();
        $this->assertNotNull($stockRow, 'batchStock relation must be loaded');

        // Both fields must be present on the model.
        $this->assertSame('8.0000', (string) $stockRow->quantity);
        $this->assertSame('3.0000', (string) $stockRow->reserved_quantity);
    }

    /**
     * The product.unitOfMeasure chain must be eager-loaded so BatchResource can
     * emit per-product quantity_decimals (drives the write-off qty step) without
     * an N+1 and without silently falling back to scale 4.
     */
    public function test_expired_batches_eager_load_product_unit_for_quantity_precision(): void
    {
        $this->createExpiredBatchWithStock(
            batchNumber: 'UOM-EAGER-EXPIRED-01',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $product = $result->first()?->product;
        $this->assertNotNull($product, 'product relation must be loaded');
        $this->assertTrue(
            $product->relationLoaded('unitOfMeasure'),
            'product.unitOfMeasure must be eager-loaded for BatchResource quantity_decimals',
        );
    }

    /**
     * When a location_id filter is passed, only lots at that location are returned.
     */
    public function test_location_filter_scopes_results(): void
    {
        $otherLocation = Location::factory()->create(['company_id' => $this->company->id]);

        // Expired lot at the main test location (available > 0)
        $batchAtMain = $this->createExpiredBatchWithStock(
            batchNumber: 'LOC-MAIN-EXPIRED-01',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $this->location->id,
        );

        // Expired lot at another location (available > 0) — should be filtered out
        $this->createExpiredBatchWithStock(
            batchNumber: 'LOC-OTHER-EXPIRED-01',
            quantity: '4.0000',
            reservedQuantity: '0.0000',
            locationId: $otherLocation->id,
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
            locationId: $this->location->id,
        );

        $this->assertCount(1, $result);
        $this->assertSame($batchAtMain->id, $result->first()->id);
    }

    /**
     * A recalled expired lot with available stock must be EXCLUDED from write-off
     * candidates. Recalled lots are handled via the dedicated recall workflow.
     *
     * Before the fix, getExpiredBatchesWithStock omitted the is_recalled guard,
     * so recalled lots incorrectly appeared in the write-off candidate list.
     */
    public function test_recalled_expired_lot_with_available_stock_is_excluded(): void
    {
        // Expired batch — recalled (is_recalled = true) — must NOT appear
        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'RECALLED-EXPIRED-01',
            'expiry_date' => now()->subDays(5),
            'is_active' => true,
            'is_expired' => true,
            'is_recalled' => true, // recalled → exclude from write-off
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => '4.0000',
            'reserved_quantity' => '0.0000', // available = 4 → would appear without guard
        ]);

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(0, $result, 'Recalled expired lot must not appear in write-off candidates');
    }

    /**
     * An inactive expired lot with available stock must be EXCLUDED from write-off
     * candidates. Only active lots should be presented for write-off.
     *
     * Before the fix, getExpiredBatchesWithStock omitted the is_active guard,
     * so deactivated lots incorrectly appeared in the write-off candidate list.
     */
    public function test_inactive_expired_lot_with_available_stock_is_excluded(): void
    {
        // Expired batch — inactive (is_active = false) — must NOT appear
        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'INACTIVE-EXPIRED-01',
            'expiry_date' => now()->subDays(5),
            'is_active' => false, // deactivated → exclude from write-off
            'is_expired' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $this->location->id,
            'quantity' => '4.0000',
            'reserved_quantity' => '0.0000', // available = 4 → would appear without guard
        ]);

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(0, $result, 'Inactive expired lot must not appear in write-off candidates');
    }

    /**
     * Expired lots with zero quantity (nothing on hand) must be excluded.
     */
    public function test_zero_quantity_expired_lot_is_excluded(): void
    {
        $this->createExpiredBatchWithStock(
            batchNumber: 'ZERO-QTY-EXPIRED-01',
            quantity: '0.0000',
            reservedQuantity: '0.0000', // available = 0 → excluded
        );

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(0, $result);
    }

    /**
     * A non-expired lot (expiry in the future) must never appear, even if it
     * has available stock.
     */
    public function test_non_expired_lot_is_never_included(): void
    {
        // Not-yet-expired batch with available stock
        $notExpiredBatch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => 'NOT-EXPIRED-01',
            'expiry_date' => now()->addDays(30),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $notExpiredBatch->id,
            'location_id' => $this->location->id,
            'quantity' => '10.0000',
            'reserved_quantity' => '0.0000',
        ]);

        $result = $this->service->getExpiredBatchesWithStock(
            companyId: $this->company->id,
        );

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create an expired batch + BatchStock at the given location.
     */
    private function createExpiredBatchWithStock(
        string $batchNumber,
        string $quantity,
        string $reservedQuantity,
        ?string $locationId = null,
    ): Batch {
        $batch = Batch::create([
            'uuid' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'batch_number' => $batchNumber,
            'expiry_date' => now()->subDays(5), // expired 5 days ago
            'is_active' => true,
            'is_expired' => true,
            'is_recalled' => false,
        ]);

        BatchStock::create([
            'tenant_id' => $this->tenant->id,
            'batch_id' => $batch->id,
            'location_id' => $locationId ?? $this->location->id,
            'quantity' => $quantity,
            'reserved_quantity' => $reservedQuantity,
        ]);

        return $batch;
    }
}
