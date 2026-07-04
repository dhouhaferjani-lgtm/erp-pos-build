<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\MarginResolver;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarginResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): MarginResolver
    {
        return app(MarginResolver::class); // resolver itself is constructor-injected; app() only in tests
    }

    public function test_product_override_wins(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $product = Product::factory()->for($company)->create(['target_margin_override' => '40.00']);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('40.00', $m->target_margin);
        $this->assertSame(MarginSource::Product, $m->target_source);
    }

    public function test_inherits_from_grandparent_category(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $grand = Category::factory()->for($company)->create(['target_margin_override' => '50.00']);
        $parent = Category::factory()->for($company)->create(['parent_id' => $grand->id]);
        $leaf = Category::factory()->for($company)->create(['parent_id' => $parent->id]);
        $product = Product::factory()->for($company)->create(['category_id' => $leaf->id]);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('50.00', $m->target_margin);
        $this->assertSame(MarginSource::Category, $m->target_source);
        $this->assertSame($grand->id, $m->target_source_category_id);
    }

    public function test_company_default_when_no_overrides(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '25', 'default_minimum_margin' => '12']);
        $product = Product::factory()->for($company)->create();
        $m = $this->resolver()->resolve($product);
        $this->assertSame('25.00', $m->target_margin);
        $this->assertSame(MarginSource::Company, $m->target_source);
    }

    public function test_independent_target_and_minimum_resolution_with_clamp(): void
    {
        // target from product (25), minimum from category (35) -> would invert -> clamp to 25
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $cat = Category::factory()->for($company)->create(['minimum_margin_override' => '35.00']);
        $product = Product::factory()->for($company)->create(['category_id' => $cat->id, 'target_margin_override' => '25.00']);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('25.00', $m->target_margin);
        $this->assertSame('25.00', $m->minimum_margin);
        $this->assertTrue($m->minimum_clamped);
        $this->assertSame(MarginSource::Category, $m->minimum_source);
    }

    public function test_nearest_category_beats_far_ancestor(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $grand = Category::factory()->for($company)->create(['target_margin_override' => '50.00']);
        $parent = Category::factory()->for($company)->create(['parent_id' => $grand->id, 'target_margin_override' => '45.00']);
        $leaf = Category::factory()->for($company)->create(['parent_id' => $parent->id]);
        $product = Product::factory()->for($company)->create(['category_id' => $leaf->id]);
        $m = $this->resolver()->resolve($product);
        $this->assertSame('45.00', $m->target_margin);                 // parent (nearest) wins, not grandparent
        $this->assertSame($parent->id, $m->target_source_category_id);
    }

    public function test_falls_back_to_hardcoded_when_company_default_is_corrupt(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '10']);
        // Corrupt the NOT NULL defaults to empty strings directly in the DB (bypassing the model)
        \Illuminate\Support\Facades\DB::table('companies')->where('id', $company->id)
            ->update(['default_target_margin' => '', 'default_minimum_margin' => '']);
        $product = Product::factory()->for($company)->create();      // no overrides, no category
        $fresh = Product::query()->with('company')->findOrFail($product->id);
        $m = $this->resolver()->resolve($fresh);
        $this->assertSame('30.00', $m->target_margin);
        $this->assertSame(\App\Modules\Product\Domain\Enums\MarginSource::DefaultFallback, $m->target_source);
        $this->assertSame('15.00', $m->minimum_margin);
        $this->assertSame(\App\Modules\Product\Domain\Enums\MarginSource::DefaultFallback, $m->minimum_source);
    }

    public function test_resolve_many_is_bounded_query(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->for($tenant)->create(['default_target_margin' => '30', 'default_minimum_margin' => '15']);
        $cat = Category::factory()->for($company)->create(['target_margin_override' => '40.00']);
        $products = Product::factory()->count(20)->for($company)->create(['category_id' => $cat->id]);
        \DB::enableQueryLog();
        $result = $this->resolver()->resolveMany($products);
        $queryCount = count(\DB::getQueryLog());
        \DB::disableQueryLog();
        $this->assertCount(20, $result);
        $this->assertLessThanOrEqual(3, $queryCount, 'resolveMany must not be N+1');
    }
}
