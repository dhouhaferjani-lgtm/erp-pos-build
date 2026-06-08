<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\ProductAttribute;
use App\Modules\Catalog\Domain\Enums\AttributeDataType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductAttributeData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $code,
        public string $name,
        public AttributeDataType $data_type,
        public bool $is_variant_axis,
        public int $display_order,
        public bool $is_active,
    ) {}

    public static function fromModel(ProductAttribute $attribute): self
    {
        return new self(
            id: $attribute->id,
            tenant_id: $attribute->tenant_id,
            code: $attribute->code,
            name: $attribute->name,
            data_type: $attribute->data_type,
            is_variant_axis: $attribute->is_variant_axis,
            display_order: $attribute->display_order,
            is_active: $attribute->is_active,
        );
    }
}
