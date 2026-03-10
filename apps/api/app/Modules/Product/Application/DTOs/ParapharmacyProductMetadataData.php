<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ParapharmacyProductMetadataData extends Data
{
    /**
     * @param  array<int, ProductIngredientData>|null  $ingredients
     * @param  array<int, ProductKeyComponentData>|null  $key_components
     * @param  array<int, ProductHealthClaimData>|null  $health_claims
     * @param  array<int, ProductCertificationData>|null  $certifications
     */
    public function __construct(
        public string $id,
        public string $product_id,
        public ParapharmacyCategory $category,
        public ?DosageForm $dosage_form,
        public ?array $ingredients,
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
        // Load relationships with pivot data if not already loaded
        if (! $metadata->relationLoaded('ingredients')) {
            $metadata->load('ingredients');
        }
        if (! $metadata->relationLoaded('keyComponents')) {
            $metadata->load('keyComponents');
        }
        if (! $metadata->relationLoaded('healthClaims')) {
            $metadata->load('healthClaims');
        }
        if (! $metadata->relationLoaded('certifications')) {
            $metadata->load('certifications');
        }

        /** @var array<int, ProductIngredientData>|null $ingredientsList */
        $ingredientsList = $metadata->ingredients->isNotEmpty()
            ? $metadata->ingredients->map(function (\Illuminate\Database\Eloquent\Model $model): ProductIngredientData {
                /** @var \App\Modules\Product\Domain\Ingredient $ingredient */
                $ingredient = $model;
                /** @var Pivot $pivot */
                $pivot = $ingredient->getRelation('pivot');

                return ProductIngredientData::fromPivot($ingredient, $pivot);
            })->values()->all()
            : null;

        /** @var array<int, ProductKeyComponentData>|null $keyComponentsList */
        $keyComponentsList = $metadata->keyComponents->isNotEmpty()
            ? $metadata->keyComponents->map(function (\Illuminate\Database\Eloquent\Model $model): ProductKeyComponentData {
                /** @var \App\Modules\Product\Domain\KeyComponent $component */
                $component = $model;
                /** @var Pivot $pivot */
                $pivot = $component->getRelation('pivot');

                return ProductKeyComponentData::fromPivot($component, $pivot);
            })->values()->all()
            : null;

        /** @var array<int, ProductHealthClaimData>|null $healthClaimsList */
        $healthClaimsList = $metadata->healthClaims->isNotEmpty()
            ? $metadata->healthClaims->map(function (\Illuminate\Database\Eloquent\Model $model): ProductHealthClaimData {
                /** @var \App\Modules\Product\Domain\HealthClaim $healthClaim */
                $healthClaim = $model;
                /** @var Pivot $pivot */
                $pivot = $healthClaim->getRelation('pivot');

                return ProductHealthClaimData::fromPivot($healthClaim, $pivot);
            })->values()->all()
            : null;

        /** @var array<int, ProductCertificationData>|null $certificationsList */
        $certificationsList = $metadata->certifications->isNotEmpty()
            ? $metadata->certifications->map(function (\Illuminate\Database\Eloquent\Model $model): ProductCertificationData {
                /** @var \App\Modules\Product\Domain\Certification $certification */
                $certification = $model;
                /** @var Pivot $pivot */
                $pivot = $certification->getRelation('pivot');

                return ProductCertificationData::fromPivot($certification, $pivot);
            })->values()->all()
            : null;

        return new self(
            id: $metadata->id,
            product_id: $metadata->product_id,
            category: $metadata->category,
            dosage_form: $metadata->dosage_form,
            ingredients: $ingredientsList,
            key_components: $keyComponentsList,
            usage_instructions: $metadata->usage_instructions,
            warnings: $metadata->warnings,
            contraindications: $metadata->contraindications,
            minimum_age: $metadata->minimum_age,
            age_restriction: $metadata->age_restriction,
            requires_consultation: $metadata->requires_consultation,
            regulatory_code: $metadata->regulatory_code,
            health_claims: $healthClaimsList,
            certifications: $certificationsList,
            storage_requirements: $metadata->storage_requirements,
            created_at: $metadata->created_at?->toIso8601String() ?? '',
            updated_at: $metadata->updated_at?->toIso8601String(),
        );
    }
}
