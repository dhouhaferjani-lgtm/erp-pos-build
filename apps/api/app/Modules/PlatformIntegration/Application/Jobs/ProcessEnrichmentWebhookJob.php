<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Jobs;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessEnrichmentWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly EnrichmentWebhookPayload $payload,
    ) {}

    public function handle(): void
    {
        EnrichmentWebhookReceived::dispatch(
            $this->payload->trackingId,
            $this->payload->status,
            $this->payload->enrichmentQuality,
            $this->payload->hasBarcodeAssigned,
            $this->payload->vertical,
        );
    }
}
