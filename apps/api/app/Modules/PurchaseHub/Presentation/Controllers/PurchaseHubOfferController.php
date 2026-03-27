<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PurchaseHub\Application\Services\PurchaseHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseHubOfferController extends Controller
{
    public function __construct(
        private readonly PurchaseHubService $service,
    ) {}

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
