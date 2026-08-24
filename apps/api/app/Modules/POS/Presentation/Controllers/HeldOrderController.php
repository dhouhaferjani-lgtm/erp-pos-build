<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\HeldOrderService;
use App\Modules\POS\Domain\Exceptions\HeldOrderDiscardRefusedException;
use App\Modules\POS\Domain\Exceptions\HeldOrderRecallConflictException;
use App\Modules\POS\Domain\HeldOrder;
use App\Modules\POS\Presentation\Requests\HoldOrderRequest;
use App\Modules\POS\Presentation\Resources\HeldOrderResource;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

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

            /** @var User $user */
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
                    'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
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
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
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
        $tenantId = $this->companyContext->requireTenantId();
        $companyId = $this->companyContext->requireCompanyId();

        $heldOrder = HeldOrder::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        return response()->json([
            'data' => new HeldOrderResource($heldOrder),
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
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
        // Q-8 — `terminal_id` is OPTIONAL on the wire: no shipped client sends
        // a body on this route today (`apps/web/src/features/pos/api/heldOrderApi.ts`
        // and `apps/pos/src/api/holdApi.ts` both POST bare), so requiring it
        // would 404 every live recall. When a caller does declare the till it
        // is operating, the service scopes the lookup to it the same way
        // `listHeldOrders` has always been scoped.
        $request->validate([
            'terminal_id' => ['nullable', 'string', 'uuid'],
        ]);

        $terminalId = $request->filled('terminal_id') ? (string) $request->input('terminal_id') : null;

        try {
            $heldOrder = $this->heldOrderService->recallOrder($id, $terminalId);

            return response()->json([
                'data' => new HeldOrderResource($heldOrder),
                'meta' => [
                    'timestamp' => now()->toIso8601String(),
                    'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            // Round-5 — let cross-tenant scope misses bubble to Laravel's
            // default 404 handler instead of being swallowed as 422 by the
            // broader RuntimeException catch below (ModelNotFoundException
            // extends RuntimeException).
            throw $e;
        } catch (HeldOrderRecallConflictException $e) {
            // Q-8 — a concurrent till consumed the basket between our read and
            // our write. 409, not 422: the request was well-formed, the
            // resource moved underneath it, and the client's remedy is to
            // refresh the held-orders list.
            return response()->json([
                'error' => [
                    'code' => 'HELD_ORDER_RECALL_CONFLICT',
                    'message' => $e->getMessage(),
                ],
            ], 409);
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
        /** @var User $user */
        $user = Auth::user();

        try {
            $this->heldOrderService->discardOrder($id, $user->id);
        } catch (HeldOrderDiscardRefusedException $e) {
            // Q-8 — a recalled basket is the only trace of what was rung up.
            return response()->json([
                'error' => [
                    'code' => 'HELD_ORDER_DISCARD_REFUSED',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }

        return response()->json([
            'data' => [
                'success' => true,
            ],
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-Id', (string) Str::uuid()),
            ],
        ]);
    }
}
