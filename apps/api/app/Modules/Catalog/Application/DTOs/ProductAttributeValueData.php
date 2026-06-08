<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductAttributeValueData extends Data
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $attribute_id,
        public string $code,
        public string $label,
        public ?string $hex_color,
        public ?string $image_url,
        public int $display_order,
    ) {}

    public static function fromModel(ProductAttributeValue $value): self
    {
        return new self(
            id: $value->id,
            tenant_id: $value->tenant_id,
            attribute_id: $value->attribute_id,
            code: $value->code,
            label: $value->label,
            hex_color: $value->hex_color,
            image_url: $value->image_url,
            display_order: $value->display_order,
        );
    }
}
