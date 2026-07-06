<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\LocationZone;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ZoneDto extends Data
{
    public function __construct(
        public string $id,
        public string $location_id,
        public string $name,
        public string $code,
        public int $sort_order,
        public bool $is_active,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(LocationZone $zone): self
    {
        return new self(
            id: $zone->id,
            location_id: $zone->location_id,
            name: $zone->name,
            code: $zone->code,
            sort_order: $zone->sort_order,
            is_active: $zone->is_active,
            created_at: $zone->created_at?->toIso8601String() ?? '',
            updated_at: $zone->updated_at?->toIso8601String() ?? '',
        );
    }
}
