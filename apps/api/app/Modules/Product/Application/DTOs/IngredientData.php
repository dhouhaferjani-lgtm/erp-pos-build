<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Ingredient;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class IngredientData extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
        public ?string $cas_number,
        public bool $is_allergen,
        public ?string $allergen_code,
        public ?string $regulatory_status,
        public ?string $notes,
        public string $name,
        public ?string $description,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(Ingredient $ingredient): self
    {
        return new self(
            id: $ingredient->id,
            slug: $ingredient->slug,
            cas_number: $ingredient->cas_number,
            is_allergen: $ingredient->is_allergen,
            allergen_code: $ingredient->allergen_code,
            regulatory_status: $ingredient->regulatory_status,
            notes: $ingredient->notes,
            name: $ingredient->name, // Uses HasTranslations trait accessor
            description: $ingredient->description, // Uses HasTranslations trait accessor
            created_at: $ingredient->created_at?->toIso8601String() ?? '',
            updated_at: $ingredient->updated_at?->toIso8601String(),
        );
    }
}
