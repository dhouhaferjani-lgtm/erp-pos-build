<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Services\ChannelOrderIngestService;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;

final class ChannelOrderController extends Controller
{
    public function __construct(
        private readonly ChannelOrderIngestService $ingestService,
        private readonly ChannelService $channelService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(string $channelId): JsonResponse
    {
        $channel = $this->channelService->findChannel($channelId, $this->companyContext->requireCompanyId());

        return response()->json([
            'data' => ChannelOrder::query()->where('channel_id', $channel->id)->latest('received_at')->get(),
        ]);
    }

    public function promote(string $channelId, string $orderId): JsonResponse
    {
        $channel = $this->channelService->findChannel($channelId, $this->companyContext->requireCompanyId());
        ChannelOrder::query()->where('channel_id', $channel->id)->findOrFail($orderId);

        $document = $this->ingestService->promote($orderId);

        return response()->json(['data' => $document]);
    }
}
