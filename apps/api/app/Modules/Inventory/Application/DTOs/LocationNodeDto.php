<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\LocationNodeType;
use App\Modules\Inventory\Domain\LocationNode;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class LocationNodeDto extends Data
{
    public function __construct(
        public string $id,
        public string $location_id,
        public ?string $parent_id,
        public LocationNodeType $node_type,
        public string $name,
        public string $code,
        public string $path,
        public int $depth,
        public int $sort_order,
        public bool $is_active,
        public int $product_count,
        public ?string $deleted_at,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(LocationNode $node): self
    {
        return new self(
            id: $node->id,
            location_id: $node->location_id,
            parent_id: $node->parent_id,
            node_type: $node->node_type,
            name: $node->name,
            code: $node->code,
            path: $node->path,
            depth: $node->depth,
            sort_order: $node->sort_order,
            is_active: $node->is_active,
            product_count: (int) ($node->product_placements_count ?? $node->productPlacements()->count()),
            deleted_at: $node->deleted_at?->toIso8601String(),
            created_at: $node->created_at?->toIso8601String() ?? '',
            updated_at: $node->updated_at?->toIso8601String() ?? '',
        );
    }
}
