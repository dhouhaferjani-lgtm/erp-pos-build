<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\CompositeItem;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuItemData extends Data
{
    public function __construct(
        public string $id,
        public string $composite_item_id,
        public string $name,
        public string $code,
        public string $base_price,
        public ?string $override_price,
        public string $effective_price,
        public int $display_order,
        public bool $is_available,
        public ?string $image_url,
    ) {}

    public static function fromPivot(CompositeItem $item): self
    {
        $overridePrice = $item->pivot->override_price ?? null;
        $basePrice = (string) $item->base_price;
        $effectivePrice = $overridePrice !== null ? (string) $overridePrice : $basePrice;

        return new self(
            id: $item->pivot->id,
            composite_item_id: $item->id,
            name: $item->name,
            code: $item->code,
            base_price: number_format((float) $basePrice, 4, '.', ''),
            override_price: $overridePrice !== null ? number_format((float) $overridePrice, 4, '.', '') : null,
            effective_price: number_format((float) $effectivePrice, 4, '.', ''),
            display_order: (int) ($item->pivot->display_order ?? 0),
            is_available: (bool) ($item->pivot->is_available ?? true),
            image_url: $item->image_url,
        );
    }
}
