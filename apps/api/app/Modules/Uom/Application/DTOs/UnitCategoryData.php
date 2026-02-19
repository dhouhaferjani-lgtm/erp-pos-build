<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\DTOs;

use App\Modules\Uom\Domain\Entities\UnitCategory;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UnitCategoryData extends Data
{
    /**
     * @param  array<UnitData>  $units
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $baseUnitId,
        public bool $isSystem,
        public bool $isActive,
        public array $units = [],
    ) {}

    public static function fromModel(UnitCategory $category): self
    {
        // Check if units relationship is loaded
        $units = $category->relationLoaded('units')
            ? $category->units->map(fn ($unit) => UnitData::fromModel($unit))->all()
            : [];

        return new self(
            id: $category->id,
            code: $category->code,
            name: $category->name,
            description: $category->description,
            baseUnitId: $category->base_unit_id,
            isSystem: $category->is_system,
            isActive: $category->is_active,
            units: $units,
        );
    }
}
