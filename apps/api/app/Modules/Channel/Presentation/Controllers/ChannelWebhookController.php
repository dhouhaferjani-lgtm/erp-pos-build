<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Jobs\IngestChannelOrderJob;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelOrderIngestService;
use App\Modules\Channel\Domain\Events\ChannelOrderReceived;
use App\Modules\Channel\Domain\Models\Channel;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @cross-tenant-by-design Webhook entry: the route is unauthenticated because external sales channels call it directly; tenant ownership is resolved from the signed channel secret after loading the channel by globally unique channel id, then the queued order job carries the resolved tenant anchor.
 */
final class ChannelWebhookController extends Controller
{
    private const MAX_TIMESTAMP_AGE_SECONDS = 300;

    public function __construct(
        private readonly AdapterRegistry $adapterRegistry,
        private readonly ChannelOrderIngestService $ingestService,
    ) {}

    #[CrossTenantRoute(reason: 'Channel webhook entry: pre-auth external callback resolved through the globally unique channel id plus adapter signature strategy; after signature verification the queued order ingestion carries the channel company tenantId anchor.')]
    public function __invoke(Request $request, string $channelId): JsonResponse
    {
        $timestamp = $request->header('X-Channel-Timestamp');
        if ($timestamp === null || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_AGE_SECONDS) {
            throw new HttpException(403, 'Webhook timestamp expired.');
        }

        $channel = Channel::query()->with('company:id,tenant_id')->findOrFail($channelId);
        $adapter = $this->adapterRegistry->resolve($channel->adapter_type);

        if (! $adapter->signatureStrategy()->verify($request, $channel)) {
            throw new HttpException(403, 'Invalid webhook signature.');
        }

        $tenantId = $channel->company?->tenant_id;
        if ($tenantId === null) {
            throw new HttpException(422, 'Webhook channel tenant could not be resolved.');
        }

        $payload = $request->json()->all();
        $externalOrderId = isset($payload['external_order_id']) ? (string) $payload['external_order_id'] : hash('sha256', $request->getContent());
        $order = $this->ingestService->ingest($externalOrderId, $payload, $channel);

        event(new ChannelOrderReceived($order->id, $channel->id));
        IngestChannelOrderJob::dispatch($order->id, $tenantId);

        return response()->json(['data' => ['order_id' => $order->id]], 202);
    }
}
