<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application\DTOs;

use App\Modules\Menu\Domain\Entities\Menu;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ActiveMenuData extends Data
{
    /**
     * @param  array<int, MenuCategoryData>  $categories
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public bool $is_default,
        public array $categories,
    ) {}

    public static function fromModel(Menu $menu): self
    {
        $menu->loadMissing(['categories' => function ($query): void {
            $query->where('is_active', true)->orderBy('display_order');
        }, 'categories.compositeItems' => function ($query): void {
            $query->withoutTrashed()
                ->wherePivot('is_available', true)
                ->orderByPivot('display_order');
        }, 'categories.compositeItems.modifierGroups' => function ($query): void {
            $query->where('is_active', true)->orderByPivot('display_order');
        }, 'categories.compositeItems.modifierGroups.modifiers' => function ($query): void {
            $query->where('is_active', true)->orderBy('display_order');
        }, 'categories.products' => function ($query): void {
            $query->withoutTrashed()
                ->wherePivot('is_available', true)
                ->orderByPivot('display_order');
        }]);

        return new self(
            id: $menu->id,
            name: $menu->name,
            description: $menu->description,
            is_default: $menu->is_default,
            categories: $menu->categories
                ->map(fn ($c) => MenuCategoryData::fromModel($c))
                ->all(),
        );
    }
}
