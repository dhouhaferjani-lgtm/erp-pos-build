<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Services\ChannelOrderIngestService;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Enums\ChannelOrderStatus;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Channel\Domain\Models\ChannelOrder;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ChannelOrderController extends Controller
{
    public function __construct(
        private readonly ChannelOrderIngestService $ingestService,
        private readonly ChannelService $channelService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Aggregate channel orders across ALL of the company's channels.
     *
     * Same row shape as the per-channel index() plus the eager-loaded
     * `channel` relation so the e-commerce orders view can show the
     * channel name per row.
     */
    public function indexAll(Request $request): JsonResponse
    {
        /** @var array{status?: string, channel_id?: string, per_page?: int} $validated */
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::enum(ChannelOrderStatus::class)],
            // 'uuid' rejects non-UUID input with 422 BEFORE it reaches a
            // PG uuid-typed where() (which would 500 on malformed input).
            'channel_id' => ['sometimes', 'string', 'uuid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $companyId = $this->companyContext->requireCompanyId();

        $query = ChannelOrder::query()
            ->whereIn(
                'channel_id',
                Channel::query()->where('company_id', $companyId)->select('id'),
            )
            ->with('channel')
            ->latest('received_at');

        if (isset($validated['status'])) {
            $query->where('status', ChannelOrderStatus::from($validated['status']));
        }

        if (isset($validated['channel_id'])) {
            $query->where('channel_id', $validated['channel_id']);
        }

        $orders = $query->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

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
