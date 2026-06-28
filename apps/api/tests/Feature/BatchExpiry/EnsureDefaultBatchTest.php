<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The default-batch invariant: a batch-tracked product must never hold stock
 * that isn't inside a lot. `ensureDefaultBatch()` is the shared behavior that
 * mints (or tops up) a single DEFAULT lot — expiry = asOfDate + the product's
 * default expiry period — and reconciles the location's batch stock up to the
 * target quantity. It is the single source of truth used by both the
 * opening-balance posting path and the demo seeders.
 */
final class EnsureDefaultBatchTest extends TestCase
{
    use RefreshDatabase;

    private BatchStockService $service;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(BatchStockService::class);
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'requires_batch_tracking' => true,
            'default_shelf_life_days' => 180,
        ]);
        $this->location = Location::create([
            'company_id' => $this->company->id,
            'code' => 'WH-DEF-01',
            'name' => 'Default Batch Warehouse',
            'type' => 'warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_mints_default_lot_with_expiry_and_reconciles_stock(): void
    {
        $batch = $this->service->ensureDefaultBatch(
            companyId: $this->company->id,
            tenantId: $this->tenant->id,
            productId: $this->product->id,
            locationId: $this->location->id,
            targetQuantity: '100.0000',
            shelfLifeDays: 180,
            asOfDate: '2026-06-27',
        );

        $this->assertNotNull($batch);
        $this->assertSame($this->product->id, $batch->product_id);
        $this->assertSame(
            CarbonImmutable::parse('2026-06-27')->addDays(180)->toDateString(),
            $batch->expiry_date->toDateString(),
            'expiry must be asOfDate + shelf life',
        );

        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp('100.0000', (string) $stock->quantity, 4));
    }

    public function test_is_idempotent_no_double_count(): void
    {
        $first = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '100.0000', 180, '2026-06-27',
        );
        $second = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '100.0000', 180, '2026-06-27',
        );

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id, 'must reuse the same DEFAULT lot');
        $this->assertSame(1, Batch::where('product_id', $this->product->id)->count());

        $stock = BatchStock::where('batch_id', $first->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp('100.0000', (string) $stock->quantity, 4), 'must not double-count on re-run');
    }

    public function test_tops_up_to_target_when_stock_grows(): void
    {
        $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '60.0000', 180, '2026-06-27',
        );
        $batch = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '100.0000', 180, '2026-06-27',
        );

        $this->assertNotNull($batch);
        $stock = BatchStock::where('batch_id', $batch->id)
            ->where('location_id', $this->location->id)
            ->firstOrFail();
        $this->assertSame(0, bccomp('100.0000', (string) $stock->quantity, 4), 'must reconcile up to the new target');
    }

    public function test_falls_back_to_default_shelf_life_when_null(): void
    {
        $batch = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '5.0000', null, '2026-06-27',
        );

        $this->assertNotNull($batch);
        $this->assertSame(
            CarbonImmutable::parse('2026-06-27')->addDays(BatchStockService::DEFAULT_SHELF_LIFE_DAYS)->toDateString(),
            $batch->expiry_date->toDateString(),
            'null shelf life must fall back to the service default',
        );
    }

    public function test_skips_zero_quantity(): void
    {
        $batch = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '0.0000', 180, '2026-06-27',
        );

        $this->assertNull($batch, 'zero target must not mint a lot');
        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
    }
}
