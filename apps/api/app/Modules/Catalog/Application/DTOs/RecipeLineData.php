<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\RecipeLine;
use App\Modules\Catalog\Domain\Enums\ComponentType;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RecipeLineData extends Data
{
    public function __construct(
        public string $id,
        public string $recipe_id,
        public ComponentType $component_type,
        public string $component_id,
        public ?string $component_name,
        public ?string $component_sku,
        public string $quantity,
        public ?string $unit_id,
        public ?string $unit_name,
        public bool $is_optional,
        public bool $is_scalable,
        public string $wastage_percent,
        public ?string $unit_cost,
        public ?string $line_cost,
        public int $display_order,
    ) {}

    public static function fromModel(RecipeLine $line): self
    {
        $componentName = null;
        $componentSku = null;

        if ($line->component_type === ComponentType::CompositeItem) {
            if ($line->relationLoaded('compositeItemComponent') && $line->compositeItemComponent !== null) {
                $componentName = $line->compositeItemComponent->name;
                $componentSku = $line->compositeItemComponent->code;
            }
        } else {
            if ($line->relationLoaded('product') && $line->product !== null) {
                $componentName = $line->product->name;
                $componentSku = $line->product->sku;
            }
        }

        return new self(
            id: $line->id,
            recipe_id: $line->recipe_id,
            component_type: $line->component_type,
            component_id: $line->component_id,
            component_name: $componentName,
            component_sku: $componentSku,
            quantity: CurrencyScale::bcformat($line->quantity, 4),
            unit_id: $line->unit_id,
            unit_name: $line->relationLoaded('unit') && $line->unit !== null
                ? $line->unit->name
                : null,
            is_optional: $line->is_optional,
            is_scalable: $line->is_scalable,
            wastage_percent: CurrencyScale::bcformat($line->wastage_percent, 2),
            unit_cost: $line->unit_cost !== null ? (string) $line->unit_cost : null,
            line_cost: $line->line_cost !== null ? (string) $line->line_cost : null,
            display_order: $line->display_order,
        );
    }
}
