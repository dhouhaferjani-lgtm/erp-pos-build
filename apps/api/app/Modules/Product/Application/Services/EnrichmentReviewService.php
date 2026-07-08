<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\BrandResolution;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Jobs\SendEnrichmentFeedbackJob;
use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\BrandSource;
use App\Modules\Product\Domain\Enums\EnrichmentResultOrigin;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class EnrichmentReviewService
{
    public function __construct(
        private readonly PlatformSubmissionInterface $submissionService,
        private readonly BrandResolutionService $brandResolution,
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

        $enrichedData = $this->buildEnrichedProductData($statusDTO->enrichedData, $product, $webhookLocale ?? $statusDTO->locale);
        $enrichmentQuality = $statusDTO->enrichmentQuality ?? 'unknown';
        $assignedBarcode = $statusDTO->assignedBarcode;

        $attempt = function () use ($trackingId, $product, $enrichedData, $enrichmentQuality, $assignedBarcode): EnrichmentResult {
            return DB::transaction(function () use ($trackingId, $product, $enrichedData, $enrichmentQuality, $assignedBarcode): EnrichmentResult {
                $latest = EnrichmentResult::query()
                    ->where('tracking_id', $trackingId)
                    ->orderByDesc('version')
                    ->lockForUpdate()
                    ->first();

                if ($latest === null) {
                    return EnrichmentResult::create([
                        'tenant_id' => $product->tenant_id,
                        'company_id' => $product->company_id,
                        'product_id' => $product->id,
                        'tracking_id' => $trackingId,
                        'version' => 1,
                        'origin' => EnrichmentResultOrigin::Initial,
                        'status' => EnrichmentReviewStatus::PendingReview,
                        'enriched_data' => $enrichedData,
                        'enrichment_quality' => $enrichmentQuality,
                        'assigned_barcode' => $assignedBarcode,
                    ]);
                }

                if ($latest->status === EnrichmentReviewStatus::PendingReview) {
                    $latest->update([
                        'tenant_id' => $product->tenant_id,
                        'company_id' => $product->company_id,
                        'product_id' => $product->id,
                        'enriched_data' => $enrichedData,
                        'enrichment_quality' => $enrichmentQuality,
                        'assigned_barcode' => $assignedBarcode,
                    ]);

                    return $latest;
                }

                if ($this->payloadMatches($latest, $enrichedData, $enrichmentQuality, $assignedBarcode)) {
                    return $latest;
                }

                return EnrichmentResult::create([
                    'tenant_id' => $product->tenant_id,
                    'company_id' => $product->company_id,
                    'product_id' => $product->id,
                    'tracking_id' => $trackingId,
                    'version' => $latest->version + 1,
                    'origin' => EnrichmentResultOrigin::CuratedUpdate,
                    'status' => EnrichmentReviewStatus::PendingReview,
                    'enriched_data' => $enrichedData,
                    'enrichment_quality' => $enrichmentQuality,
                    'assigned_barcode' => $assignedBarcode,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'accepted_fields' => null,
                    'rejection_reason' => null,
                    'rejection_notes' => null,
                ]);
            });
        };

        try {
            return $attempt();
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $attempt();
        }
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
        $trackingId = $enrichmentResult->tracking_id;
        $companyId = $enrichmentResult->company_id;
        $brandResolutionOutcome = null;

        // Wrap the entire transaction in a closure so the retry restarts a FRESH transaction.
        // On PostgreSQL a unique-constraint violation inside a transaction aborts the whole
        // transaction, meaning any subsequent query (including the retry firstOrCreate) fails
        // with "current transaction is aborted". Moving the retry outside DB::transaction()
        // avoids that aborted-state problem.
        $attempt = function () use ($product, $enrichedData, $acceptedFields, $enrichmentResult, $reviewedBy, &$brandResolutionOutcome): void {
            DB::transaction(function () use ($product, $enrichedData, $acceptedFields, $enrichmentResult, $reviewedBy, &$brandResolutionOutcome): void {
                // Start with mandatory tracking clear; merge scalar-field updates on top.
                // platform_product_id is written at product create (CreateProductRequest)
                // when the product originated from a FOUND catalog lookup - it is NOT set
                // by this review flow, which correlates via tracking_id (submission id).
                $productUpdates = [
                    'enrichment_status' => null,
                    'platform_submission_id' => null,
                ];

                foreach ($acceptedFields as $field) {
                    match ($field) {
                        'name' => $productUpdates['name'] = $enrichedData->name,
                        'description' => $enrichedData->description !== null
                            ? $productUpdates['description'] = $enrichedData->description
                            : null,
                        'barcode' => $enrichedData->assigned_barcode !== null
                            ? $productUpdates['barcode'] = $enrichedData->assigned_barcode
                            : null,
                        default => null,
                    };
                }

                // Brand resolution ladder: platform external mapping -> canonical id -> slug -> create.
                // Concurrent-race retry is handled by the outer try/catch: it restarts a fresh
                // transaction so the retry resolve() finds the row inserted by the racing request.
                if (in_array('brand', $acceptedFields, true) && filled($enrichedData->brand)) {
                    $resolution = $this->brandResolution->resolve(
                        $product->tenant_id,
                        $enrichedData->brand,
                        $enrichedData->canonical_brand_id,
                        $enrichedData->external_brand_id,
                    );
                    $brandResolutionOutcome = $resolution;

                    $productUpdates['brand_id'] = $resolution->brand->id;
                    $productUpdates['brand_source'] = BrandSource::Enriched;
                }

                $product->update($productUpdates);

                // Path B (human-reviewed accept): images persist automatically unless the
                // reviewer explicitly submits a non-empty accepted-fields list that excludes
                // 'images'. Mirrors Path A (CatalogEnrichmentService::applyCatalogHit()) -
                // afterCommit() is required so the queue worker never races the
                // surrounding transaction (see SendEnrichmentFeedbackJob below).
                $imagesRequested = $acceptedFields === [] || in_array('images', $acceptedFields, true);
                $normalizedImages = ImageDescriptorNormalizer::normalize($enrichedData->images);
                if ($imagesRequested && $normalizedImages !== []) {
                    PersistEnrichmentImagesJob::dispatch(
                        $product->tenant_id,
                        $product->id,
                        $normalizedImages,
                    )->afterCommit();
                }

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
        };

        try {
            $attempt();
        } catch (QueryException $e) {
            // Only retry on a unique-constraint violation (concurrent brand-insert race).
            // Any other database error is re-thrown immediately.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            $attempt(); // fresh transaction; resolve() now finds the racing row
        }

        if ($brandResolutionOutcome instanceof BrandResolution) {
            $this->brandResolution->dispatchPushIfNeeded($brandResolutionOutcome, $companyId);
        }

        if (is_string($trackingId)) {
            try {
                SendEnrichmentFeedbackJob::dispatch(
                    trackingId: $trackingId,
                    action: EnrichmentFeedbackAction::Confirmed->value,
                    reason: null,
                    notes: null,
                    companyId: $companyId,
                )->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('enrichment feedback dispatch failed', [
                    'tracking_id' => $trackingId,
                    'action' => EnrichmentFeedbackAction::Confirmed->value,
                    'company_id' => $companyId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Reject enrichment results.
     */
    public function reject(
        EnrichmentResult $enrichmentResult,
        string $reviewedBy,
        EnrichmentFeedbackReason $reason,
        ?string $notes,
    ): void {
        $trackingId = $enrichmentResult->tracking_id;
        $companyId = $enrichmentResult->company_id;

        $enrichmentResult->update([
            'status' => EnrichmentReviewStatus::Rejected,
            'reviewed_at' => now(),
            'reviewed_by' => $reviewedBy,
            'rejection_reason' => $reason->value,
            'rejection_notes' => $notes,
        ]);

        $enrichmentResult->product->update([
            'enrichment_status' => EnrichmentStatus::Rejected,
            'platform_submission_id' => null,
        ]);

        if (is_string($trackingId)) {
            try {
                SendEnrichmentFeedbackJob::dispatch(
                    trackingId: $trackingId,
                    action: EnrichmentFeedbackAction::Rejected->value,
                    reason: $reason->value,
                    notes: $notes,
                    companyId: $companyId,
                )->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('enrichment feedback dispatch failed', [
                    'tracking_id' => $trackingId,
                    'action' => EnrichmentFeedbackAction::Rejected->value,
                    'reason' => $reason->value,
                    'company_id' => $companyId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }
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
        ?string $productId = null,
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

        if ($productId !== null) {
            $query->where('product_id', $productId);
        }

        return $query->paginate(25);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function buildEnrichedProductData(array $payload, Product $product, ?string $locale): EnrichedProductData
    {
        return new EnrichedProductData(
            name: is_string($payload['name'] ?? null) ? $payload['name'] : $product->name,
            brand: is_string($payload['brand'] ?? null) ? $payload['brand'] : null,
            description: is_string($payload['description'] ?? null) ? $payload['description'] : null,
            classification: is_array($payload['classification'] ?? null) ? $payload['classification'] : [],
            ingredients: $this->ingredientsFromPayload($payload['ingredients'] ?? null),
            images: $this->imagesFromPayload($payload['images'] ?? null),
            confidence_score: (int) ($payload['confidence_score'] ?? 0),
            enrichment_tier: is_string($payload['enrichment_tier'] ?? null) ? $payload['enrichment_tier'] : null,
            field_confidence: is_array($payload['field_confidence'] ?? null) ? $payload['field_confidence'] : null,
            enrichment_sources: is_array($payload['enrichment_sources'] ?? null) ? $payload['enrichment_sources'] : null,
            assigned_barcode: is_string($payload['assigned_barcode'] ?? null) ? $payload['assigned_barcode'] : null,
            assigned_barcode_type: is_string($payload['assigned_barcode_type'] ?? null) ? $payload['assigned_barcode_type'] : null,
            locale: $locale ?? (is_string($payload['locale'] ?? null) ? $payload['locale'] : null),
            canonical_brand_id: is_string($payload['canonical_brand_id'] ?? null) ? $payload['canonical_brand_id'] : null,
            canonical_brand_slug: is_string($payload['canonical_brand_slug'] ?? null) ? $payload['canonical_brand_slug'] : null,
            external_brand_id: is_string($payload['external_brand_id'] ?? null) ? $payload['external_brand_id'] : null,
        );
    }

    /**
     * @return list<array{name: string, position: int}>
     */
    private function ingredientsFromPayload(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ingredients = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $item['name'] ?? null;
            $position = $item['position'] ?? null;
            if (is_string($name) && is_int($position)) {
                $ingredients[] = [
                    'name' => $name,
                    'position' => $position,
                ];
            }
        }

        return $ingredients;
    }

    /**
     * @return list<array{url: string|null, thumbnail: string|null, type: string|null}>
     */
    private function imagesFromPayload(mixed $value): array
    {
        // Unbounded cap: this call site preserves the original (uncapped)
        // display behavior. The persister applies the real cap of 6.
        return ImageDescriptorNormalizer::normalize($value, PHP_INT_MAX);
    }

    private function payloadMatches(
        EnrichmentResult $result,
        EnrichedProductData $enrichedData,
        string $enrichmentQuality,
        ?string $assignedBarcode,
    ): bool {
        return [
            'enriched_data' => $this->comparableEnrichedData($result->enriched_data),
            'enrichment_quality' => $result->enrichment_quality,
            'assigned_barcode' => $result->assigned_barcode,
        ] == [
            'enriched_data' => $this->comparableEnrichedData($enrichedData),
            'enrichment_quality' => $enrichmentQuality,
            'assigned_barcode' => $assignedBarcode,
        ]; // Intentional leniency: null == '' payload differences are accepted as no-op echoes.
    }

    /**
     * @return array<string, mixed>
     */
    private function comparableEnrichedData(EnrichedProductData $enrichedData): array
    {
        $data = $enrichedData->toArray();
        // Locale and brand-mapping metadata are not reviewable content. If the
        // platform later mirrors lookup mapping fields into enriched_data, our
        // own push-back must not mint spurious curated_update versions.
        unset(
            $data['locale'],
            $data['canonical_brand_id'],
            $data['canonical_brand_slug'],
            $data['external_brand_id'],
        );

        return $data;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return $e->getCode() === '23505'
            || str_contains($message, 'unique constraint');
    }
}
