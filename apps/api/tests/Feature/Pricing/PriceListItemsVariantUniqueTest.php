<?php

declare(strict_types=1);

namespace Tests\Feature\Pricing;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 1.1.9 — verify partial unique indexes on price_list_items
 * and the variant_id column on catalog_cart_items.
 *
 * Assertions for migration 2026_06_02_100014:
 *   - price_list_items_non_variant  (variant_id IS NULL)
 *   - price_list_items_with_variant (variant_id IS NOT NULL)
 *
 * The legacy unique constraint price_list_product_qty_unique over
 * (price_list_id, product_id, min_quantity) is dropped by the same
 * migration; the partial indexes replace its semantics and extend them
 * to support variants.
 *
 * Assertion for migration 2026_06_02_100013:
 *   - catalog_cart_items.variant_id column exists.
 */
class PriceListItemsVariantUniqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    /** @var array<string, mixed> */
    private array $priceList;

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

        // Create a price list row directly — no factory exists for PriceList.
        $priceListId = (string) Str::uuid();
        DB::table('price_lists')->insert([
            'id' => $priceListId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'PLI-TEST-'.Str::random(6),
            'name' => 'Test Price List',
            'currency' => 'TND',
            'is_active' => true,
            'is_default' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->priceList = ['id' => $priceListId];
    }

    /**
     * Build the base column set required for a price_list_items row.
     *
     * Required NOT NULL columns (no DB default):
     *   id, price_list_id, product_id, price
     * Columns with DB defaults (min_quantity = 1.00): min_quantity kept explicit
     * to make the composite key unambiguous in the partial indexes.
     *
     * @return array<string, mixed>
     */
    private function baseRow(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'price_list_id' => $this->priceList['id'],
            'product_id' => $this->product->id,
            'variant_id' => null,
            'price' => '25.000',
            'min_quantity' => '1.00',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * A price_list_item with variant_id=null and one with variant_id=$variant->id,
     * sharing the same (price_list_id, product_id, min_quantity), must both
     * succeed — they fall under different partial indexes.
     */
    public function test_variant_and_non_variant_price_list_items_coexist(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);

        // Non-variant row (variant_id IS NULL)
        DB::table('price_list_items')->insert($this->baseRow());

        // Variant row (variant_id IS NOT NULL) — same price_list_id, product_id, min_quantity
        DB::table('price_list_items')->insert(array_merge($this->baseRow(), [
            'id' => (string) Str::uuid(),
            'variant_id' => $variant->id,
        ]));

        $this->assertSame(2, DB::table('price_list_items')->count());
    }

    /**
     * Two non-variant rows with the same (price_list_id, product_id, min_quantity)
     * must be rejected by the price_list_items_non_variant partial unique index.
     */
    public function test_duplicate_non_variant_price_list_item_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique indexes are PostgreSQL-only.');
        }

        $this->expectException(QueryException::class);

        DB::table('price_list_items')->insert($this->baseRow());

        // Second insert — same (price_list_id, product_id, min_quantity) with variant_id null
        DB::table('price_list_items')->insert(array_merge($this->baseRow(), [
            'id' => (string) Str::uuid(),
            'variant_id' => null,
        ]));
    }

    /**
     * catalog_cart_items must have a variant_id column after migration 2026_06_02_100013.
     */
    public function test_catalog_cart_items_has_variant_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('catalog_cart_items', 'variant_id'),
            'catalog_cart_items must have a variant_id column after migration 2026_06_02_100013.',
        );
    }
}
