<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTOs;

use App\Modules\Menu\Domain\Entities\MenuCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuCategoryData extends Data
{
    /**
     * @param  array<int, MenuItemData>|null  $items
     */
    public function __construct(
        public string $id,
        public string $menu_id,
        public string $name,
        public ?string $description,
        public ?string $icon,
        public int $display_order,
        public bool $is_active,
        public ?array $items,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(MenuCategory $category): self
    {
        $items = collect();

        if ($category->relationLoaded('compositeItems')) {
            $items = $items->merge(
                $category->compositeItems->map(fn ($ci) => MenuItemData::fromCompositeItemPivot($ci))
            );
        }

        if ($category->relationLoaded('products')) {
            $items = $items->merge(
                $category->products->map(fn ($p) => MenuItemData::fromProductPivot($p))
            );
        }

        $hasItems = $category->relationLoaded('compositeItems') || $category->relationLoaded('products');

        return new self(
            id: $category->id,
            menu_id: $category->menu_id,
            name: $category->name,
            description: $category->description,
            icon: $category->icon,
            display_order: $category->display_order,
            is_active: $category->is_active,
            items: $hasItems
                ? $items->sortBy('display_order')->values()->all()
                : null,
            created_at: $category->created_at?->toIso8601String() ?? '',
            updated_at: $category->updated_at?->toIso8601String(),
        );
    }
}
