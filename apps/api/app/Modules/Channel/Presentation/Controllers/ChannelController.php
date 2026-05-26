<?php

declare(strict_types=1);

namespace App\Modules\Channel\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Channel\Application\Commands\CreateChannelCommand;
use App\Modules\Channel\Application\Services\AdapterRegistry;
use App\Modules\Channel\Application\Services\ChannelService;
use App\Modules\Channel\Domain\Models\Channel;
use App\Modules\Company\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ChannelController extends Controller
{
    public function __construct(
        private readonly ChannelService $channelService,
        private readonly AdapterRegistry $adapterRegistry,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        return response()->json([
            'data' => [
                'channels' => Channel::query()->where('company_id', $companyId)->latest()->get(),
                'registered_adapters' => $this->adapterRegistry->listRegistered()->values(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'adapter_type' => ['required', 'string', 'max:100'],
            'metadata' => ['array'],
        ]);

        $channel = $this->channelService->create(new CreateChannelCommand(
            companyId: $this->companyContext->requireCompanyId(),
            name: (string) $validated['name'],
            adapterType: (string) $validated['adapter_type'],
            metadata: $validated['metadata'] ?? [],
        ));

        return response()->json(['data' => $channel], 201);
    }

    public function testConnection(string $id): JsonResponse
    {
        return response()->json(['data' => $this->channelService->testConnection($id, $this->companyContext->requireCompanyId())]);
    }

    public function resync(string $id): JsonResponse
    {
        return response()->json([
            'data' => $this->channelService->manualResync(
                $id,
                $this->companyContext->requireCompanyId(),
                $this->companyContext->requireTenantId(),
            ),
        ]);
    }
}
