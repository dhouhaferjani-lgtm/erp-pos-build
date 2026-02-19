<?php

declare(strict_types=1);

namespace App\Modules\Uom\Application\DTOs;

use App\Modules\Uom\Domain\Entities\Unit;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class UnitData extends Data
{
    public function __construct(
        public string $id,
        public string $categoryId,
        public string $code,
        public string $name,
        public string $symbol,
        public string $conversionFactor,
        public int $decimalPlaces,
        public string $roundingMethod,
        public bool $isBaseUnit,
        public bool $isSystem,
        public bool $isActive,
        public ?UnitCategoryData $category = null,
    ) {}

    public static function fromModel(Unit $unit): self
    {
        return new self(
            id: $unit->id,
            categoryId: $unit->category_id,
            code: $unit->code,
            name: $unit->name,
            symbol: $unit->symbol,
            conversionFactor: $unit->conversion_factor,
            decimalPlaces: $unit->decimal_places,
            roundingMethod: $unit->rounding_method->value,
            isBaseUnit: $unit->is_base_unit,
            isSystem: $unit->is_system,
            isActive: $unit->is_active,
            category: $unit->relationLoaded('category')
                ? UnitCategoryData::fromModel($unit->category)
                : null,
        );
    }
}
