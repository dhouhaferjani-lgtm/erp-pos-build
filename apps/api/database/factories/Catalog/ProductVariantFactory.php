<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Product\Domain\Product;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        // Resolve or create the parent product so we can derive tenant_id / company_id.
        // Tests that need specific wiring should pass product_id, company_id, tenant_id
        // explicitly via ->create([...]).
        $tenantId = (string) Str::uuid();
        $company = CompanyFactory::new()->create(['tenant_id' => $tenantId]);

        $product = Product::factory()->create([
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
        ]);

        return [
            'tenant_id' => $tenantId,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'variant_code' => 'VAR-'.$this->faker->unique()->numerify('######'),
            'sku' => 'SKU-'.$this->faker->unique()->numerify('########'),
            'barcode' => null,
            'name_suffix' => $this->faker->words(2, true),
            'is_default' => false,
            'is_active' => true,
            'display_order' => 0,
            'price_override' => null,
            'cost_override' => null,
            'image_url' => null,
        ];
    }
}
