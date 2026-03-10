<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTOs;

use App\Modules\Catalog\Application\DTOs\ModifierGroupData;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemData extends Data
{
    /**
     * @param  array<int, ModifierGroupData>|null  $modifier_groups
     */
    public function __construct(
        public string $id,
        public string $composite_item_id,
        public string $name,
        public string $code,
        public string $base_price,
        public ?string $override_price,
        public string $effective_price,
        public ?string $tax_rate,
        public int $display_order,
        public bool $is_available,
        public ?string $image_url,
        public ?array $modifier_groups,
    ) {}

    public static function fromPivot(CompositeItem $item): self
    {
        /** @var \Illuminate\Database\Eloquent\Relations\Pivot|null $pivot */
        $pivot = $item->getAttribute('pivot');
        $overridePrice = $pivot?->getAttribute('override_price') ?? null;
        $basePrice = (string) $item->base_price;
        $effectivePrice = $overridePrice !== null ? (string) $overridePrice : $basePrice;

        return new self(
            id: $pivot?->getAttribute('id') ?? '',
            composite_item_id: $item->id,
            name: $item->name,
            code: $item->code,
            base_price: number_format((float) $basePrice, 4, '.', ''),
            override_price: $overridePrice !== null ? number_format((float) $overridePrice, 4, '.', '') : null,
            effective_price: number_format((float) $effectivePrice, 4, '.', ''),
            tax_rate: $item->tax_rate !== null ? (string) $item->tax_rate : null,
            display_order: (int) ($pivot?->getAttribute('display_order') ?? 0),
            is_available: (bool) ($pivot?->getAttribute('is_available') ?? true),
            image_url: $item->image_url,
            modifier_groups: $item->relationLoaded('modifierGroups')
                ? $item->modifierGroups
                    ->where('is_active', true)
                    ->map(fn ($g) => ModifierGroupData::fromModel($g))
                    ->values()
                    ->all()
                : null,
        );
    }
}
