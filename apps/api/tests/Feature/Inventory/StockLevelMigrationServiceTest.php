<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Exceptions\LargeMigrationRefusalException;
use App\Modules\Inventory\Application\Services\StockLevelMigrationService;
use App\Modules\Inventory\Domain\Events\StockLevelsMigratedToDefaultVariant;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.2.14 — StockLevelMigrationService
 *
 * Verifies atomic migration of pre-existing product-level state (variant_id NULL)
 * to a newly-created default variant. Covers all four migrated tables, the
 * append-only guard on stock_movements, the large-migration threshold, and
 * event emission.
 */
class StockLevelMigrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockLevelMigrationService $service;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private Location $location;

    private ProductVariant $defaultVariant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockLevelMigrationService::class);

        $this->tenant = Tenant::factory()->create();

        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $this->defaultVariant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'is_default' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // stock_levels
    // -------------------------------------------------------------------------

    public function test_migrates_active_stock_levels_to_default_variant(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        // A product-level stock_levels row (variant_id NULL)
        DB::table('stock_levels')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'quantity' => '10.00',
            'reserved' => '0.00',
        ]);

        $this->assertSame(1, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->count());

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        // No more product-level (null variant) rows
        $this->assertSame(0, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->count());

        // Row now points to the default variant
        $this->assertSame(1, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->defaultVariant->id)
            ->count());

        $row = DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->defaultVariant->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertEquals('10.00', $row->quantity);
    }

    // -------------------------------------------------------------------------
    // stock_movements — must NOT be rewritten
    // -------------------------------------------------------------------------

    public function test_historical_movements_not_rewritten(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        // Insert a product-level stock_movement row (variant_id NULL)
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'movement_type' => 'purchase',
            'quantity' => '5.00',
            'quantity_before' => '0.00',
            'quantity_after' => '5.00',
            'variant_id' => null,
        ]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        // The stock_movement row must remain untouched (variant_id still NULL)
        $this->assertSame(1, DB::table('stock_movements')
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->count());

        $this->assertSame(0, DB::table('stock_movements')
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->defaultVariant->id)
            ->count());
    }

    // -------------------------------------------------------------------------
    // stock_reservations — open migrated, closed (released) left alone
    // -------------------------------------------------------------------------

    public function test_open_reservations_migrated_not_released(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        $openId = (string) Str::uuid();
        $closedId = (string) Str::uuid();

        // Open reservation (released_at IS NULL) — should be migrated
        DB::table('stock_reservations')->insert([
            'id' => $openId,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'quantity' => '3.0000',
            'source_type' => 'sales_order',
            'source_id' => (string) Str::uuid(),
            'source_line_id' => null,
            'expires_at' => null,
            'expired_at' => null,
            'released_at' => null,
            'released_by' => null,
            'release_reason' => null,
            'priority' => 0,
        ]);

        // Closed reservation (released_at IS NOT NULL) — must stay NULL
        DB::table('stock_reservations')->insert([
            'id' => $closedId,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'quantity' => '2.0000',
            'source_type' => 'sales_order',
            'source_id' => (string) Str::uuid(),
            'source_line_id' => null,
            'expires_at' => null,
            'expired_at' => null,
            'released_at' => now()->subHour()->toDateTimeString(),
            'released_by' => null,
            'release_reason' => 'fulfilled',
            'priority' => 0,
        ]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        // Open reservation is now assigned to the default variant
        $open = DB::table('stock_reservations')->where('id', $openId)->first();
        $this->assertNotNull($open);
        $this->assertSame($this->defaultVariant->id, $open->variant_id);

        // Closed reservation is left untouched (variant_id still NULL)
        $closed = DB::table('stock_reservations')->where('id', $closedId)->first();
        $this->assertNotNull($closed);
        $this->assertNull($closed->variant_id);
    }

    // -------------------------------------------------------------------------
    // product_batches — active migrated, inactive left alone
    // -------------------------------------------------------------------------

    public function test_active_batches_migrated_inactive_left_alone(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        $activeBatchId = (string) Str::uuid();
        $inactiveBatchId = (string) Str::uuid();

        // Active batch (is_active = true) — should be migrated
        DB::table('product_batches')->insert([
            'uuid' => $activeBatchId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'batch_number' => 'BATCH-ACTIVE-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'is_active' => true,
            'is_expired' => false,
            'is_recalled' => false,
        ]);

        // Inactive batch (is_active = false) — must stay NULL
        DB::table('product_batches')->insert([
            'uuid' => $inactiveBatchId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'variant_id' => null,
            'batch_number' => 'BATCH-INACTIVE-002',
            'expiry_date' => now()->subDay()->toDateString(),
            'is_active' => false,
            'is_expired' => true,
            'is_recalled' => false,
        ]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        // Active batch now points to the default variant
        $active = DB::table('product_batches')->where('uuid', $activeBatchId)->first();
        $this->assertNotNull($active);
        $this->assertSame($this->defaultVariant->id, $active->variant_id);

        // Inactive batch is left untouched (variant_id still NULL)
        $inactive = DB::table('product_batches')->where('uuid', $inactiveBatchId)->first();
        $this->assertNotNull($inactive);
        $this->assertNull($inactive->variant_id);
    }

    // -------------------------------------------------------------------------
    // recipe_lines — component pointing at product gets rewritten
    // -------------------------------------------------------------------------

    public function test_recipes_pointing_at_product_get_rewritten_to_default_variant(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        // We need a composite_item and a recipe first (recipe_lines.recipe_id FK)
        $compositeItemId = (string) Str::uuid();
        DB::table('composite_items')->insert([
            'id' => $compositeItemId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CI-RECIPE-TEST',
            'name' => 'Test Composite Item',
            'base_price' => '100.0000',
            'is_active' => true,
        ]);

        $recipeId = (string) Str::uuid();
        DB::table('recipes')->insert([
            'id' => $recipeId,
            'composite_item_id' => $compositeItemId,
            'version' => 1,
            'is_active' => true,
            'yield_quantity' => '1.0000',
        ]);

        $lineId = (string) Str::uuid();
        DB::table('recipe_lines')->insert([
            'id' => $lineId,
            'recipe_id' => $recipeId,
            'component_type' => 'product',
            'component_id' => $this->product->id,
            'component_variant_id' => null,
            'quantity' => '2.0000',
            'is_optional' => false,
            'is_scalable' => true,
            'wastage_percent' => '0.00',
            'display_order' => 1,
        ]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        $line = DB::table('recipe_lines')->where('id', $lineId)->first();
        $this->assertNotNull($line);
        $this->assertSame($this->defaultVariant->id, $line->component_variant_id);
    }

    // -------------------------------------------------------------------------
    // recipe_lines — lines for a DIFFERENT component_id must be untouched
    // -------------------------------------------------------------------------

    public function test_recipe_lines_for_other_products_not_touched(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $compositeItemId = (string) Str::uuid();
        DB::table('composite_items')->insert([
            'id' => $compositeItemId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CI-OTHER-TEST',
            'name' => 'Other Composite Item',
            'base_price' => '50.0000',
            'is_active' => true,
        ]);

        $recipeId = (string) Str::uuid();
        DB::table('recipes')->insert([
            'id' => $recipeId,
            'composite_item_id' => $compositeItemId,
            'version' => 1,
            'is_active' => true,
            'yield_quantity' => '1.0000',
        ]);

        $otherLineId = (string) Str::uuid();
        DB::table('recipe_lines')->insert([
            'id' => $otherLineId,
            'recipe_id' => $recipeId,
            'component_type' => 'product',
            'component_id' => $otherProduct->id,
            'component_variant_id' => null,
            'quantity' => '1.0000',
            'is_optional' => false,
            'is_scalable' => true,
            'wastage_percent' => '0.00',
            'display_order' => 1,
        ]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        $otherLine = DB::table('recipe_lines')->where('id', $otherLineId)->first();
        $this->assertNotNull($otherLine);
        $this->assertNull($otherLine->component_variant_id);
    }

    // -------------------------------------------------------------------------
    // Event emission
    // -------------------------------------------------------------------------

    public function test_emits_stock_levels_migrated_event_after_commit(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        Event::assertDispatched(
            StockLevelsMigratedToDefaultVariant::class,
            function (StockLevelsMigratedToDefaultVariant $event): bool {
                return $event->productId === (string) $this->product->id
                    && $event->defaultVariantId === $this->defaultVariant->id;
            }
        );
    }

    // -------------------------------------------------------------------------
    // no-op when no pre-existing product-level data
    // -------------------------------------------------------------------------

    public function test_noop_when_no_product_level_stock_exists(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        // No stock_levels / reservations / batches / recipe_lines for this product

        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
        );

        // Event is still emitted (migration ran, just nothing to move)
        Event::assertDispatched(StockLevelsMigratedToDefaultVariant::class);

        // No rows were created
        $this->assertSame(0, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Large-migration guard
    // -------------------------------------------------------------------------

    public function test_large_migration_refused_without_override(): void
    {
        // Insert 5001 stock_levels rows for this product across distinct locations
        // to exceed the 5000 threshold.  We use bulk inserts to keep this fast.
        $batchSize = 250;
        $totalRows = 5001;

        // Pre-create enough distinct locations so each stock_levels row is unique
        // (tenant_id + product_id + location_id must be unique per partial index).
        for ($i = 0; $i < $totalRows; $i += $batchSize) {
            $batch = [];
            $count = min($batchSize, $totalRows - $i);
            for ($j = 0; $j < $count; $j++) {
                $locId = (string) Str::uuid();
                DB::table('locations')->insert([
                    'id' => $locId,
                    'company_id' => $this->company->id,
                    'name' => 'Loc-'.$locId,
                    'code' => strtoupper(substr(str_replace('-', '', $locId), 0, 12)),
                    'type' => 'warehouse',
                    'is_active' => true,
                ]);
                $batch[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $this->tenant->id,
                    'company_id' => $this->company->id,
                    'product_id' => $this->product->id,
                    'location_id' => $locId,
                    'variant_id' => null,
                    'quantity' => '1.00',
                    'reserved' => '0.00',
                ];
            }
            DB::table('stock_levels')->insert($batch);
        }

        $this->assertSame($totalRows, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->count());

        try {
            $this->service->migrateToDefaultVariant(
                productId: (string) $this->product->id,
                defaultVariantId: $this->defaultVariant->id,
                allowLargeMigration: false,
            );
            $this->fail('Expected LargeMigrationRefusalException was not thrown.');
        } catch (LargeMigrationRefusalException) {
            // Data must be completely untouched — all rows still have variant_id NULL.
            $this->assertSame(
                $totalRows,
                DB::table('stock_levels')
                    ->where('product_id', $this->product->id)
                    ->whereNull('variant_id')
                    ->count(),
                'stock_levels rows must remain variant_id NULL after a refused migration.'
            );

            $this->assertSame(
                0,
                DB::table('stock_levels')
                    ->where('product_id', $this->product->id)
                    ->whereNotNull('variant_id')
                    ->count(),
                'No stock_levels rows should have been migrated after a refused migration.'
            );
        }
    }

    public function test_large_migration_allowed_with_override(): void
    {
        Event::fake([StockLevelsMigratedToDefaultVariant::class]);

        // Same setup as refusal test but pass allowLargeMigration = true
        $batchSize = 250;
        $totalRows = 5001;

        for ($i = 0; $i < $totalRows; $i += $batchSize) {
            $batch = [];
            $count = min($batchSize, $totalRows - $i);
            for ($j = 0; $j < $count; $j++) {
                $locId = (string) Str::uuid();
                DB::table('locations')->insert([
                    'id' => $locId,
                    'company_id' => $this->company->id,
                    'name' => 'Loc-'.$locId,
                    'code' => strtoupper(substr(str_replace('-', '', $locId), 0, 12)),
                    'type' => 'warehouse',
                    'is_active' => true,
                ]);
                $batch[] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $this->tenant->id,
                    'company_id' => $this->company->id,
                    'product_id' => $this->product->id,
                    'location_id' => $locId,
                    'variant_id' => null,
                    'quantity' => '1.00',
                    'reserved' => '0.00',
                ];
            }
            DB::table('stock_levels')->insert($batch);
        }

        // Should NOT throw
        $this->service->migrateToDefaultVariant(
            productId: (string) $this->product->id,
            defaultVariantId: $this->defaultVariant->id,
            allowLargeMigration: true,
        );

        // All rows should now have the variant_id set
        $this->assertSame(0, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->whereNull('variant_id')
            ->count());

        $this->assertSame($totalRows, DB::table('stock_levels')
            ->where('product_id', $this->product->id)
            ->where('variant_id', $this->defaultVariant->id)
            ->count());

        Event::assertDispatched(StockLevelsMigratedToDefaultVariant::class);
    }
}
