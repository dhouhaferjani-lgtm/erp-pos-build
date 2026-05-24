<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Models\ChannelSyncOperation;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChannelSyncOperationController extends Controller
{
    public function __construct(
        private readonly ChannelService $channelService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request, string $channelId): JsonResponse
    {
        $channel = $this->channelService->findChannel($channelId, $this->companyContext->requireCompanyId());
        $query = ChannelSyncOperation::query()->where('channel_id', $channel->id)->latest();
        $status = $request->query('status');

        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return response()->json(['data' => $query->get()]);
    }
}
