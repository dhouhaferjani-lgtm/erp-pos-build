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

        $this->service = new FEFOInventoryService;

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
