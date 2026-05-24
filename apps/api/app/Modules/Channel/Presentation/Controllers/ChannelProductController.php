<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChannelProductController extends Controller
{
    public function __construct(
        private readonly ChannelService $channelService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function publish(Request $request, string $channelId, string $productId): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['nullable', 'uuid'],
            'overrides' => ['array'],
        ]);

        $mapping = $this->channelService->publishProduct(
            $channelId,
            $productId,
            $validated['variant_id'] ?? null,
            $validated['overrides'] ?? [],
            $this->companyContext->requireCompanyId(),
        );

        return response()->json(['data' => $mapping]);
    }

    public function unpublish(Request $request, string $channelId, string $productId): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['nullable', 'uuid'],
        ]);

        $this->channelService->unpublishProduct($channelId, $productId, $validated['variant_id'] ?? null, $this->companyContext->requireCompanyId());

        return response()->json(['data' => ['ok' => true]]);
    }
}
