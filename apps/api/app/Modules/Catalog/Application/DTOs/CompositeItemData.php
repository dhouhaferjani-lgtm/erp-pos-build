<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Catalog\Domain\Enums\PricingMode;
use App\Modules\Catalog\Domain\Enums\ProductionType;
use App\Modules\Catalog\Domain\Enums\VerticalType;
use App\Shared\Domain\CurrencyScale;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CompositeItemData extends Data
{
    /**
     * @param  array<int, CompositeItemVariantData>|null  $variants
     * @param  array<int, ModifierGroupData>|null  $modifier_groups
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public VerticalType $vertical_type,
        public string $base_price,
        public ProductionType $production_type,
        public PricingMode $pricing_mode,
        public ?string $tax_rate,
        public ?string $default_tax_configuration_id,
        public ?string $manual_cost,
        public ?string $effective_cost,
        public ?string $recipe_cost,
        public ?float $margin_percentage,
        public bool $is_active,
        public bool $is_available,
        public int|string|null $category_id,
        public ?string $category_name,
        public ?string $image_url,
        public int $display_order,
        public ?RecipeData $active_recipe,
        public ?array $variants,
        public ?array $modifier_groups,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(CompositeItem $item): self
    {
        $recipeCost = $item->activeRecipe?->calculated_cost;

        return new self(
            id: $item->id,
            code: $item->code,
            name: $item->name,
            vertical_type: $item->vertical_type,
            base_price: CurrencyScale::bcformat($item->base_price, 4),
            production_type: $item->production_type,
            pricing_mode: $item->pricing_mode ?? PricingMode::Standard,
            tax_rate: $item->tax_rate !== null ? (string) $item->tax_rate : null,
            default_tax_configuration_id: $item->default_tax_configuration_id,
            manual_cost: $item->manual_cost !== null ? (string) $item->manual_cost : null,
            effective_cost: $item->getEffectiveCost(),
            recipe_cost: $recipeCost !== null ? (string) $recipeCost : null,
            margin_percentage: $item->getMarginPercentage(),
            is_active: $item->is_active,
            is_available: $item->is_available,
            category_id: $item->category_id,
            category_name: $item->relationLoaded('category') && $item->category !== null
                ? $item->category->name
                : null,
            image_url: $item->image_url,
            display_order: $item->display_order,
            active_recipe: $item->relationLoaded('activeRecipe') && $item->activeRecipe !== null
                ? RecipeData::fromModel($item->activeRecipe)
                : null,
            variants: $item->relationLoaded('variants')
                ? $item->variants->map(fn ($v) => CompositeItemVariantData::fromModel($v))->all()
                : null,
            modifier_groups: $item->relationLoaded('modifierGroups')
                ? $item->modifierGroups->map(fn ($g) => ModifierGroupData::fromModel($g))->all()
                : null,
            created_at: $item->created_at?->toIso8601String() ?? '',
            updated_at: $item->updated_at?->toIso8601String(),
        );
    }
}
