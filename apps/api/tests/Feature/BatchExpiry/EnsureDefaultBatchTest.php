<?php

declare(strict_types=1);

namespace Tests\Feature\BatchExpiry;

use App\Modules\BatchExpiry\Application\Services\BatchStockService;
use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\BatchExpiry\Domain\Entities\BatchStock;
use App\Modules\BatchExpiry\Domain\Services\FEFOInventoryService;
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
 * mints (or tops up) a single DEFAULT lot and reconciles the location's batch
 * stock up to the target quantity. Its expiry is whatever the caller SUPPLIED,
 * or the product's configured shelf life measured from asOfDate, or — when
 * neither exists — NULL (W4-1: never an invented date). It is the single source of truth used by both the
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

    /**
     * Campaign W4-1. This case used to assert the OPPOSITE — that a null shelf
     * life fell back to a 365-day default. That fallback is the defect: on the
     * launch tenant every product is batch-tracked and all day-one stock is an
     * opening, so the whole catalogue carried the same fabricated `cutover + 365`
     * expiry, which FEFO then ranked FIRST and the transfer/delivery guards
     * compelled the operator to ship.
     */
    public function test_mints_an_undated_lot_when_no_shelf_life_is_configured(): void
    {
        $batch = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '5.0000', null, '2026-06-27',
        );

        $this->assertNotNull($batch);
        $this->assertNull(
            $batch->expiry_date,
            'no configured shelf life and no supplied expiry must produce a lot with NO expiry, never an invented one',
        );
    }

    public function test_an_explicitly_supplied_expiry_beats_the_configured_shelf_life(): void
    {
        $batch = $this->service->ensureDefaultBatch(
            $this->company->id, $this->tenant->id, $this->product->id,
            $this->location->id, '5.0000', 180, '2026-06-27',
            expiryDate: '2027-02-28',
        );

        $this->assertNotNull($batch);
        $this->assertSame(
            '2027-02-28',
            $batch->expiry_date?->toDateString(),
            'the expiry supplied for THIS stock is more specific than the product-wide shelf-life rule',
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

    /**
     * Gate r4 R4-10 — `FEFOInventoryService` (Domain) duplicates
     * `DEFAULT_BATCH_NUMBER` rather than importing it, because Domain must not
     * depend on Application (deptrac ModuleDomain → ModuleApplication). Correct,
     * but nothing pinned the two equal, so a silent divergence would split the
     * DEFAULT-lot vocabulary in half: the outbound/repair side would look for
     * 'DEFAULT' while the inbound restore minted something else, and every return
     * would create a second untracked lot.
     *
     * W4-1 removed the second half of this pair. `DEFAULT_SHELF_LIFE_DAYS = 365`
     * existed on BOTH classes and was the fabricated expiry itself; the mirror is
     * now asserted ABSENT on both sides, so re-adding a fallback to either one
     * fails here rather than quietly re-inventing dates for a whole catalogue.
     */
    public function test_the_domain_mirror_of_the_default_lot_constants_matches_the_application_source(): void
    {
        $mirror = new \ReflectionClass(FEFOInventoryService::class);

        $this->assertSame(
            BatchStockService::DEFAULT_BATCH_NUMBER,
            $mirror->getConstant('DEFAULT_BATCH_NUMBER'),
            'FEFOInventoryService mirrors this constant; the two must never diverge.',
        );

        $this->assertFalse(
            $mirror->hasConstant('DEFAULT_SHELF_LIFE_DAYS'),
            'W4-1: no class may carry a fallback shelf life. An undated lot records NULL and ranks last.',
        );
        $this->assertFalse(
            (new \ReflectionClass(BatchStockService::class))->hasConstant('DEFAULT_SHELF_LIFE_DAYS'),
            'W4-1: no class may carry a fallback shelf life. An undated lot records NULL and ranks last.',
        );
    }
}
