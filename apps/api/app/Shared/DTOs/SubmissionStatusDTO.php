<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

/**
 * Typed response from a platform submission status check.
 */
final readonly class SubmissionStatusDTO
{
    /**
     * @param  array<string, mixed>  $enrichedData
     */
    public function __construct(
        public string $trackingId,
        public string $status,
        public ?string $enrichmentQuality,
        public array $enrichedData,
        public ?string $assignedBarcode,
        public ?string $vertical,
        public ?string $locale = null,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromApiResponse(array $response): self
    {
        return new self(
            trackingId: $response['tracking_id'] ?? '',
            status: $response['status'] ?? '',
            enrichmentQuality: $response['enrichment_quality'] ?? null,
            enrichedData: $response['enriched_data'] ?? [],
            assignedBarcode: $response['enriched_data']['assigned_barcode'] ?? $response['assigned_barcode'] ?? null,
            vertical: $response['vertical'] ?? null,
            // Guard the ?string property: a malformed payload (non-string
            // locale) degrades to null instead of a strict_types TypeError.
            locale: isset($response['locale']) && is_string($response['locale']) ? $response['locale'] : null,
        );
    }
}
