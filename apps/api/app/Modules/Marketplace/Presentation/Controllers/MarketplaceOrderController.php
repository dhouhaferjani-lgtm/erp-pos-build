<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Marketplace\Application\DTOs\MarketplaceOrderData;
use App\Modules\Marketplace\Application\Services\MarketplaceOrderService;
use App\Modules\Marketplace\Domain\Enums\MarketplaceOrderStatus;
use App\Modules\Marketplace\Domain\Models\MarketplaceOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class MarketplaceOrderController extends Controller
{
    public function __construct(
        private readonly MarketplaceOrderService $orderService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * POST /api/v1/marketplace/orders
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.listing_id' => ['required', 'string', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);

        $company = $this->companyContext->requireCompany();

        try {
            $order = $this->orderService->createOrder($validated['items'], $company);
        } catch (\DomainException $e) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => $e->getMessage(),
                    'errors' => [],
                ],
            ], 422);
        }

        return response()->json([
            'data' => MarketplaceOrderData::fromModel($order),
        ], 201);
    }

    /**
     * GET /api/v1/marketplace/orders
     */
    public function index(): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $orders = MarketplaceOrder::query()
            ->forBuyer($company->tenant_id, $company->id)
            ->with(['seller', 'lines'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $orders->getCollection()->map(
            fn (MarketplaceOrder $order): MarketplaceOrderData => MarketplaceOrderData::fromModel($order)
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * GET /api/v1/marketplace/orders/{id}
     */
    public function show(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $order = MarketplaceOrder::query()
            ->forBuyer($company->tenant_id, $company->id)
            ->with(['seller', 'lines'])
            ->findOrFail($id);

        return response()->json([
            'data' => MarketplaceOrderData::fromModel($order),
        ]);
    }

    /**
     * POST /api/v1/marketplace/orders/{id}/cancel
     */
    public function cancel(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $order = MarketplaceOrder::query()
            ->forBuyer($company->tenant_id, $company->id)
            ->findOrFail($id);

        if (! in_array($order->order_status, [MarketplaceOrderStatus::Pending, MarketplaceOrderStatus::Confirmed], true)) {
            return response()->json([
                'error' => [
                    'code' => 'BUSINESS_ERROR',
                    'message' => 'Only pending or confirmed orders can be cancelled',
                    'errors' => [],
                ],
            ], 422);
        }

        $this->orderService->cancelOrder($order);

        return response()->json([
            'data' => MarketplaceOrderData::fromModel($order->fresh()), /** @phpstan-ignore-line */
        ]);
    }
}
