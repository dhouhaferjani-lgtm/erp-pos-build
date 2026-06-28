<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\DTOs;

use App\Modules\Product\Domain\Certification;
use App\Modules\Product\Domain\Enums\AgeRestriction;
use App\Modules\Product\Domain\Enums\DosageForm;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\HealthClaim;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\KeyComponent;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\ProductSkinSuitability;
use App\Modules\Product\Domain\Routine;
use App\Shared\Domain\Enums\SkinType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
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
     * @param  array<int, SkinType>  $suitable_skin_types
     * @param  array<int, string>  $equivalent_product_ids
     * @param  array<int, string>  $complement_product_ids
     * @param  array<int, array{routine_id: string, step_order: int, step_label: string}>  $routine_refs
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
        public array $suitable_skin_types,
        public array $equivalent_product_ids,
        public array $complement_product_ids,
        public array $routine_refs,
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
        if (! $metadata->relationLoaded('skinSuitabilities')) {
            $metadata->load('skinSuitabilities');
        }
        if (! $metadata->relationLoaded('routines')) {
            $metadata->load('routines');
        }
        if (! $metadata->relationLoaded('equivalentProducts')) {
            $metadata->load('equivalentProducts');
        }
        if (! $metadata->relationLoaded('complementProducts')) {
            $metadata->load('complementProducts');
        }

        /** @var Collection<int, Ingredient>|null $ingredients */
        $ingredients = $metadata->ingredients;
        /** @var array<int, ProductIngredientData>|null $ingredientsList */
        $ingredientsList = $ingredients !== null && $ingredients->isNotEmpty()
            ? $ingredients->map(function (Model $model): ProductIngredientData {
                /** @var Ingredient $ingredient */
                $ingredient = $model;
                /** @var Pivot $pivot */
                $pivot = $ingredient->getRelation('pivot');

                return ProductIngredientData::fromPivot($ingredient, $pivot);
            })->values()->all()
            : null;

        /** @var Collection<int, KeyComponent>|null $keyComponents */
        $keyComponents = $metadata->keyComponents;
        /** @var array<int, ProductKeyComponentData>|null $keyComponentsList */
        $keyComponentsList = $keyComponents !== null && $keyComponents->isNotEmpty()
            ? $keyComponents->map(function (Model $model): ProductKeyComponentData {
                /** @var KeyComponent $component */
                $component = $model;
                /** @var Pivot $pivot */
                $pivot = $component->getRelation('pivot');

                return ProductKeyComponentData::fromPivot($component, $pivot);
            })->values()->all()
            : null;

        /** @var Collection<int, HealthClaim>|null $healthClaims */
        $healthClaims = $metadata->healthClaims;
        /** @var array<int, ProductHealthClaimData>|null $healthClaimsList */
        $healthClaimsList = $healthClaims !== null && $healthClaims->isNotEmpty()
            ? $healthClaims->map(function (Model $model): ProductHealthClaimData {
                /** @var HealthClaim $healthClaim */
                $healthClaim = $model;
                /** @var Pivot $pivot */
                $pivot = $healthClaim->getRelation('pivot');

                return ProductHealthClaimData::fromPivot($healthClaim, $pivot);
            })->values()->all()
            : null;

        /** @var Collection<int, Certification>|null $certifications */
        $certifications = $metadata->certifications;
        /** @var array<int, ProductCertificationData>|null $certificationsList */
        $certificationsList = $certifications !== null && $certifications->isNotEmpty()
            ? $certifications->map(function (Model $model): ProductCertificationData {
                /** @var Certification $certification */
                $certification = $model;
                /** @var Pivot $pivot */
                $pivot = $certification->getRelation('pivot');

                return ProductCertificationData::fromPivot($certification, $pivot);
            })->values()->all()
            : null;

        /** @var Collection<int, ProductSkinSuitability> $skinSuitabilities */
        $skinSuitabilities = $metadata->skinSuitabilities;
        /** @var array<int, SkinType> $suitableSkinTypes */
        $suitableSkinTypes = $skinSuitabilities->pluck('skin_type')->all();

        /** @var Collection<int, Product> $equivalentProducts */
        $equivalentProducts = $metadata->equivalentProducts;
        /** @var array<int, string> $equivalentProductIds */
        $equivalentProductIds = $equivalentProducts->pluck('id')->all();

        /** @var Collection<int, Product> $complementProducts */
        $complementProducts = $metadata->complementProducts;
        /** @var array<int, string> $complementProductIds */
        $complementProductIds = $complementProducts->pluck('id')->all();

        /** @var Collection<int, Routine> $routines */
        $routines = $metadata->routines;
        /** @var array<int, array{routine_id: string, step_order: int, step_label: string}> $routineRefs */
        $routineRefs = $routines->map(fn (Routine $r): array => [
            'routine_id' => $r->id,
            'step_order' => (int) $r->pivot->step_order,
            'step_label' => (string) $r->pivot->step_label,
        ])->all();

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
            suitable_skin_types: $suitableSkinTypes,
            equivalent_product_ids: $equivalentProductIds,
            complement_product_ids: $complementProductIds,
            routine_refs: $routineRefs,
        );
    }
}
