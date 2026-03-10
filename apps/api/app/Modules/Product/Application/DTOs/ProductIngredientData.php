<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Ingredient;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ProductIngredientData extends Data
{
    public function __construct(
        public IngredientData $ingredient,
        public ?string $concentration,
        public ?float $concentration_numeric,
        public ?string $concentration_unit,
        public int $order,
        public ?string $notes,
    ) {}

    public static function fromPivot(Ingredient $ingredient, Pivot $pivot): self
    {
        return new self(
            ingredient: IngredientData::fromModel($ingredient),
            concentration: $pivot->getAttribute('concentration'),
            concentration_numeric: $pivot->getAttribute('concentration_numeric') !== null
                ? (float) $pivot->getAttribute('concentration_numeric')
                : null,
            concentration_unit: $pivot->getAttribute('concentration_unit'),
            order: $pivot->getAttribute('order') ?? 0,
            notes: $pivot->getAttribute('notes'),
        );
    }
}
