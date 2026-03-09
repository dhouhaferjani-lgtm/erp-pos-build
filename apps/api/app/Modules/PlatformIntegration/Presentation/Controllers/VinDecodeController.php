<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class VinDecodeController extends Controller
{
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
