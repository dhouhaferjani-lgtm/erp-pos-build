<?php

declare(strict_types=1);

namespace App\Modules\Service\Application\DTOs;

use App\Modules\Service\Domain\ServiceCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ServiceCategoryData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public ?string $parent_id,
        public int $sort_order,
        public bool $is_active,
        public ?int $services_count,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(ServiceCategory $category): self
    {
        return new self(
            id: $category->id,
            name: $category->name,
            description: $category->description,
            parent_id: $category->parent_id,
            sort_order: $category->sort_order,
            is_active: $category->is_active,
            services_count: $category->services_count ?? null,
            created_at: $category->created_at?->toIso8601String() ?? '',
            updated_at: $category->updated_at?->toIso8601String(),
        );
    }
}
