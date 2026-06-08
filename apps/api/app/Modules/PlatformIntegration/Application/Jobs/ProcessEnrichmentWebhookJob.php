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

/**
 * @cross-tenant-by-design Webhook re-dispatcher queue job — handle() does ZERO DB access, only re-dispatches the EnrichmentWebhookReceived event; the synchronous downstream listener (ProcessEnrichmentEventListener) resolves Product by the globally-unique platform_submission_id encoded in payload->trackingId. Webhook entry (EnrichmentWebhookController) is intentionally tenant-agnostic — third-party platform calls back about previously-tracked submissions, and tenant resolution chains through platform_submission_id → Product → company_id. The platform_submission_id global-uniqueness contract risk is tracked separately at docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md (Finding A) for api.platform-integration.
 */
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
            // Null-coalesce (isset semantics) so a job enqueued before the
            // `locale` property existed — whose deserialized payload leaves
            // `$locale` uninitialized — degrades to null instead of throwing
            // "must not be accessed before initialization" on rolling deploy.
            $this->payload->locale ?? null,
        );
    }
}
