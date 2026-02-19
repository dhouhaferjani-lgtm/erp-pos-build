<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use App\Modules\Catalog\Domain\Entities\Recipe;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RecipeData extends Data
{
    /**
     * @param  array<int, RecipeLineData>|null  $lines
     */
    public function __construct(
        public string $id,
        public string $composite_item_id,
        public int $version,
        public ?string $version_name,
        public bool $is_active,
        public string $yield_quantity,
        public ?string $yield_unit_id,
        public ?string $calculated_cost,
        public ?int $prep_time_minutes,
        public ?int $cook_time_minutes,
        public ?int $total_time_minutes,
        public ?string $instructions,
        public ?array $lines,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Recipe $recipe): self
    {
        return new self(
            id: $recipe->id,
            composite_item_id: $recipe->composite_item_id,
            version: $recipe->version,
            version_name: $recipe->version_name,
            is_active: $recipe->is_active,
            yield_quantity: (string) $recipe->yield_quantity,
            yield_unit_id: $recipe->yield_unit_id,
            calculated_cost: $recipe->calculated_cost !== null ? (string) $recipe->calculated_cost : null,
            prep_time_minutes: $recipe->prep_time_minutes,
            cook_time_minutes: $recipe->cook_time_minutes,
            total_time_minutes: $recipe->total_time_minutes,
            instructions: $recipe->instructions,
            lines: $recipe->relationLoaded('lines')
                ? $recipe->lines->map(fn ($l) => RecipeLineData::fromModel($l))->all()
                : null,
            created_at: $recipe->created_at?->toIso8601String() ?? '',
            updated_at: $recipe->updated_at?->toIso8601String(),
        );
    }
}
