<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\ProductPlacement;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProductPlacementDto extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public ?string $product_name,
        public ?string $product_sku,
        public string $location_id,
        public string $node_id,
        public ?string $deleted_at,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(ProductPlacement $placement): self
    {
        if (! $placement->relationLoaded('product')) {
            $placement->load('product');
        }

        return new self(
            id: $placement->id,
            product_id: $placement->product_id,
            product_name: $placement->product->name ?? null,
            product_sku: $placement->product->sku ?? null,
            location_id: $placement->location_id,
            node_id: $placement->node_id,
            deleted_at: $placement->deleted_at?->toIso8601String(),
            created_at: $placement->created_at?->toIso8601String() ?? '',
            updated_at: $placement->updated_at?->toIso8601String() ?? '',
        );
    }
}
