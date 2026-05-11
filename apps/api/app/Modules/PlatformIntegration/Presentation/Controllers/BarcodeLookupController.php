<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BarcodeLookupController extends Controller
{
    public function __construct(
        private readonly BarcodeLookupService $barcodeLookupService,
    ) {}

    #[CrossTenantRoute(reason: 'PlatformIntegration outbound: BarcodeLookupService::lookup makes a tenant-tagged HTTP GET to the upstream Synerivia barcode catalog via PlatformHttpClient (auto-stamps X-Tenant-Id + X-Company-Id from CompanyContext per the locked api.platform-integration cluster). Controller is a thin pass-through; tenant identity flows through the service layer\'s PlatformHttpClient injection.')]
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
            'vertical' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        $result = $this->barcodeLookupService->lookup(
            (string) $request->input('barcode'),
            $request->input('vertical') !== null ? (string) $request->input('vertical') : null,
        );

        return response()->json([
            'data' => $result,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
