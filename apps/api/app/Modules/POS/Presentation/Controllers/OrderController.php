<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\OrderManagementService;
use App\Modules\POS\Application\Services\OrderToReceiptService;
use App\Modules\POS\Domain\Enums\ConsumptionMode;
use App\Modules\POS\Domain\Enums\OrderStatus;
use App\Modules\POS\Domain\Order;
use App\Modules\POS\Presentation\Requests\AddOrderLineRequest;
use App\Modules\POS\Presentation\Requests\CreateOrderRequest;
use App\Modules\POS\Presentation\Requests\ModifyOrderLineRequest;
use App\Modules\POS\Presentation\Resources\OrderLineResource;
use App\Modules\POS\Presentation\Resources\OrderResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * POS Order Controller
 *
 * Handles order lifecycle: creation, line management, kitchen workflow, and closing.
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderManagementService $orderManagementService,
        private readonly OrderToReceiptService $orderToReceiptService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * List orders with filters and pagination.
     *
     * GET /api/v1/pos/orders
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $query = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['lines', 'terminal', 'table.floor']);

        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }

        if ($request->filled('shift_id')) {
            $query->where('shift_id', $request->input('shift_id'));
        }

        if ($request->filled('status')) {
            $statusValue = $request->input('status');
            $status = OrderStatus::tryFrom((string) $statusValue);
            if ($status !== null) {
                $query->byStatus($status);
            }
        }

        if ($request->has('active') && filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN)) {
            $query->active();
        }

        $orders = $query->orderByDesc('opened_at')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => OrderResource::collection($orders->items()),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'timestamp' => now()->toISOString(),
            ],
        ]);
    }

    /**
     * Show a single order with lines.
     *
     * GET /api/v1/pos/orders/{id}
     */
    public function show(string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        /** @var Order $order */
        $order = Order::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->with(['lines', 'terminal', 'table.floor'])
            ->findOrFail($id);

        return response()->json([
            'data' => new OrderResource($order),
        ]);
    }

    /**
     * Create a new order.
     *
     * POST /api/v1/pos/orders
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $validated = $request->validated();

            $consumptionMode = isset($validated['consumption_mode'])
                ? ConsumptionMode::from($validated['consumption_mode'])
                : null;

            $order = $this->orderManagementService->createOrder(
                terminalId: $validated['terminal_id'],
                shiftId: $validated['shift_id'],
                tableId: $validated['table_id'] ?? null,
                partnerId: $validated['partner_id'] ?? null,
                customerName: $validated['customer_name'] ?? null,
                consumptionMode: $consumptionMode,
                notes: $validated['notes'] ?? null,
            );

            return response()->json([
                'data' => new OrderResource($order),
            ], 201);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ORDER_CREATION_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Add a line to an order.
     *
     * POST /api/v1/pos/orders/{id}/lines
     */
    public function addLine(AddOrderLineRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $validated = $request->validated();

            $line = $this->orderManagementService->addLine(
                orderId: $id,
                productId: $validated['product_id'],
                quantity: (string) $validated['quantity'],
                unitPrice: (string) $validated['unit_price'],
                taxRate: (string) $validated['tax_rate'],
                discountAmount: isset($validated['discount_amount']) ? (string) $validated['discount_amount'] : null,
                modifiers: $validated['modifiers'] ?? null,
                specialInstructions: $validated['special_instructions'] ?? null,
            );

            // Reload the order to return updated totals.
            // Round-5 — anchor on BOTH tenant_id and company_id so the
            // response resource cannot echo a foreign tenant's order even
            // if the service-tier scope had been bypassed.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $order */
            $order = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with('lines')
                ->findOrFail($id);

            return response()->json([
                'data' => [
                    'line' => new OrderLineResource($line),
                    'order' => new OrderResource($order),
                ],
            ], 201);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'ADD_LINE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_LINE_DATA',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Modify a line on an order.
     *
     * PATCH /api/v1/pos/orders/{id}/lines/{lineId}
     */
    public function modifyLine(ModifyOrderLineRequest $request, string $id, string $lineId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $validated = $request->validated();

            $line = $this->orderManagementService->modifyLine(
                orderId: $id,
                lineId: $lineId,
                quantity: isset($validated['quantity']) ? (string) $validated['quantity'] : null,
                discountAmount: isset($validated['discount_amount']) ? (string) $validated['discount_amount'] : null,
                modifiers: $validated['modifiers'] ?? null,
                specialInstructions: $validated['special_instructions'] ?? null,
            );

            // Round-5 — anchor on BOTH tenant_id and company_id.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $order */
            $order = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with('lines')
                ->findOrFail($id);

            return response()->json([
                'data' => [
                    'line' => new OrderLineResource($line),
                    'order' => new OrderResource($order),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'MODIFY_LINE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Remove a line from an order.
     *
     * DELETE /api/v1/pos/orders/{id}/lines/{lineId}
     */
    public function removeLine(string $id, string $lineId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $this->orderManagementService->removeLine(
                orderId: $id,
                lineId: $lineId,
            );

            // Round-5 — anchor on BOTH tenant_id and company_id.
            $tenantId = $this->companyContext->requireTenantId();
            $companyId = $this->companyContext->requireCompanyId();
            /** @var Order $order */
            $order = Order::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->with('lines')
                ->findOrFail($id);

            return response()->json([
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'REMOVE_LINE_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'LINE_NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        }
    }

    /**
     * Send an order to the kitchen.
     *
     * POST /api/v1/pos/orders/{id}/send-to-kitchen
     */
    public function sendToKitchen(string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $order = $this->orderManagementService->sendToKitchen($id);

            return response()->json([
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SEND_TO_KITCHEN_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Close an order and convert to receipt.
     *
     * **§14.2 — NEW-SALE AUTHORING RETIRED.** The
     * `POST /api/v1/pos/orders/{id}/close` route is dispositioned to return
     * HTTP 410 Gone with `NEW_SALE_AUTHORING_RETIRED` at the route-level
     * closure (see `routes_orders.php`). The downstream chain — this
     * controller method → `OrderManagementService::closeOrder` →
     * `OrderToReceiptService::convertToReceipt` →
     * `ReceiptCreationService::createReceipt` — is one of the §14.2
     * server-authoring paths the device-authority rebuild replaces. The
     * rest of order CRUD/lines/kitchen/cancel routes in `routes_orders.php`
     * are untouched. Do not re-wire this method to a route for new-sale
     * authoring without coordinating with Task 30's CI gate.
     */
    public function close(string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $order = $this->orderManagementService->closeOrder($id, $this->orderToReceiptService);

            return response()->json([
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CLOSE_ORDER_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ORDER_DATA',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * Cancel an order.
     *
     * POST /api/v1/pos/orders/{id}/cancel
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        try {
            $reason = $request->input('reason');

            $order = $this->orderManagementService->cancelOrder(
                $id,
                is_string($reason) ? $reason : null,
            );

            return response()->json([
                'data' => new OrderResource($order),
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-4 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below.
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'CANCEL_ORDER_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }
}
