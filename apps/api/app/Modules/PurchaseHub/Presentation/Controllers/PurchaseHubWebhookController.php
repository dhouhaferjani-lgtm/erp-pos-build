<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * @cross-tenant-by-design STUB: ZERO DB access, ZERO Bus/Event/Queue
 * dispatch, ZERO Notification send. Route at
 * Modules/PurchaseHub/Presentation/routes.php:25 has NO signature-
 * verification middleware (only the default `api` middleware group), and
 * no auth:sanctum. Today this is acceptable because the controller body
 * is a verifiable noop (Log::info + 200 OK), but the moment any
 * side-effect is added, the route becomes an unauthenticated cross-
 * tenant write surface.
 *
 * BEFORE adding ANY DB access (raw SQL, Eloquent reads/writes), Bus or
 * Event::dispatch, Queue::push, Notification::send, or any model-class
 * static call to this controller body, you MUST:
 *
 *   (1) Register a signature-verification middleware on the route at
 *       Modules/PurchaseHub/Presentation/routes.php:25 — mirror the
 *       VerifySynerivaWebhookSignature pattern at
 *       Modules/PlatformIntegration/Infrastructure/Middleware/
 *       (HMAC-SHA256 over timestamp.body, 5-min freshness, constant-time
 *       hash_equals, dedicated env-var-backed shared secret). Adapt the
 *       HMAC scheme to whatever PurchaseHub's outbound contract specifies
 *       (per-tenant secret OR globally pinned).
 *
 *   (2) Define a tenant-resolution path from the verified payload — one
 *       of the three shapes documented in master plan §8 step 2:
 *         (a) per-tenant signature secret (lookup tenant by webhook_secret),
 *         (b) globally-unique resource identifier in payload (lookup by
 *             external-contract-unique field → row carries tenant_id),
 *         (c) per-tenant URL parameter (route encodes tenantId).
 *       Pick whichever PurchaseHub's outbound contract supports, then
 *       bind the resolved tenant via CompanyContext::setCompanyId(...) or
 *       equivalent BEFORE any downstream call.
 *
 *   (3) Update this annotation to document the chosen tenant-resolution
 *       path; convert the corresponding manual inventory row at
 *       `manual:api.webhooks-incoming:purchase-hub-webhook-controller-stub-cross-tenant-by-design`
 *       from cat-(b)/stub to cat-(a) with a regression test in
 *       tests/Feature/Webhooks/.
 *
 * The architecture test at
 * tests/Architecture/WebhookControllerTenantContextTest.php enforces this
 * contract: when the annotation begins with "STUB", the test additionally
 * inspects the controller body via reflection and fails on any forbidden
 * call pattern (DB::, Bus::, Event::, Queue::, Notification::, ::dispatch(,
 * ::create(, ::find*(, ->save(, ->delete(, ->update(). This converts
 * "forgot to add the signature middleware" from a silent runtime risk
 * into a deterministic CI failure at PR time.
 *
 * Tracked as Finding G in
 * docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md.
 */
final class PurchaseHubWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $event = $request->input('event');

        Log::info('PurchaseHub webhook received', ['event' => $event]);

        // Future: handle order.receipt_confirmed to auto-create PO in ERP
        // For now, just acknowledge

        return response()->json(['status' => 'received']);
    }
}
