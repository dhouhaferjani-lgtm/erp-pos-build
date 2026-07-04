<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillPricingModeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_priced_row_becomes_auto(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create([
            'currency'              => 'TND',
            'default_target_margin' => '50',
        ]);
        // cost 10, margin 50% → price = 10 * 1.5 = 15.000 (3dp TND)
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price'   => '10.000000',
            'sale_price'   => '15.000',
        ]);

        $this->artisan('products:backfill-pricing-mode')->assertExitCode(0);

        $this->assertSame(PricingMode::Auto, $product->fresh()->pricing_mode);
    }

    public function test_hand_set_row_stays_manual(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create([
            'default_target_margin' => '50',
        ]);
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price'   => '10.000000',
            'sale_price'   => '99.000',
        ]);

        $this->artisan('products:backfill-pricing-mode');

        $this->assertSame(PricingMode::Manual, $product->fresh()->pricing_mode);
    }

    public function test_zero_cost_row_stays_manual(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->for(Company::factory()->for($tenant))->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price'   => '0.000000',
            'sale_price'   => '5.000',
        ]);

        $this->artisan('products:backfill-pricing-mode');

        $this->assertSame(PricingMode::Manual, $product->fresh()->pricing_mode);
    }

    public function test_idempotent_on_rerun(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create([
            'default_target_margin' => '50',
        ]);
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price'   => '10.000000',
            'sale_price'   => '15.000',
        ]);

        $this->artisan('products:backfill-pricing-mode');
        $this->artisan('products:backfill-pricing-mode'); // second run

        $this->assertSame(PricingMode::Auto, $product->fresh()->pricing_mode);
    }
}
