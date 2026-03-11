<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Application\Services\HeldOrderService;
use App\Modules\POS\Presentation\Requests\HoldOrderRequest;
use App\Modules\POS\Presentation\Resources\HeldOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * POS Held Order Controller
 *
 * Handles cart parking (hold), listing, recalling, and discarding operations.
 */
final class HeldOrderController extends Controller
{
    public function __construct(
        private readonly HeldOrderService $heldOrderService,
        private readonly CompanyContext $companyContext,
    ) {}

    /**
     * Hold the current cart as a new held order.
     *
     * POST /api/v1/pos/held-orders
     */
    public function store(HoldOrderRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            /** @var \App\Modules\Identity\Domain\User $user */
            $user = Auth::user();

            $heldOrder = $this->heldOrderService->holdOrder(
                terminalId: $validated['terminal_id'],
                shiftId: $validated['shift_id'],
                cashierId: $user->id,
                cartSnapshot: $validated['cart_snapshot'],
                label: $validated['label'] ?? null,
                expiresInMinutes: $validated['expires_in_minutes'] ?? 240,
            );

            return response()->json([
                'data' => new HeldOrderResource($heldOrder),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
                ],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_HOLD_DATA',
                    'message' => $e->getMessage(),
                ],
            ], 400);
        }
    }

    /**
     * List held orders for a terminal.
     *
     * GET /api/v1/pos/held-orders?terminal_id=xxx&shift_id=yyy
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_id' => ['required', 'string', 'uuid'],
            'shift_id' => ['nullable', 'string', 'uuid'],
        ]);

        $terminalId = (string) $request->input('terminal_id');
        $shiftId = $request->filled('shift_id') ? (string) $request->input('shift_id') : null;

        $heldOrders = $this->heldOrderService->listHeldOrders($terminalId, $shiftId);

        return response()->json([
            'data' => HeldOrderResource::collection($heldOrders),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
            ],
        ]);
    }

    /**
     * Show a single held order.
     *
     * GET /api/v1/pos/held-orders/{id}
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();

        $heldOrder = \App\Modules\POS\Domain\HeldOrder::where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'data' => new HeldOrderResource($heldOrder),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
            ],
        ]);
    }

    /**
     * Recall a held order back to the cart.
     *
     * POST /api/v1/pos/held-orders/{id}/recall
     */
    public function recall(Request $request, string $id): JsonResponse
    {
        try {
            $heldOrder = $this->heldOrderService->recallOrder($id);

            return response()->json([
                'data' => new HeldOrderResource($heldOrder),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'RECALL_FAILED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Discard (delete) a held order.
     *
     * DELETE /api/v1/pos/held-orders/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->heldOrderService->discardOrder($id);

        return response()->json([
            'data' => [
                'success' => true,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) \Illuminate\Support\Str::uuid()),
            ],
        ]);
    }
}
