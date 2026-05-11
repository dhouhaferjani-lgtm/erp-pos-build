<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\OrderManagementService;
use App\Modules\POS\Domain\Enums\OrderLineStatus;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Presentation\Requests\UpdateLineStatusRequest;
use App\Modules\POS\Presentation\Resources\OrderResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class KitchenDisplayController
{
    public function __construct(
        private readonly OrderManagementService $orderService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Get active kitchen orders (sent_to_kitchen + ready), sorted by sent_at ASC.
     */
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('pos.operate_terminal');
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $orders = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereIn('status', [
                OrderStatus::SentToKitchen,
                OrderStatus::Ready,
            ])
            ->with(['lines', 'table.floor'])
            ->orderBy('sent_at', 'asc')
            ->get();

        return OrderResource::collection($orders);
    }

    /**
     * Update a single line status (Sent→Preparing→Ready or →Cancelled).
     */
    public function updateLineStatus(
        UpdateLineStatusRequest $request,
        string $orderId,
        string $lineId,
    ): JsonResponse {
        Gate::authorize('pos.operate_terminal');
        try {
            $newStatus = OrderLineStatus::from($request->validated('status'));

            $line = $this->orderService->updateLineStatus($orderId, $lineId, $newStatus);

            // Round-5 — anchor on BOTH tenant_id and company_id.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $order */
            $order = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with(['lines', 'table.floor'])
                ->findOrFail($orderId);

            return response()->json([
                'data' => [
                    'line' => [
                        'id' => $line->id,
                        'status' => $line->status->value,
                        'prepared_at' => $line->prepared_at?->toISOString(),
                    ],
                    'order' => (new OrderResource($order))->resolve(),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Bump an order — mark all Sent/Preparing lines as Ready.
     */
    public function bump(string $orderId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');
        try {
            $order = $this->orderService->bumpOrder($orderId);

            // Round-5 — anchor on BOTH tenant_id and company_id.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $freshOrder */
            $freshOrder = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with(['lines', 'table.floor'])
                ->findOrFail($order->id);

            return response()->json([
                'data' => (new OrderResource($freshOrder))->resolve(),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Mark an order as served.
     */
    public function served(string $orderId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');
        try {
            $order = $this->orderService->markOrderServed($orderId);

            // Round-5 — anchor on BOTH tenant_id and company_id.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $freshOrder */
            $freshOrder = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with(['lines', 'table.floor'])
                ->findOrFail($order->id);

            return response()->json([
                'data' => (new OrderResource($freshOrder))->resolve(),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
