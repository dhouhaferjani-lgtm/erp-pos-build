<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\ProductZoneAssignment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ZoneProductAssignmentDto extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public ?string $product_name,
        public ?string $product_sku,
        public string $location_id,
        public string $zone_id,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(ProductZoneAssignment $assignment): self
    {
        if (! $assignment->relationLoaded('product')) {
            $assignment->load('product');
        }

        return new self(
            id: $assignment->id,
            product_id: $assignment->product_id,
            product_name: $assignment->product->name ?? null,
            product_sku: $assignment->product->sku ?? null,
            location_id: $assignment->location_id,
            zone_id: $assignment->zone_id,
            created_at: $assignment->created_at?->toIso8601String() ?? '',
            updated_at: $assignment->updated_at?->toIso8601String() ?? '',
        );
    }
}
