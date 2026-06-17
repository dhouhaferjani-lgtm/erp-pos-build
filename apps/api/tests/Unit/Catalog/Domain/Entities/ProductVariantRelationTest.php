<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use Database\Factories\Catalog\ProductAttributeFactory;
use Database\Factories\Catalog\ProductAttributeValueFactory;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductVariantRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attribute_values_relation_returns_junction_rows(): void
    {
        $variant = ProductVariantFactory::new()->create();

        $attr1 = ProductAttributeFactory::new()->create(['tenant_id' => $variant->tenant_id]);
        $val1 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
            'attribute_id' => $attr1->id,
        ]);

        $attr2 = ProductAttributeFactory::new()->create(['tenant_id' => $variant->tenant_id]);
        $val2 = ProductAttributeValueFactory::new()->create([
            'tenant_id' => $variant->tenant_id,
            'attribute_id' => $attr2->id,
        ]);

        ProductVariantAttributeValue::create([
            'variant_id' => $variant->id,
            'attribute_id' => $attr1->id,
            'attribute_value_id' => $val1->id,
        ]);
        ProductVariantAttributeValue::create([
            'variant_id' => $variant->id,
            'attribute_id' => $attr2->id,
            'attribute_value_id' => $val2->id,
        ]);

        $this->assertCount(2, $variant->attributeValues);
        $this->assertSame($attr1->id, $variant->attributeValues->first()->attribute_id);
    }
}
