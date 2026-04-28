<?php

declare(strict_types=1);

namespace App\Modules\PurchaseHub\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\PurchaseHub\Application\Services\PurchaseHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PurchaseHubOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseHubService $service,
    ) {}

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

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->getOrders();
        if ($data === null) {
            return response()->json(['data' => null], 502);
        }

        return response()->json(['data' => $data]);
    }

    public function show(string $id): JsonResponse
    {
        $data = $this->service->getOrder($id);
        if ($data === null) {
            return response()->json(['data' => null], 502);
        }

        return response()->json(['data' => $data]);
    }
}
