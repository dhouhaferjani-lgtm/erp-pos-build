<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Domain\Entities;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductAttributeTest extends TestCase
{
    public function test_attribute_can_be_constructed_with_required_fields(): void
    {
        $attribute = new ProductAttribute([
            'tenant_id' => (string) Str::uuid(),
            'code' => 'color',
            'name' => 'Color',
            'data_type' => AttributeDataType::Color,
        ]);

        $this->assertSame('color', $attribute->code);
        $this->assertSame('Color', $attribute->name);
        $this->assertSame(AttributeDataType::Color, $attribute->data_type);
    }

    public function test_attribute_default_is_not_variant_axis(): void
    {
        $attribute = new ProductAttribute([
            'tenant_id' => (string) Str::uuid(),
            'code' => 'size',
            'name' => 'Size',
            'data_type' => AttributeDataType::Selection,
        ]);

        $this->assertFalse($attribute->is_variant_axis);
    }
}
