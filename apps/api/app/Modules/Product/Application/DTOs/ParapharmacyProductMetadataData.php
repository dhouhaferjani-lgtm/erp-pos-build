<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ParapharmacyProductMetadataData extends Data
{
    public function __construct(
        public string $id,
        public string $product_id,
        public ParapharmacyCategory $category,
        public ?DosageForm $dosage_form,
        #[DataCollectionOf(ProductIngredientData::class)]
        public ?DataCollection $ingredients,
        #[DataCollectionOf(ProductKeyComponentData::class)]
        public ?DataCollection $key_components,
        public ?string $usage_instructions,
        public ?string $warnings,
        public ?string $contraindications,
        public ?int $minimum_age,
        public ?AgeRestriction $age_restriction,
        public bool $requires_consultation,
        public ?string $regulatory_code,
        #[DataCollectionOf(ProductHealthClaimData::class)]
        public ?DataCollection $health_claims,
        #[DataCollectionOf(ProductCertificationData::class)]
        public ?DataCollection $certifications,
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

        return new self(
            id: $metadata->id,
            product_id: $metadata->product_id,
            category: $metadata->category,
            dosage_form: $metadata->dosage_form,
            ingredients: $metadata->ingredients->isNotEmpty()
                ? ProductIngredientData::collection(
                    $metadata->ingredients->map(function ($ingredient) {
                        return ProductIngredientData::fromPivot($ingredient, $ingredient->pivot);
                    })
                )
                : null,
            key_components: $metadata->keyComponents->isNotEmpty()
                ? ProductKeyComponentData::collection(
                    $metadata->keyComponents->map(function ($component) {
                        return ProductKeyComponentData::fromPivot($component, $component->pivot);
                    })
                )
                : null,
            usage_instructions: $metadata->usage_instructions,
            warnings: $metadata->warnings,
            contraindications: $metadata->contraindications,
            minimum_age: $metadata->minimum_age,
            age_restriction: $metadata->age_restriction,
            requires_consultation: $metadata->requires_consultation,
            regulatory_code: $metadata->regulatory_code,
            health_claims: $metadata->healthClaims->isNotEmpty()
                ? ProductHealthClaimData::collection(
                    $metadata->healthClaims->map(function ($healthClaim) {
                        return ProductHealthClaimData::fromPivot($healthClaim, $healthClaim->pivot);
                    })
                )
                : null,
            certifications: $metadata->certifications->isNotEmpty()
                ? ProductCertificationData::collection(
                    $metadata->certifications->map(function ($certification) {
                        return ProductCertificationData::fromPivot($certification, $certification->pivot);
                    })
                )
                : null,
            storage_requirements: $metadata->storage_requirements,
            created_at: $metadata->created_at?->toIso8601String() ?? '',
            updated_at: $metadata->updated_at?->toIso8601String(),
        );
    }
}
