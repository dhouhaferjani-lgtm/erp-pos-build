<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Category;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CategoryData extends Data
{
    /**
     * @param  array<int, array{id: int, name: string, slug: string}>|null  $breadcrumb
     * @param  array<int, CategoryData>|null  $children
     */
    public function __construct(
        public int $id,
        public string $company_id,
        public ?int $parent_id,
        public string $name,
        public string $slug,
        public ?string $description,
        public ?string $image_url,
        public string $path,
        public int $depth,
        public int $sort_order,
        public bool $is_active,
        public ?int $products_count,
        public ?array $breadcrumb,
        public ?array $children,
        public ?string $default_tax_rate = null,
        public ?string $default_tax_configuration_id = null,
        public ?string $max_discount_percent = null,
    ) {}

    public static function fromModel(Category $category, bool $includeChildren = false): self
    {
        return new self(
            id: $category->id,
            company_id: $category->company_id,
            parent_id: $category->parent_id,
            name: $category->name,
            slug: $category->slug,
            description: $category->description,
            image_url: $category->image_path ? asset('storage/'.$category->image_path) : null,
            path: $category->path,
            depth: $category->depth,
            sort_order: $category->sort_order,
            is_active: $category->is_active,
            products_count: $category->products_count ?? null,
            breadcrumb: $category->getBreadcrumb(),
            children: $includeChildren
                ? $category->children->map(fn ($c) => self::fromModel($c, true))->all()
                : null,
            default_tax_rate: $category->default_tax_rate,
            default_tax_configuration_id: $category->default_tax_configuration_id,
            max_discount_percent: $category->max_discount_percent !== null ? (string) $category->max_discount_percent : null,
        );
    }
}
