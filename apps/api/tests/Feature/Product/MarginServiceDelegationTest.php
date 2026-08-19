<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginServiceDelegationTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_margins_reflect_three_level_resolution(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create([
            'default_target_margin' => '20',
            'default_minimum_margin' => '10',
        ]);
        $product = Product::factory()->for($company)->create([
            'target_margin_override' => '45.00',
        ]);

        $margins = app(MarginService::class)->getEffectiveMargins($product);

        $this->assertSame('45.00', $margins['target_margin']);
        $this->assertSame('product', $margins['source']);
    }

    public function test_update_sale_price_skips_manual_products(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Manual,
            'cost_price' => '10.000000',
            'sale_price' => '99.000',
            'target_margin_override' => '50.00',
        ]);

        $changed = app(MarginService::class)->updateSalePrice($product);

        $this->assertFalse($changed);
        $this->assertSame('99.000', (string) $product->fresh()->sale_price);
    }

    public function test_update_sale_price_reprices_auto_products(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Auto,
            'cost_price' => '10.000000',
            'sale_price' => '0.000',
            'target_margin_override' => '50.00',
        ]);

        $changed = app(MarginService::class)->updateSalePrice($product);

        $this->assertTrue($changed);
        // 10 * (1 + 50/100) = 15; decimal:3 cast on fresh() normalises to '15.000'
        $this->assertSame('15.000', (string) $product->fresh()->sale_price);
    }

    public function test_reprice_does_not_throw_without_company_context(): void
    {
        app(CompanyContext::class)->clear(); // simulate queue worker — no bound context

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['currency' => 'TND']);
        $product = Product::factory()->for($company)->create([
            'pricing_mode' => PricingMode::Auto,
            'cost_price' => '10.000000',
            'target_margin_override' => '50.00',
        ]);

        app(MarginService::class)->updateSalePrice($product); // must not throw

        $this->assertTrue(true);
    }
}
