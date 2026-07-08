<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Enums\Vertical;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Enums\ParapharmacyCategory;
use App\Modules\Product\Domain\Ingredient;
use App\Modules\Product\Domain\IngredientTranslation;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Shared\DTOs\CatalogProductDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CatalogEnrichmentService
{
    public function applyCatalogHit(Product $product, CatalogProductDTO $catalog): void
    {
        $attempt = function () use ($product, $catalog): void {
            DB::transaction(function () use ($product, $catalog): void {
                // Row lock serializes concurrent redeliveries of the same job so
                // the check-then-insert idempotency guard below cannot race.
                $locked = Product::query()->lockForUpdate()->find($product->id);
                if ($locked === null) {
                    return;
                }
                $product = $locked;

                // Queue delivery is at-least-once: a re-delivered job must not
                // mint a second accepted result row for the same catalog hit.
                $alreadyApplied = EnrichmentResult::query()
                    ->where('product_id', $product->id)
                    ->where('enrichment_quality', 'catalog')
                    ->where('status', EnrichmentReviewStatus::Accepted)
                    ->exists();

                if ($alreadyApplied) {
                    return;
                }

                // The form already carried the catalog suggestion at create time;
                // anything the user edited before saving wins. Name and barcode are
                // therefore never overwritten here — the apply only ADDS what the
                // create flow could not: brand, ingredients, a missing description,
                // the audit row, and the completed status.
                $productUpdates = [
                    'enrichment_status' => EnrichmentStatus::Completed,
                ];

                $acceptedFields = [];

                $hasOwnDescription = $product->description !== null && trim($product->description) !== '';
                if (! $hasOwnDescription && filled($catalog->description)) {
                    $productUpdates['description'] = $catalog->description;
                    $acceptedFields['description'] = true;
                }

                if ($product->brand_id === null) {
                    // Prefer the brand already resolved by the lookup's platform
                    // brand mapping — never mint a duplicate when it resolved one.
                    $resolvedBrand = $catalog->localBrandId !== null
                        ? Brand::query()->where('tenant_id', $product->tenant_id)->find($catalog->localBrandId)
                        : null;

                    if ($resolvedBrand !== null) {
                        $productUpdates['brand_id'] = $resolvedBrand->id;
                        $productUpdates['brand_source'] = BrandSource::Enriched;
                        $acceptedFields['brand'] = true;
                    } elseif (filled($catalog->brand)) {
                        // Legacy payloads (no resolved id) — or the resolved brand
                        // was deleted between lookup and apply.
                        $slug = Brand::slugFor($catalog->brand);
                        $brand = Brand::firstOrCreate(
                            ['tenant_id' => $product->tenant_id, 'slug' => $slug],
                            ['name' => $catalog->brand, 'is_active' => true],
                        );

                        $productUpdates['brand_id'] = $brand->id;
                        $productUpdates['brand_source'] = BrandSource::Enriched;
                        $acceptedFields['brand'] = true;
                    }
                }

                $ingredientsApplied = $this->applyIngredientsIfParapharmacy($product, $catalog);
                if ($ingredientsApplied) {
                    $acceptedFields['ingredients'] = true;
                }

                $product->update($productUpdates);

                EnrichmentResult::create([
                    'tenant_id' => $product->tenant_id,
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'tracking_id' => null,
                    'status' => EnrichmentReviewStatus::Accepted,
                    'enriched_data' => new EnrichedProductData(
                        name: $catalog->name,
                        brand: $catalog->brand,
                        description: $catalog->description,
                        classification: $catalog->classification,
                        ingredients: $catalog->ingredients,
                        images: $catalog->images,
                        confidence_score: $catalog->confidenceScore,
                        enrichment_tier: $catalog->enrichmentTier,
                        field_confidence: null,
                        enrichment_sources: null,
                        assigned_barcode: $catalog->barcode,
                        assigned_barcode_type: null,
                        canonical_brand_id: $catalog->canonicalBrandId,
                        canonical_brand_slug: $catalog->canonicalBrandSlug,
                        external_brand_id: $catalog->externalBrandId,
                    ),
                    'enrichment_quality' => 'catalog',
                    'assigned_barcode' => $catalog->barcode,
                    'reviewed_at' => now(),
                    'reviewed_by' => null,
                    'accepted_fields' => $acceptedFields,
                ]);

                $normalizedImages = ImageDescriptorNormalizer::normalize($catalog->images);
                if ($normalizedImages !== []) {
                    PersistEnrichmentImagesJob::dispatch(
                        $product->tenant_id,
                        $product->id,
                        $normalizedImages,
                    )->afterCommit();
                }
            });
        };

        try {
            $attempt();
        } catch (QueryException $e) {
            if (! str_contains($e->getMessage(), '23505') && ! str_contains(strtolower($e->getMessage()), 'unique')) {
                throw $e;
            }

            $attempt();
        }
    }

    private function applyIngredientsIfParapharmacy(Product $product, CatalogProductDTO $catalog): bool
    {
        if ($product->tenant->vertical !== Vertical::Parapharmacy || $catalog->ingredients === []) {
            return false;
        }

        $metadata = ParapharmacyProductMetadata::firstOrCreate(
            ['product_id' => $product->id],
            ['category' => $this->categoryFromClassification($catalog)->value],
        );

        $attachedIngredientIds = DB::table('product_ingredient')
            ->where('product_id', $metadata->product_id)
            ->pluck('ingredient_id')
            ->map(static fn (string $id): string => $id)
            ->all();

        $applied = false;
        foreach ($catalog->ingredients as $ingredientData) {
            $name = trim($ingredientData['name']);
            if ($name === '') {
                continue;
            }

            $ingredient = $this->firstOrCreateIngredient($name);

            if (in_array($ingredient->id, $attachedIngredientIds, true)) {
                continue;
            }

            $metadata->ingredients()->syncWithoutDetaching([
                $ingredient->id => [
                    'id' => (string) Str::uuid(),
                    'order' => $ingredientData['position'],
                ],
            ]);
            $attachedIngredientIds[] = $ingredient->id;
            $applied = true;
        }

        return $applied;
    }

    private function categoryFromClassification(CatalogProductDTO $catalog): ParapharmacyCategory
    {
        $category = $catalog->classification['category'] ?? null;

        if (! is_string($category)) {
            return ParapharmacyCategory::Other;
        }

        return ParapharmacyCategory::tryFrom($category) ?? ParapharmacyCategory::Other;
    }

    private function firstOrCreateIngredient(string $name): Ingredient
    {
        $slug = Str::slug($name);
        $ingredient = Ingredient::firstOrCreate(
            ['slug' => $slug],
            [
                'is_allergen' => false,
                'regulatory_status' => null,
            ],
        );

        if ($ingredient->wasRecentlyCreated) {
            IngredientTranslation::create([
                'ingredient_id' => $ingredient->id,
                'locale' => config('app.locale'),
                'name' => $name,
                'description' => null,
            ]);
        }

        return $ingredient;
    }
}
