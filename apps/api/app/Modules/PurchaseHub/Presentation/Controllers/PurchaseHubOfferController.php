<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PurchaseHub\Application\Services\PurchaseHubService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseHubOfferController extends Controller
{
    public function __construct(
        private readonly PurchaseHubService $service,
    ) {}

    #[CrossTenantRoute(reason: 'PurchaseHub outbound integration: PurchaseHubService::getOffers() makes a tenant-tagged HTTP GET to the upstream PurchaseHub platform via PlatformHttpClient (which auto-stamps X-Tenant-Id + X-Company-Id from CompanyContext per the locked api.platform-integration cluster). Response is tenant-filtered upstream; controller is a thin pass-through with cache.')]
    public function index(Request $request): JsonResponse
    {
        $data = $this->service->getOffers();
        if ($data === null) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 502);
        }

        return response()->json([
            'data' => $data,
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }

    #[CrossTenantRoute(reason: 'PurchaseHub outbound integration: PurchaseHubService::getOffer($id) makes a tenant-tagged HTTP GET to the upstream PurchaseHub platform via PlatformHttpClient. Same shape as index() — tenant identity flows through PlatformHttpClient\'s X-Tenant-Id + X-Company-Id headers.')]
    public function show(Request $request, string $id): JsonResponse
    {
        $data = $this->service->getOffer($id);
        if ($data === null) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()->toIso8601String()],
            ], 502);
        }

        return response()->json([
            'data' => $data,
            'meta' => ['timestamp' => now()->toIso8601String()],
        ]);
    }
}
