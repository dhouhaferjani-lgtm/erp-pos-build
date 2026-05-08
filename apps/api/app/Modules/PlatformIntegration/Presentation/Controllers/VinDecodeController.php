<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VinDecodeController extends Controller
{
    #[CrossTenantRoute(reason: 'STUB shape: returns a hardcoded "not yet configured" placeholder response. ZERO DB access, ZERO service calls, ZERO side-effects. Phase 4 will implement the actual VIN decode flow against a CompanyContext-aware service. Today the body is a verifiable noop, so no tenant-scoping is needed; the attribute marks the method as classified.')]
    public function decode(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'max:50'],
            'query_type' => ['required', 'string', 'in:vin,plate'],
            'country_code' => ['required', 'string', 'size:2'],
        ]);

        // VIN decoding will be implemented in Phase 4
        // For now, return a structured placeholder indicating the feature is pending
        return response()->json([
            'data' => [
                'status' => 'not_found',
                'vehicle' => null,
                'platform_match' => null,
                'wmi_hint' => null,
                'message' => 'VIN decoding is not yet configured for this country.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    #[CrossTenantRoute(reason: 'STUB shape: returns 501 Not Implemented placeholder. ZERO DB access, ZERO service calls. Phase 4 placeholder mirroring decode(); no tenant context needed today.')]
    public function confirmMatch(Request $request): JsonResponse
    {
        $request->validate([
            'vin' => ['required', 'string', 'size:17'],
            'platform_vehicle_type' => ['required', 'string', 'in:pc,cv,mtb'],
            'platform_vehicle_id' => ['required', 'uuid'],
        ]);

        // Will be implemented in Phase 4
        return response()->json([
            'data' => [
                'saved' => false,
                'message' => 'VIN match confirmation is not yet implemented.',
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ], 501);
    }
}
