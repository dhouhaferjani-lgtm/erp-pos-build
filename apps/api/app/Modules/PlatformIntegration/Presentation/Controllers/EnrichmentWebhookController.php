<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\DTOs\EnrichmentWebhookPayload;
use App\Modules\PlatformIntegration\Application\Jobs\ProcessEnrichmentWebhookJob;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * @cross-tenant-by-design Webhook entry — the controller body performs ZERO DB
 * access. It maps the verified payload to an EnrichmentWebhookPayload DTO and
 * dispatches ProcessEnrichmentWebhookJob per item.
 *
 * Signature verification fires at the route layer via
 * VerifySynerivaWebhookSignature middleware
 * (Modules/PlatformIntegration/Presentation/routes.php) BEFORE the controller
 * body executes. The middleware enforces:
 *   - Required headers X-Syneriva-Signature + X-Syneriva-Timestamp
 *   - Required global secret SYNERIVA_WEBHOOK_SECRET
 *     (config services.platform.webhook_secret)
 *   - Timestamp freshness ≤ 300s (replay window)
 *   - HMAC-SHA256 over `timestamp.body` matched via constant-time hash_equals
 *   - HttpException(403) on any failure — handled before this controller
 *
 * **Tenant resolution — CORRECTED 2026-08-05 (cat-(b) re-sweep).** This
 * annotation used to claim the tenant "resolves downstream through the
 * globally-unique tracking_id → Product → company_id chain". That chain is
 * unwalkable post-flip: `products` is a TENANT table and the route is
 * unauthenticated, so `ResolveTenancy` binds nothing and the very first hop
 * would run on CENTRAL and raise 42P01. The tenant anchor is now read from the
 * webhook body (`tenant_id` -> EnrichmentWebhookPayload::$tenantId) and rebound
 * by ProcessEnrichmentWebhookJob via BindsTenantContext.
 *
 * The platform does not send `tenant_id` yet; the ERP side is deliberately
 * TOLERANT (absent -> null) and the job discards anchorless payloads, which
 * `enrichment:check-pending` re-resolves per tenant within 15 minutes. The
 * platform-side contract change is tracked at
 * docs/superpowers/tickets/2026-08-05-enrichment-webhook-platform-contract.md.
 * That change also closes Finding A (platform_submission_id global-uniqueness)
 * in the scheduled-jobs cross-cluster observations doc.
 */
final class EnrichmentWebhookController extends Controller
{
    #[CrossTenantRoute(reason: 'Synerivia enrichment webhook entry: signature verified at the route layer via VerifySynerivaWebhookSignature middleware BEFORE this controller fires (HMAC-SHA256 + timestamp freshness <= 300s + constant-time hash_equals). The controller body performs ZERO DB access; the tenant anchor travels in the webhook body (tenant_id -> EnrichmentWebhookPayload::$tenantId) and is rebound by ProcessEnrichmentWebhookJob through BindsTenantContext. An anchorless payload is DISCARDED, never processed under central — enrichment:check-pending re-resolves it per tenant within 15 minutes.')]
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
