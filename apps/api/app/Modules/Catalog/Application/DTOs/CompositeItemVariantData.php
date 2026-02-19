<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\CompositeItemVariant;
use App\Modules\Catalog\Domain\Enums\PriceAdjustmentType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CompositeItemVariantData extends Data
{
    public function __construct(
        public string $id,
        public string $composite_item_id,
        public string $code,
        public string $name,
        public PriceAdjustmentType $price_adjustment_type,
        public string $price_adjustment,
        public string $recipe_multiplier,
        public bool $is_default,
        public bool $is_active,
        public int $display_order,
    ) {}

    public static function fromModel(CompositeItemVariant $variant): self
    {
        return new self(
            id: $variant->id,
            composite_item_id: $variant->composite_item_id,
            code: $variant->code,
            name: $variant->name,
            price_adjustment_type: $variant->price_adjustment_type,
            price_adjustment: (string) $variant->price_adjustment,
            recipe_multiplier: (string) $variant->recipe_multiplier,
            is_default: $variant->is_default,
            is_active: $variant->is_active,
            display_order: $variant->display_order,
        );
    }
}
