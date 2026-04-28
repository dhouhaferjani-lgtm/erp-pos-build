<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class EnrichmentWebhookReceived
{
    use Dispatchable;

    public function __construct(
        public readonly string $trackingId,
        public readonly string $status,
        public readonly ?string $enrichmentQuality,
        public readonly bool $hasBarcodeAssigned,
        public readonly string $vertical,
    ) {}
}
