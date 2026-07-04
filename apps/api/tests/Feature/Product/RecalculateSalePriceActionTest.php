<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\RecalculateSalePriceAction;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecalculateSalePriceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalc_reprices_manual_product_and_sets_auto(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($company)->create([
            'pricing_mode'           => PricingMode::Manual,
            'cost_price'             => '10.000000',
            'sale_price'             => '99.000',
            'target_margin_override' => '50.00',
        ]);

        app(RecalculateSalePriceAction::class)->execute($product);

        $fresh = $product->fresh();
        $this->assertSame(PricingMode::Auto, $fresh->pricing_mode);
        // 10 * (1 + 50/100) = 15; decimal:3 normalises to '15.000'
        $this->assertSame('15.000', (string) $fresh->sale_price);
    }
}
