<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Services;

use App\Modules\PlatformIntegration\Application\DTOs\ProductSubmissionData;
use App\Modules\PlatformIntegration\Application\DTOs\SubmissionResultData;
use App\Modules\PlatformIntegration\Infrastructure\Http\PlatformHttpClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class ProductSubmissionService
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
        return $this->platformClient->postRaw('/api/v1/products/uploads/request', [
            'filename' => $filename,
            'content_type' => $contentType,
            'size_bytes' => $sizeBytes,
        ]);
    }

    /**
     * Upload a photo directly to the pre-signed URL (bypasses PlatformHttpClient).
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

        return $this->platformClient->postRaw('/api/v1/products/submit/bulk', [
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
        return $this->platformClient->postRaw('/api/v1/products/lookup/bulk', [
            'barcodes' => $barcodes,
            'vertical' => $vertical,
        ]);
    }

    /**
     * Check the enrichment status of a previously submitted product.
     *
     * @return array<string, mixed>|null
     */
    public function checkStatus(string $trackingId): ?array
    {
        return $this->platformClient->getRaw('/api/v1/products/status/'.$trackingId);
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
        return $this->platformClient->getRaw('/api/v1/products/categories/'.$vertical.'/'.$category.'/attributes');
    }
}
