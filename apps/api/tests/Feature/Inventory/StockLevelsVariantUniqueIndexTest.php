<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.1.5 — verify partial unique indexes on stock_levels.
 *
 * These tests assert the two PostgreSQL partial unique indexes introduced by
 * migration 2026_06_02_100005:
 *   - stock_levels_non_variant  (variant_id IS NULL)
 *   - stock_levels_with_variant (variant_id IS NOT NULL)
 *
 * The legacy unique constraint stock_levels_tenant_id_product_id_location_id_unique
 * is dropped by the same migration, so the three scenarios below verify
 * the replacement semantics precisely.
 */
class StockLevelsVariantUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /**
     * Build the base column set required for a stock_levels row.
     *
     * Required NOT NULL columns (no DB default):
     *   tenant_id, company_id, product_id, location_id
     * Columns with DB defaults (quantity = 0, reserved = 0): omitted.
     *
     * @return array<string, mixed>
     */
    private function baseRow(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'location_id' => $this->location->id,
            'variant_id' => null,
            'quantity' => '0.00',
            'reserved' => '0.00',
        ];
    }

    /**
     * A non-variant row and a variant row for the same (tenant, product, location)
     * must both succeed — they fall under different partial indexes.
     */
    public function test_can_have_one_non_variant_and_one_variant_row_for_same_product_location(): void
    {
        // Create a variant whose product/tenant/company matches our shared fixtures.
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        // Non-variant row (variant_id IS NULL)
        DB::table('stock_levels')->insert($this->baseRow());

        // Variant row (variant_id IS NOT NULL) — same product + location
        DB::table('stock_levels')->insert(array_merge($this->baseRow(), [
            'id' => (string) Str::uuid(),
            'variant_id' => $variant->id,
        ]));

        $this->assertSame(2, DB::table('stock_levels')->count());
    }

    /**
     * Two non-variant rows with the same (tenant, product, location) must be
     * rejected by the stock_levels_non_variant partial unique index.
     */
    public function test_duplicate_non_variant_row_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        DB::table('stock_levels')->insert($this->baseRow());

        // Second insert with variant_id = null and same composite key
        DB::table('stock_levels')->insert(array_merge($this->baseRow(), [
            'id' => (string) Str::uuid(),
            'variant_id' => null,
        ]));
    }

    /**
     * Two rows for the same (tenant, product, variant, location) must be
     * rejected by the stock_levels_with_variant partial unique index.
     */
    public function test_duplicate_variant_row_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        $this->expectException(QueryException::class);

        DB::table('stock_levels')->insert(array_merge($this->baseRow(), [
            'variant_id' => $variant->id,
        ]));

        // Second insert — same variant_id + same composite key
        DB::table('stock_levels')->insert(array_merge($this->baseRow(), [
            'id' => (string) Str::uuid(),
            'variant_id' => $variant->id,
        ]));
    }
}
