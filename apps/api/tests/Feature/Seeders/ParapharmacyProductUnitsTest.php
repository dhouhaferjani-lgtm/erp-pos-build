<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use Database\Seeders\ParapharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-unit quantity-step feature derives the qty input's step from a
 * product's unit precision (Unit.decimal_places → quantity_decimals →
 * step = 1/10^decimals). A product with no unit falls back to 4 decimals
 * (step 0.0001 — the old buggy behavior). The parapharmacy demo seeds ~1000
 * products; if none carry a unit, the feature is invisible in the demo.
 *
 * These tests pin that the seeder seeds units AND assigns every product a real
 * unit (FK + mirrored string), with a deliberate mix so the demo shows both a
 * step-1 (pieces) product and a fractional-step (kg/g) product.
 */
final class ParapharmacyProductUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_system_units_in_the_tenant(): void
    {
        $this->seed(ParapharmacySeeder::class);

        foreach (['pc', 'kg', 'l'] as $code) {
            $this->assertTrue(
                Unit::where('code', $code)->exists(),
                "unit '{$code}' must be seeded so products can link to it",
            );
        }

        $this->assertSame(0, (int) Unit::where('code', 'pc')->value('decimal_places'), 'pc must step by 1');
        $this->assertGreaterThan(0, (int) Unit::where('code', 'kg')->value('decimal_places'), 'kg must step fractionally');
        $this->assertGreaterThan(0, (int) Unit::where('code', 'l')->value('decimal_places'), 'l must step fractionally');
    }

    public function test_every_product_has_a_unit(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $this->assertGreaterThan(0, Product::count());
        $this->assertSame(
            0,
            Product::whereNull('unit_id')->count(),
            'no demo product may be left without a unit (else qty steps by 0.0001)',
        );
    }

    public function test_unit_string_mirrors_the_linked_unit_code(): void
    {
        $this->seed(ParapharmacySeeder::class);

        $product = Product::with('unitOfMeasure')->whereNotNull('unit_id')->firstOrFail();
        $this->assertNotNull($product->unitOfMeasure);
        $this->assertSame(
            $product->unitOfMeasure->code,
            $product->unit,
            'the legacy unit string column must mirror the linked unit code',
        );
    }

    public function test_assigns_a_demonstrable_mix_of_pieces_and_weight_units(): void
    {
        $this->seed(ParapharmacySeeder::class);

        // Pieces categories step by 1.
        $supplement = $this->firstProductInCategory(ParapharmacyCategory::Supplement);
        $this->assertSame('pc', $supplement->unit);
        $this->assertSame(0, (int) $supplement->unitOfMeasure->decimal_places);

        // Sports nutrition is sold by weight → kg → fractional step (0.001).
        $sports = $this->firstProductInCategory(ParapharmacyCategory::SportsNutrition);
        $this->assertSame('kg', $sports->unit);
        $this->assertGreaterThan(0, (int) $sports->unitOfMeasure->decimal_places);

        // Herbal extracts/oils/syrups sold by volume → l → fractional step (0.001).
        $herbal = $this->firstProductInCategory(ParapharmacyCategory::Herbal);
        $this->assertSame('l', $herbal->unit);
        $this->assertGreaterThan(0, (int) $herbal->unitOfMeasure->decimal_places);

        // Both behaviors are present in the catalog.
        $this->assertTrue(
            Product::whereHas('unitOfMeasure', fn ($q) => $q->where('decimal_places', 0))->exists(),
            'demo must contain a step-1 (pieces) product',
        );
        $this->assertTrue(
            Product::whereHas('unitOfMeasure', fn ($q) => $q->where('decimal_places', '>', 0))->exists(),
            'demo must contain a fractional-step product',
        );
    }

    private function firstProductInCategory(ParapharmacyCategory $category): Product
    {
        return Product::with('unitOfMeasure')
            ->whereHas('parapharmacyMetadata', fn ($q) => $q->where('category', $category))
            ->firstOrFail();
    }
}
