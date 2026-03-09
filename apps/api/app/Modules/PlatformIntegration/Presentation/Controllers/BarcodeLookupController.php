<?php

declare(strict_types=1);

namespace App\Modules\PlatformIntegration\Presentation\Controllers;

use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class BarcodeLookupController extends Controller
{
    public function __construct(
        private readonly BarcodeLookupService $barcodeLookupService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'barcode' => ['required', 'string', 'max:100'],
        ]);

        $result = $this->barcodeLookupService->lookup((string) $request->input('barcode'));

        return response()->json([
            'data' => $result,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }
}
