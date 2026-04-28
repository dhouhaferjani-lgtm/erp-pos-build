<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\DTOs;

use Spatie\LaravelData\Data;

class EnrichmentWebhookPayload extends Data
{
    public function __construct(
        public string $event,
        public string $trackingId,
        public ?string $barcode,
        public string $status,
        public ?string $enrichmentQuality,
        public bool $hasBarcodeAssigned,
        public string $vertical,
        public string $timestamp,
    ) {}

    /**
     * @param  array<string, mixed>  $webhook
     */
    public static function fromWebhook(array $webhook): self
    {
        return new self(
            event: (string) ($webhook['event'] ?? ''),
            trackingId: (string) ($webhook['tracking_id'] ?? ''),
            barcode: isset($webhook['barcode']) ? (string) $webhook['barcode'] : null,
            status: (string) ($webhook['status'] ?? ''),
            enrichmentQuality: isset($webhook['enrichment_quality']) ? (string) $webhook['enrichment_quality'] : null,
            hasBarcodeAssigned: (bool) ($webhook['has_barcode_assigned'] ?? false),
            vertical: (string) ($webhook['vertical'] ?? ''),
            timestamp: (string) ($webhook['timestamp'] ?? now()->toIso8601String()),
        );
    }
}
