<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Product\Domain\Product;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The deployed Tunisia parapharmacy demo (DemoPharmacySeeder, run by the docker
 * entrypoint) must give every product a real unit so the per-unit quantity-step
 * feature is demonstrable: pieces products step by 1, weight/volume products
 * step fractionally. DemoPharmacySeeder inherits createProduct() from
 * ParapharmacySeeder, so this guards that inheritance end-to-end on the actual
 * deployed seeder.
 */
final class DemoPharmacyProductUnitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_products_all_have_units_with_both_step_behaviors(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $this->assertGreaterThan(0, Product::count());
        $this->assertSame(
            0,
            Product::whereNull('unit_id')->count(),
            'every deployed demo product must carry a unit (else qty steps by 0.0001)',
        );

        // The mix the demo needs: at least one step-1 product and at least one
        // fractional-step product.
        $this->assertTrue(
            Product::whereHas('unitOfMeasure', fn ($q) => $q->where('decimal_places', 0))->exists(),
            'demo must contain a step-1 (pieces) product',
        );
        $this->assertTrue(
            Product::whereHas('unitOfMeasure', fn ($q) => $q->where('decimal_places', '>', 0))->exists(),
            'demo must contain a fractional-step (kg/l) product',
        );
    }
}
