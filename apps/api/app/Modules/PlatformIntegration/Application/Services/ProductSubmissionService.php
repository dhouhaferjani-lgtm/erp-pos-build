<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\DTOs\SubmissionResultData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use App\Shared\Contracts\PlatformSubmissionInterface;
use App\Shared\DTOs\SubmissionStatusDTO;
use App\Shared\Enums\BrandMappingPushResult;
use App\Shared\Enums\EnrichmentFeedbackAction;
use App\Shared\Enums\EnrichmentFeedbackReason;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ProductSubmissionService implements PlatformSubmissionInterface
{
    public function __construct(
        private readonly PlatformHttpClient $platformClient,
    ) {}

    /**
     * Request a pre-signed upload URL for a product photo.
     *
     * @return array<string, mixed>|null
     */
    public function requestUploadUrl(string $filename, string $contentType, int $sizeBytes): ?array
    {
        return $this->platformClient->postRaw('/api/v1/products/upload-url', [
            'filename' => $filename,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
        ]);
    }

    /**
     * Upload a photo directly to the pre-signed URL (bypasses PlatformHttpClient).
     *
     * Pre-signed URL: the URL IS the auth credential. Tenant headers would
     * be dead weight (S3/GCS doesn't read them). The pre-sign step
     * (issued by PlatformHttpClient elsewhere via requestUploadUrl()) is
     * where tenant context binds — the pre-signed URL is a one-shot
     * upload credential minted in response to a tenant-bound API call.
     * Re-applying the X-Tenant-Id / X-Company-Id discipline here would
     * not improve isolation and would couple us to a header convention
     * the storage layer doesn't honor.
     */
    public function uploadPhoto(string $uploadUrl, string $fileContents, string $contentType): void
    {
        Http::withBody($fileContents, $contentType)
            ->timeout(30)
            ->connectTimeout(10)
            ->put($uploadUrl);
    }

    /**
     * Submit a product to the platform for enrichment.
     */
    public function submit(ProductSubmissionData $submission): ?SubmissionResultData
    {
        $response = $this->platformClient->postRaw('/api/v1/products/submit', [
            'barcode' => $submission->barcode,
            'vertical' => $submission->vertical,
            'name' => $submission->name,
            'brand' => $submission->brand,
            'category' => $submission->category,
            'description' => $submission->description,
            'attributes' => $submission->attributes,
            'photo_ids' => $submission->photoIds,
            'auto_enrich' => $submission->autoEnrich,
        ], [
            'Idempotency-Key' => Str::uuid()->toString(),
        ]);

        if ($response === null) {
            return null;
        }

        return SubmissionResultData::fromApiResponse($response);
    }

    /**
     * Submit multiple products in a single bulk request.
     *
     * @param  array<int, ProductSubmissionData>  $submissions
     * @return array<string, mixed>|null
     */
    public function bulkSubmit(string $vertical, array $submissions, bool $autoEnrich): ?array
    {
        $items = array_map(fn (ProductSubmissionData $s) => [
            'barcode' => $s->barcode,
            'name' => $s->name,
            'brand' => $s->brand,
            'category' => $s->category,
            'description' => $s->description,
            'attributes' => $s->attributes,
            'photo_ids' => $s->photoIds,
        ], $submissions);

        return $this->platformClient->postRaw('/api/v1/products/bulk-submit', [
            'vertical' => $vertical,
            'auto_enrich' => $autoEnrich,
            'items' => $items,
        ]);
    }

    /**
     * Lookup multiple barcodes in a single request.
     *
     * @param  array<int, string>  $barcodes
     * @return array<string, mixed>|null
     */
    public function bulkLookup(array $barcodes, string $vertical): ?array
    {
        return $this->platformClient->postRaw('/api/v1/products/bulk-lookup', [
            'barcodes' => $barcodes,
            'vertical' => $vertical,
        ]);
    }

    /**
     * Check the enrichment status of a previously submitted product (typed DTO).
     *
     * Implements PlatformSubmissionInterface for cross-module use.
     */
    public function checkStatus(string $trackingId): ?SubmissionStatusDTO
    {
        $response = $this->checkStatusRaw($trackingId);

        if ($response === null) {
            return null;
        }

        return SubmissionStatusDTO::fromApiResponse($response);
    }

    /**
     * Check the enrichment status — raw array response for internal use.
     *
     * @return array<string, mixed>|null
     */
    public function checkStatusRaw(string $trackingId): ?array
    {
        return $this->platformClient->getRaw('/api/v1/products/lookup-status/'.$trackingId);
    }

    public function sendFeedback(
        string $trackingId,
        EnrichmentFeedbackAction $action,
        ?EnrichmentFeedbackReason $reason,
        ?string $notes,
    ): bool {
        $exceptionContext = [];

        try {
            $response = $this->platformClient->postRaw(
                "/api/v1/products/lookup-status/{$trackingId}/feedback",
                [
                    'action' => $action->value,
                    'reason' => $reason?->value,
                    'notes' => $notes,
                ],
            );

            if ($response !== null) {
                return true;
            }
        } catch (\Throwable $e) {
            $exceptionContext = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ];
        }

        Log::warning('Failed to send enrichment feedback to platform', array_merge([
            'tracking_id' => $trackingId,
            'action' => $action->value,
            'reason' => $reason?->value,
        ], $exceptionContext));

        return false;
    }

    public function pushBrandMapping(string $canonicalBrandId, string $externalBrandId): BrandMappingPushResult
    {
        try {
            $response = $this->platformClient->postWithStatus(
                "/api/v1/brands/{$canonicalBrandId}/external-mapping",
                ['external_brand_id' => $externalBrandId],
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to push brand mapping to platform', [
                'canonical_brand_id' => $canonicalBrandId,
                'external_brand_id' => $externalBrandId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return BrandMappingPushResult::Failed;
        }

        if ($response->status >= 200 && $response->status < 300) {
            return BrandMappingPushResult::Mapped;
        }

        if ($response->status === 422) {
            Log::error('Brand mapping conflict: local brand id already mapped to another canonical brand', [
                'canonical_brand_id' => $canonicalBrandId,
                'external_brand_id' => $externalBrandId,
                'platform_message' => $response->body['error']['message'] ?? null,
            ]);

            return BrandMappingPushResult::Conflict;
        }

        if ($response->status === 404) {
            Log::error('Brand mapping push rejected: canonical brand not found on platform', [
                'canonical_brand_id' => $canonicalBrandId,
                'external_brand_id' => $externalBrandId,
            ]);

            return BrandMappingPushResult::NotFound;
        }

        Log::warning('Brand mapping push failed', [
            'canonical_brand_id' => $canonicalBrandId,
            'external_brand_id' => $externalBrandId,
            'status' => $response->status,
        ]);

        return BrandMappingPushResult::Failed;
    }

    /**
     * Trigger enrichment for a previously submitted product.
     */
    public function triggerEnrichment(string $trackingId): ?SubmissionResultData
    {
        $response = $this->platformClient->postRaw('/api/v1/products/'.$trackingId.'/enrich');

        if ($response === null) {
            return null;
        }

        return SubmissionResultData::fromApiResponse($response);
    }

    /**
     * Get available attributes for a category within a vertical.
     *
     * @return array<string, mixed>|null
     */
    public function getCategoryAttributes(string $vertical, string $category): ?array
    {
        return $this->platformClient->getRaw('/api/v1/verticals/'.$vertical.'/categories/'.$category.'/attributes');
    }
}
