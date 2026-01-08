<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\KeyComponent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class KeyComponentData extends Data
{
    public function __construct(
        public string $id,
        public string $slug,
        public bool $is_allergen,
        public string $name,
        public ?string $description,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(KeyComponent $keyComponent): self
    {
        return new self(
            id: $keyComponent->id,
            slug: $keyComponent->slug,
            is_allergen: $keyComponent->is_allergen,
            name: $keyComponent->name, // Uses HasTranslations trait accessor
            description: $keyComponent->description, // Uses HasTranslations trait accessor
            created_at: $keyComponent->created_at?->toIso8601String() ?? '',
            updated_at: $keyComponent->updated_at?->toIso8601String(),
        );
    }
}
