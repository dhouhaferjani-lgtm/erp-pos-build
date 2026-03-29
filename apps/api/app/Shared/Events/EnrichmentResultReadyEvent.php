<?php

declare(strict_types=1);

namespace App\Shared\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when an enrichment result is ready and users should be notified.
 *
 * This is a cross-module event: dispatched by Product module,
 * listened to by Identity module for notification delivery.
 */
final class EnrichmentResultReadyEvent
{
    use Dispatchable;

    public function __construct(
        public readonly string $enrichmentResultId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $productName,
        public readonly string $enrichmentQuality,
        public readonly ?string $assignedBarcode,
    ) {}
}
