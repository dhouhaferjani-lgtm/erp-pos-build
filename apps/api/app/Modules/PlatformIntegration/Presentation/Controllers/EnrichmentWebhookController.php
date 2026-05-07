<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @cross-tenant-by-design Webhook entry — tenant resolution shape (b)
 * sub-form (master plan §8): the controller body performs ZERO DB access.
 * It maps the verified payload to EnrichmentWebhookPayload DTO and
 * dispatches ProcessEnrichmentWebhookJob per item; tenant resolves
 * downstream through the globally-unique tracking_id (=
 * platform_submission_id) → Product → company_id chain.
 *
 * Signature verification fires at the route layer via
 * VerifySynerivaWebhookSignature middleware
 * (Modules/PlatformIntegration/Presentation/routes.php:15) BEFORE the
 * controller body executes. The middleware enforces:
 *   - Required headers X-Syneriva-Signature + X-Syneriva-Timestamp
 *   - Required global secret SYNERIVA_WEBHOOK_SECRET
 *     (config services.platform.webhook_secret)
 *   - Timestamp freshness ≤ 300s (replay window)
 *   - HMAC-SHA256 over `timestamp.body` matched via constant-time hash_equals
 *   - HttpException(403) on any failure — handled before this controller
 *
 * Downstream resolution chain (existing annotation):
 *   ProcessEnrichmentWebhookJob.php:16 carries
 *   `@cross-tenant-by-design Webhook re-dispatcher queue job …` documenting
 *   the platform_submission_id → Product → company_id resolution path that
 *   ProcessEnrichmentEventListener performs synchronously after the job
 *   re-emits EnrichmentWebhookReceived.
 *
 * Defense-in-depth: the platform_submission_id global-uniqueness contract
 * is tracked as Finding A in the scheduled-jobs cross-cluster observations
 * doc, slated for future api.platform-integration (or unified
 * api.external-id-uniqueness) cluster.
 */
final class EnrichmentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->all();
        $event = (string) ($data['event'] ?? '');

        if ($event === 'enrichment.batch_resolved') {
            /** @var array<int, array<string, mixed>> $items */
            $items = $data['items'] ?? [];

            foreach ($items as $item) {
                /** @var array<string, mixed> $item */
                $item['event'] = 'enrichment.resolved';
                $payload = EnrichmentWebhookPayload::fromWebhook($item);
                ProcessEnrichmentWebhookJob::dispatch($payload)->onQueue('enrichment');
            }
        } else {
            $payload = EnrichmentWebhookPayload::fromWebhook($data);
            ProcessEnrichmentWebhookJob::dispatch($payload)->onQueue('enrichment');
        }

        return response()->json(['received' => true]);
    }
}
