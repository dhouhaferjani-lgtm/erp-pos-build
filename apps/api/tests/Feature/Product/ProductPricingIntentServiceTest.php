<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\ProductPricingIntentService;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPricingIntentServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ProductPricingIntentService
    {
        return app(ProductPricingIntentService::class);
    }

    /**
     * Explicit pricing_mode=manual in payload wins; sale_price is applied.
     */
    public function test_explicit_manual_intent_on_price_edit(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Auto]);

        $this->service()->applyIntent($product, ['sale_price' => '50.000', 'pricing_mode' => 'manual']);

        $this->assertSame(PricingMode::Manual, $product->pricing_mode);
        $this->assertSame('50.000', $product->sale_price);
    }

    /**
     * Explicit pricing_mode=auto + target override different from inherited → stores override.
     * The decimal:3 cast on target_margin_override normalises '45.00' → '45.000' on read.
     */
    public function test_margin_edit_keeps_auto_and_persists_override_when_different(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30']);
        $product = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Manual]);

        $this->service()->applyIntent($product, ['pricing_mode' => 'auto', 'target_margin_override' => '45.00']);

        $this->assertSame(PricingMode::Auto, $product->pricing_mode);
        // decimal:3 cast normalises the stored '45.00' to '45.000' on read
        $this->assertSame('45.000', $product->target_margin_override);
    }

    /**
     * When the submitted target equals the inherited effective target, store null (keep inheriting).
     * Company default 30 → inherited is '30.00'; payload '30.00' → null.
     */
    public function test_margin_equal_to_inherited_stores_null(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30']);
        $product = Product::factory()->for($company)->create();

        $this->service()->applyIntent($product, ['pricing_mode' => 'auto', 'target_margin_override' => '30.00']);

        $this->assertNull($product->target_margin_override);
    }

    /**
     * Import path: sale_price present but no pricing_mode → infer Manual.
     */
    public function test_import_path_without_mode_becomes_manual(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create();
        $product = Product::factory()->for($company)->create(['pricing_mode' => PricingMode::Auto]);

        $this->service()->applyIntent($product, ['sale_price' => '12.000']); // no pricing_mode key

        $this->assertSame(PricingMode::Manual, $product->pricing_mode);
    }

    /**
     * Correctness fix: product already has its own override ('45.00'); company default is 30.
     * Submitting target_margin_override='30.00' should compare against the INHERITED baseline
     * (company 30, not the product's old 45), detect equality, and store null.
     *
     * Without the fix the resolver would see the OLD override '45.000' → inherited='45.00',
     * bccomp('30.00','45.00',2)≠0 → would persist '30.00' instead of storing null.
     */
    public function test_override_equal_to_inherited_when_product_had_prior_override(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30']);
        // Product starts with its own override of 45
        $product = Product::factory()->for($company)->create(['target_margin_override' => '45.00']);

        // Submit 30.00 — equals the company/inherited baseline, NOT the old 45
        $this->service()->applyIntent($product, ['target_margin_override' => '30.00']);

        $this->assertNull($product->target_margin_override);
    }
}
