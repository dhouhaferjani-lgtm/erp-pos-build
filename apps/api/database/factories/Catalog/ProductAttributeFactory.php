<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttribute>
 */
class ProductAttributeFactory extends Factory
{
    protected $model = ProductAttribute::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::uuid(),
            'code' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'data_type' => AttributeDataType::Selection,
            'is_variant_axis' => true,
            'display_order' => 0,
            'is_active' => true,
        ];
    }
}
