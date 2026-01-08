<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ParapharmacyProductMetadataData extends Data
{
    /**
     * @param  array<int, array{name: string, concentration?: string}>|null  $active_ingredients
     * @param  array<int, string>|null  $key_components
     * @param  array<int, string>|null  $health_claims
     * @param  array<int, array{type: string, code: string}>|null  $certifications
     */
    public function __construct(
        public string $id,
        public string $product_id,
        public ParapharmacyCategory $category,
        public ?DosageForm $dosage_form,
        public ?array $active_ingredients,
        public ?array $key_components,
        public ?string $usage_instructions,
        public ?string $warnings,
        public ?string $contraindications,
        public ?int $minimum_age,
        public ?AgeRestriction $age_restriction,
        public bool $requires_consultation,
        public ?string $regulatory_code,
        public ?array $health_claims,
        public ?array $certifications,
        public ?string $storage_requirements,
        public string $created_at,
        public ?string $updated_at,
    ) {}

    public static function fromModel(ParapharmacyProductMetadata $metadata): self
    {
        return new self(
            id: $metadata->id,
            product_id: $metadata->product_id,
            category: $metadata->category,
            dosage_form: $metadata->dosage_form,
            active_ingredients: $metadata->active_ingredients,
            key_components: $metadata->key_components,
            usage_instructions: $metadata->usage_instructions,
            warnings: $metadata->warnings,
            contraindications: $metadata->contraindications,
            minimum_age: $metadata->minimum_age,
            age_restriction: $metadata->age_restriction,
            requires_consultation: $metadata->requires_consultation,
            regulatory_code: $metadata->regulatory_code,
            health_claims: $metadata->health_claims,
            certifications: $metadata->certifications,
            storage_requirements: $metadata->storage_requirements,
            created_at: $metadata->created_at?->toIso8601String() ?? '',
            updated_at: $metadata->updated_at?->toIso8601String(),
        );
    }
}
