<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\Brand;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class EnrichmentReviewService
{
    public function __construct(
        private readonly PlatformSubmissionInterface $submissionService,
    ) {}

    /**
     * Fetch enrichment status from the platform and store the result locally.
     *
     * @param  string|null  $webhookLocale  Locale carried on the webhook push;
     *                                      authoritative at approval time and
     *                                      preferred over the lookup-status echo.
     */
    public function fetchAndStore(string $trackingId, Product $product, ?string $webhookLocale = null): ?EnrichmentResult
    {
        $statusDTO = $this->submissionService->checkStatus($trackingId);

        if ($statusDTO === null) {
            return null;
        }

        $enrichedData = $statusDTO->enrichedData;

        return EnrichmentResult::updateOrCreate(
            ['tracking_id' => $trackingId],
            [
                'tenant_id' => $product->tenant_id,
                'company_id' => $product->company_id,
                'product_id' => $product->id,
                'status' => EnrichmentReviewStatus::PendingReview,
                'enriched_data' => new EnrichedProductData(
                    name: $enrichedData['name'] ?? $product->name,
                    brand: $enrichedData['brand'] ?? null,
                    description: $enrichedData['description'] ?? null,
                    classification: $enrichedData['classification'] ?? [],
                    ingredients: $enrichedData['ingredients'] ?? [],
                    images: $enrichedData['images'] ?? [],
                    confidence_score: (int) ($enrichedData['confidence_score'] ?? 0),
                    enrichment_tier: $enrichedData['enrichment_tier'] ?? null,
                    field_confidence: $enrichedData['field_confidence'] ?? null,
                    enrichment_sources: $enrichedData['enrichment_sources'] ?? null,
                    assigned_barcode: $enrichedData['assigned_barcode'] ?? null,
                    assigned_barcode_type: $enrichedData['assigned_barcode_type'] ?? null,
                    locale: $webhookLocale ?? $statusDTO->locale ?? ($enrichedData['locale'] ?? null),
                ),
                'enrichment_quality' => $statusDTO->enrichmentQuality ?? 'unknown',
                'assigned_barcode' => $statusDTO->assignedBarcode,
            ],
        );
    }

    /**
     * Accept enrichment results and merge accepted fields into the product.
     *
     * @param  array<int, string>  $acceptedFields  List of field names to accept
     */
    public function accept(EnrichmentResult $enrichmentResult, array $acceptedFields, string $reviewedBy): void
    {
        $product = $enrichmentResult->product;
        $enrichedData = $enrichmentResult->enriched_data;

        DB::transaction(function () use ($product, $enrichedData, $acceptedFields, $enrichmentResult, $reviewedBy): void {
            // Start with mandatory tracking clear; merge scalar-field updates on top.
            // Note: platform_product_id is set during barcode lookup when a match is found.
            // The enrichment flow uses tracking_id (submission ID), not the canonical product ID.
            $productUpdates = [
                'enrichment_status' => null,
                'platform_submission_id' => null,
            ];

            foreach ($acceptedFields as $field) {
                match ($field) {
                    'name' => $productUpdates['name'] = $enrichedData->name,
                    'description' => $productUpdates['description'] = $enrichedData->description,
                    'barcode' => $productUpdates['barcode'] = $enrichedData->assigned_barcode,
                    default => null,
                };
            }

            // Brand upsert: firstOrCreate on (tenant_id, slug), retry once on concurrent race.
            if (in_array('brand', $acceptedFields, true) && filled($enrichedData->brand)) {
                $slug = Brand::slugFor($enrichedData->brand);
                $criteria = ['tenant_id' => $product->tenant_id, 'slug' => $slug];
                $createAttrs = ['name' => $enrichedData->brand, 'is_active' => true];

                try {
                    $brand = Brand::firstOrCreate($criteria, $createAttrs);
                } catch (QueryException) {
                    // Concurrent accept race — retry once; the row now exists.
                    $brand = Brand::firstOrCreate($criteria, $createAttrs);
                }

                $productUpdates['brand_id'] = $brand->id;
                $productUpdates['brand_source'] = BrandSource::Enriched;
            }

            $product->update($productUpdates);

            // Record accepted fields as a map
            $acceptedFieldsMap = [];
            foreach ($acceptedFields as $field) {
                $acceptedFieldsMap[$field] = true;
            }

            $enrichmentResult->update([
                'status' => EnrichmentReviewStatus::Accepted,
                'reviewed_at' => now(),
                'reviewed_by' => $reviewedBy,
                'accepted_fields' => $acceptedFieldsMap,
            ]);
        });
    }

    /**
     * Reject enrichment results.
     */
    public function reject(EnrichmentResult $enrichmentResult, string $reviewedBy, ?string $reason): void
    {
        $enrichmentResult->update([
            'status' => EnrichmentReviewStatus::Rejected,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewedBy,
            'rejection_reason' => $reason,
        ]);

        $enrichmentResult->product->update([
            'enrichment_status' => EnrichmentStatus::Rejected,
            'platform_submission_id' => null,
        ]);
    }

    /**
     * List enrichment results for review with optional filters.
     *
     * @return LengthAwarePaginator<EnrichmentResult>
     */
    public function listForReview(
        string $tenantId,
        string $companyId,
        ?EnrichmentReviewStatus $status,
        ?string $quality,
    ): LengthAwarePaginator {
        $query = EnrichmentResult::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with('product')
            ->orderByDesc('created_at');

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($quality !== null) {
            $query->where('enrichment_quality', $quality);
        }

        return $query->paginate(25);
    }
}
