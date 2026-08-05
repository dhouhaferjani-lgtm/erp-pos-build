<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Jobs\IngestChannelOrderJob;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelOrderIngestService;
use App\Modules\Channel\Domain\Events\ChannelOrderReceived;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryObserver;
use App\Modules\Channel\Infrastructure\Directory\ChannelWebhookDirectoryRegistrar;
use App\Modules\Tenant\Application\Services\TenancyResolver;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @cross-tenant-by-design Webhook entry: the route is unauthenticated because
 * external sales channels call it directly, so `ResolveTenancy` binds nothing.
 *
 * **Tenant resolution — CORRECTED 2026-08-05 (cat-(b) re-sweep).** The previous
 * annotation claimed ownership was "resolved from the signed channel secret
 * after loading the channel by globally unique channel id". Both halves were
 * unachievable post-flip:
 *   - `channels` is a TENANT table, so `Channel::findOrFail($channelId)` on the
 *     central connection raised 42P01 and every external callback 500'd;
 *   - the signature cannot be checked first either — verification needs the
 *     channel's adapter and credentials, which is the same unreadable row.
 *
 * The channel id in the URL is the ONLY identifier the request carries, so the
 * tenant has to be resolvable from CENTRAL. That is what
 * `channel_webhook_directory` is for (see its migration): a minimal
 * `channel_id -> tenant_id` pointer, maintained by
 * {@see ChannelWebhookDirectoryObserver}
 * and self-healed nightly by `channels:reconcile`.
 *
 * FAIL CLOSED on an unresolvable id — 404, before the adapter registry is even
 * consulted. Deliberately NOT a per-tenant fan-out: this endpoint is
 * unauthenticated, so scanning every tenant database for a channel id would let
 * one forged request cost N database switches.
 */
final class ChannelWebhookController extends Controller
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public function __construct(
        private readonly AdapterRegistry $adapterRegistry,
        private readonly ChannelOrderIngestService $ingestService,
        private readonly ChannelWebhookDirectoryRegistrar $directory,
        private readonly TenancyResolver $tenancyResolver,
    ) {}

    #[CrossTenantRoute(reason: 'Channel webhook entry: pre-auth external callback. `channels` is a tenant table and the request carries no credential, so the owning tenant is resolved from the CENTRAL channel_webhook_directory pointer (channel_id -> tenant_id) and bound via TenancyResolver BEFORE the channel row, its adapter and its signature are read. Fail-closed 404 on an unresolvable channel id — never a per-tenant fan-out, which on an unauthenticated route would be an amplification vector. The queued order ingestion then carries that same tenantId anchor.')]
    public function __invoke(Request $request, string $channelId): JsonResponse
    {
        $timestamp = $request->header('X-Channel-Timestamp');
        if ($timestamp === null || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            throw new HttpException(403, 'Webhook timestamp expired.');
        }

        // CENTRAL lookup first: without it nothing below is readable.
        $tenantId = $this->directory->resolveTenantId($channelId);
        if ($tenantId === null) {
            throw new NotFoundHttpException('Unknown channel.');
        }

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            // Directory row outlived its tenant (deprovisioned). Same answer as
            // an unknown channel — the caller must not be able to tell them
            // apart from an unauthenticated endpoint.
            throw new NotFoundHttpException('Unknown channel.');
        }

        // Fail-closed binding: under database-per-tenant an unprovisioned or
        // unopenable tenant database raises TenantUnavailableException (503)
        // rather than silently degrading to the central connection.
        $this->tenancyResolver->initializeIfProvisioned($tenant);

        $channel = Channel::query()->findOrFail($channelId);
        $adapter = $this->adapterRegistry->resolve($channel->adapter_type);

        if (! $adapter->signatureStrategy()->verify($request, $channel)) {
            throw new HttpException(403, 'Invalid webhook signature.');
        }

        $payload = $request->json()->all();
        $externalOrderId = isset($payload['external_order_id']) ? (string) $payload['external_order_id'] : hash('sha256', $request->getContent());
        $order = $this->ingestService->ingest($externalOrderId, $payload, $channel);

        event(new ChannelOrderReceived($order->id, $channel->id));
        IngestChannelOrderJob::dispatch($order->id, $tenantId);

        return response()->json(['data' => ['order_id' => $order->id]], 202);
    }
}
