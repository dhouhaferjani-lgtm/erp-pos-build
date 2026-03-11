<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTOs;

use App\Modules\Menu\Domain\Entities\Menu;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class MenuData extends Data
{
    /**
     * @param  array<int, MenuCategoryData>|null  $categories
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $is_default,
        public bool $is_active,
        public ?string $active_from,
        public ?string $active_until,
        public ?string $start_date,
        public ?string $end_date,
        /** @var array<int>|null */
        public ?array $available_days,
        public int $display_order,
        public ?array $categories,
        public int $categories_count,
        public int $items_count,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Menu $menu): self
    {
        $categoriesCount = 0;
        $itemsCount = 0;

        if ($menu->relationLoaded('categories')) {
            $categoriesCount = $menu->categories->count();
            foreach ($menu->categories as $category) {
                if ($category->relationLoaded('compositeItems')) {
                    $itemsCount += $category->compositeItems->count();
                }
                if ($category->relationLoaded('products')) {
                    $itemsCount += $category->products->count();
                }
            }
        }

        return new self(
            id: $menu->id,
            name: $menu->name,
            description: $menu->description,
            is_default: $menu->is_default,
            is_active: $menu->is_active,
            active_from: $menu->active_from,
            active_until: $menu->active_until,
            start_date: $menu->start_date,
            end_date: $menu->end_date,
            available_days: $menu->available_days,
            display_order: $menu->display_order,
            categories: $menu->relationLoaded('categories')
                ? $menu->categories->map(fn ($c) => MenuCategoryData::fromModel($c))->all()
                : null,
            categories_count: $categoriesCount,
            items_count: $itemsCount,
            created_at: $menu->created_at?->toIso8601String() ?? '',
            updated_at: $menu->updated_at?->toIso8601String(),
        );
    }
}
