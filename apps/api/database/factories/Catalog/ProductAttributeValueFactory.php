<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductAttributeValue>
 */
class ProductAttributeValueFactory extends Factory
{
    protected $model = ProductAttributeValue::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => (string) Str::uuid(),
            'attribute_id' => ProductAttribute::factory(),
            'code' => $this->faker->unique()->slug(1),
            'label' => $this->faker->words(2, true),
            'hex_color' => null,
            'image_url' => null,
            'display_order' => 0,
        ];
    }
}
