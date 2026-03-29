<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\PlatformIntegration\Application\Services\ProductSubmissionService;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Enums\EnrichmentStatus;
use App\Modules\Product\Domain\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EnrichmentReviewService
{
    public function __construct(
        private readonly ProductSubmissionService $submissionService,
    ) {}

    /**
     * Fetch enrichment status from the platform and store the result locally.
     */
    public function fetchAndStore(string $trackingId, Product $product): ?EnrichmentResult
    {
        $response = $this->submissionService->checkStatus($trackingId);

        if ($response === null) {
            return null;
        }

        $enrichedData = $response['enriched_data'] ?? [];

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
                ),
                'enrichment_quality' => $response['enrichment_quality'] ?? 'unknown',
                'assigned_barcode' => $enrichedData['assigned_barcode'] ?? null,
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
        $updates = [];

        foreach ($acceptedFields as $field) {
            match ($field) {
                'name' => $updates['name'] = $enrichedData->name,
                'brand' => null, // Brand is metadata, not a direct product field — skip silently
                'description' => $updates['description'] = $enrichedData->description,
                'barcode' => $updates['barcode'] = $enrichedData->assigned_barcode,
                default => null,
            };
        }

        if ($updates !== []) {
            $product->update($updates);
        }

        // Link to platform product and clear enrichment tracking
        $product->update([
            'platform_product_id' => $enrichmentResult->tracking_id,
            'enrichment_status' => null,
            'platform_submission_id' => null,
        ]);

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
