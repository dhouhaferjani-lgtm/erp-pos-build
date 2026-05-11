<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PurchaseHub\Application\Services\PurchaseHubService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseHubOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseHubService $service,
    ) {}

    #[CrossTenantRoute(reason: 'PurchaseHub outbound integration: PurchaseHubService::placeOrder makes a tenant-tagged HTTP POST to the upstream PurchaseHub platform via PlatformHttpClient (auto-stamps X-Tenant-Id + X-Company-Id from CompanyContext). Response is the upstream-created order assigned to the originating tenant.')]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaign_id' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.campaign_item_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ]);

        $data = $this->service->placeOrder($validated);
        if ($data === null) {
            return response()->json([
                'error' => [
                    'code' => 'ORDER_FAILED',
                    'message' => 'Failed to place order',
                ],
            ], 502);
        }

        return response()->json(['data' => $data], 201);
    }

    #[CrossTenantRoute(reason: 'PurchaseHub outbound integration: PurchaseHubService::getOrders makes a tenant-tagged HTTP GET via PlatformHttpClient; upstream returns the originating tenant\'s orders only (filtered by X-Tenant-Id header).')]
    public function index(Request $request): JsonResponse
    {
        $data = $this->service->getOrders();
        if ($data === null) {
            return response()->json(['data' => null], 502);
        }

        return response()->json(['data' => $data]);
    }

    #[CrossTenantRoute(reason: 'PurchaseHub outbound integration: PurchaseHubService::getOrder($id) makes a tenant-tagged HTTP GET via PlatformHttpClient; upstream platform validates that the order id belongs to the originating tenant (tenant filter via X-Tenant-Id header).')]
    public function show(string $id): JsonResponse
    {
        $data = $this->service->getOrder($id);
        if ($data === null) {
            return response()->json(['data' => null], 502);
        }

        return response()->json(['data' => $data]);
    }
}
