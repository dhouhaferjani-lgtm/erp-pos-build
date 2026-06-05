<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductVariantAttributeValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_junction_enforces_unique_attribute_per_variant(): void
    {
        $this->expectException(QueryException::class);

        $variant = ProductVariantFactory::new()->create();
        $attribute = ProductAttributeFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
        ]);
        $value1 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
            'attribute_id' => $attribute->id,
        ]);
        $value2 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
            'attribute_id' => $attribute->id,
        ]);

        // First assignment: variant → attribute → value1 (must succeed)
        ProductVariantAttributeValue::create([
            'variant_id' => $variant->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value1->id,
        ]);

        // Second assignment: same variant + same attribute but value2 — must violate unique(variant_id, attribute_id)
        ProductVariantAttributeValue::create([
            'variant_id' => $variant->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value2->id,
        ]);
    }

    public function test_deleting_attribute_in_use_is_restricted(): void
    {
        $this->expectException(QueryException::class);

        $variant = ProductVariantFactory::new()->create();
        $attribute = ProductAttributeFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
        ]);
        $value = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
            'attribute_id' => $attribute->id,
        ]);

        ProductVariantAttributeValue::create([
            'variant_id' => $variant->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value->id,
        ]);

        // Must throw due to RESTRICT FK on product_attributes
        DB::table('product_attributes')->where('id', $attribute->id)->delete();
    }
}
