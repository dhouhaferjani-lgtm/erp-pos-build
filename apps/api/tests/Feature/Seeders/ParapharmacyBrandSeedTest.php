<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 13: verify that ParapharmacySeeder seeds ≥ 15 brands and assigns
 * brand_id + brand_source='user' to ≥ 80% of cosmetic products.
 */
final class ParapharmacyBrandSeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_brands_seeded_with_at_least_15_rows(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $this->assertGreaterThanOrEqual(
            15,
            Brand::count(),
            'seedBrands() must insert at least 15 brand rows',
        );
    }

    public function test_brand_slugs_match_slugfor_convention(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $wrongSlug = Brand::all()->first(
            fn (Brand $b): bool => $b->slug !== Brand::slugFor($b->name),
        );

        $this->assertNull($wrongSlug, 'Every brand slug must equal Brand::slugFor(name)');
    }

    public function test_at_least_80_percent_of_cosmetic_products_have_brand_id(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $cosmeticIds = ParapharmacyProductMetadata::where('category', 'cosmetic')
            ->pluck('product_id');

        $total = $cosmeticIds->count();
        $this->assertGreaterThan(0, $total, 'Expected cosmetic products to exist after seeding');

        $branded = Product::whereIn('id', $cosmeticIds)
            ->whereNotNull('brand_id')
            ->count();

        $this->assertGreaterThanOrEqual(
            (int) ceil(0.8 * $total),
            $branded,
            "At least 80% of cosmetic products must have brand_id assigned (got {$branded}/{$total})",
        );
    }

    public function test_cosmetic_products_with_brand_id_have_brand_source_user(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $cosmeticIds = ParapharmacyProductMetadata::where('category', 'cosmetic')
            ->pluck('product_id');

        $wrongSource = Product::whereIn('id', $cosmeticIds)
            ->whereNotNull('brand_id')
            ->where('brand_source', '!=', 'user')
            ->count();

        $this->assertSame(
            0,
            $wrongSource,
            'All cosmetic products with brand_id must have brand_source = "user"',
        );
    }
}
