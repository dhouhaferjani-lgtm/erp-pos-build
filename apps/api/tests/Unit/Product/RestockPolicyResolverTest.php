<?php

declare(strict_types=1);

namespace Tests\Unit\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Product\Application\Services\RestockPolicyResolver;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RestockPolicyResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
    }

    private function resolver(): RestockPolicyResolver
    {
        return $this->app->make(RestockPolicyResolver::class);
    }

    public function test_product_override_wins(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::Never->value,
        ]);

        $result = $this->resolver()->resolve($product->id);

        $this->assertSame(RestockPolicy::Never, $result->policy);
        $this->assertSame(RestockPolicySource::Product, $result->source);
        $this->assertNull($result->sourceCategoryId);
    }

    public function test_parent_category_supplies_when_product_and_leaf_null(): void
    {
        $parent = Category::factory()->create([
            'company_id' => $this->company->id,
            'restock_policy' => RestockPolicy::IfSealed->value,
        ]);
        $leaf = Category::factory()->create([
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
            'restock_policy' => null,
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'category_id' => $leaf->id,
            'restock_policy' => null,
        ]);

        $result = $this->resolver()->resolve($product->id);

        $this->assertSame(RestockPolicy::IfSealed, $result->policy);
        $this->assertSame(RestockPolicySource::Category, $result->source);
        $this->assertSame($parent->id, $result->sourceCategoryId);
    }

    public function test_falls_back_to_company_then_default(): void
    {
        $company = Company::factory()->for($this->tenant)->create([
            'reservation_settings' => ['default_restock_policy' => RestockPolicy::IfSealed->value],
        ]);
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $company->id,
            'restock_policy' => null,
            'category_id' => null,
        ]);

        $result = $this->resolver()->resolve($product->id);

        $this->assertSame(RestockPolicy::IfSealed, $result->policy);
        $this->assertSame(RestockPolicySource::Company, $result->source);
        $this->assertNull($result->sourceCategoryId);
    }

    public function test_default_fallback_when_nothing_set(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'restock_policy' => null,
            'category_id' => null,
        ]);

        $result = $this->resolver()->resolve($product->id);

        $this->assertSame(RestockPolicy::DefaultAllow, $result->policy);
        $this->assertSame(RestockPolicySource::DefaultFallback, $result->source);
        $this->assertNull($result->sourceCategoryId);
    }
}
