<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Application\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Presentation\Controllers\EnrichmentWebhookController;
use App\Modules\Product\Application\Listeners\ProcessEnrichmentEventListener;
use App\Modules\Product\Domain\Events\EnrichmentWebhookReceived;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Webhook re-dispatcher for enrichment results.
 *
 * `handle()` itself does no DB work — it only re-emits
 * {@see EnrichmentWebhookReceived} — but {@see ProcessEnrichmentEventListener}
 * is a SYNCHRONOUS listener that runs inside this job and immediately does
 * `Product::where('platform_submission_id', …)->sole()`. `products` is a TENANT
 * table, so this job's tenancy IS the listener's tenancy. "Zero DB access in
 * handle()" was never a tenancy justification.
 *
 * **Why the previous `@cross-tenant-by-design` claim was false post-flip
 * (2026-08-05 cat-(b) re-sweep).** It said the entry was "intentionally
 * tenant-agnostic" because tenant resolution chains through
 * `platform_submission_id → Product → company_id`. That chain's FIRST hop is
 * the tenant-table read that fails: {@see EnrichmentWebhookController}'s route
 * is `['api', VerifySynerivaWebhookSignature]` with no `auth:sanctum`, session
 * or signed link, so `ResolveTenancy` binds nothing, the dispatch happens under
 * CENTRAL context, `QueueTenancyBootstrapper` stamps no tenant on the payload,
 * and the worker runs central too. The `sole()` then raised a 42P01
 * `QueryException`, which the listener's `catch (ModelNotFoundException)` does
 * not catch.
 *
 * The anchor therefore has to travel WITH the payload. It rides on
 * {@see EnrichmentWebhookPayload::$tenantId} and is rebound here through
 * {@see BindsTenantContext}.
 *
 * **Null handling — DISCARD, and why that is safe here.** The platform does not
 * echo `tenant_id` back yet (tracked in
 * docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md),
 * and every payload enqueued before 2026-08-05 carries no anchor. Guessing a
 * tenant would be exactly the cross-tenant write the anchor exists to prevent,
 * and running under central is the bug itself. Discarding is safe because this
 * webhook is an OPTIMISATION rather than the system of record:
 * `enrichment:check-pending` re-polls every product still Pending/Enriching
 * every 15 minutes, inside each tenant's own database, and dispatches the same
 * event. The result arrives late, not never.
 *
 * `$tenantId` is DECLARED and defaulted rather than a promoted `readonly`, per
 * the property contract on {@see BindsTenantContext}: this class has been
 * dispatched for months without an anchor, and
 * `SerializesModels::__unserialize()` skips absent payload keys — a promoted
 * `readonly string` would stay uninitialized and fatal on first read, making
 * every pre-existing `failed_jobs` row permanently un-retryable.
 */
final class ProcessEnrichmentWebhookJob implements ShouldQueue
{
    use BindsTenantContext;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * See the class docblock for why this is declared + defaulted rather than
     * promoted readonly. `__serialize()` omits default-valued properties, so
     * new payloads do not grow.
     */
    public ?string $tenantId = null;

    public function __construct(
        public readonly EnrichmentWebhookPayload $payload,
    ) {
        $this->tenantId = $payload->tenantId;
    }

    public function handle(): void
    {
        if ($this->tenantId === null) {
            Log::warning('ProcessEnrichmentWebhookJob discarded: webhook payload carries no tenant anchor, and the owning tenant cannot be derived from tracking_id without already being inside that tenant. enrichment:check-pending re-resolves this submission from the owning tenant within 15 minutes.', [
                'job' => self::class,
                'tracking_id' => $this->payload->trackingId,
                'status' => $this->payload->status,
            ]);

            return;
        }

        $this->withTenantContext(function (): void {
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
        });
    }
}
