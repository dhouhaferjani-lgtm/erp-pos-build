<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Company\Services\LocationContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Enums\MovementReason;
use App\Modules\Inventory\Domain\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Presentation\Requests\AdjustStockRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class StockMovementController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $stockService,
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        // Both predicates required: tenant_id alone leaks same-tenant
        // cross-company movement history when a user with multi-company
        // membership selects company A but the query returns company B
        // rows. (api.inventory Codex round-1 Finding 1.)
        $query = StockMovement::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->with(['product', 'location', 'user']);

        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->has('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if ($request->has('movement_type')) {
            $query->where('movement_type', $request->input('movement_type'));
        }

        $movements = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $movements->map(fn (StockMovement $movement) => $this->formatMovement($movement)),
        ]);
    }

    public function receive(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'uuid'],
            'location_id' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'quantity.regex' => 'The quantity must have at most 4 decimal places.',
        ]);

        // Validate user has access to this location
        $companyId = $this->companyContext->requireCompanyId();
        $this->locationContext->validateLocationAccess(
            $validated['location_id'],
            $companyId,
            $user
        );

        /** @var numeric-string $quantity */
        $quantity = (string) $validated['quantity'];

        $movement = $this->stockService->receive(
            productId: $validated['product_id'],
            locationId: $validated['location_id'],
            quantity: $quantity,
            reference: $validated['reference'],
            userId: $user->id,
        );

        $movement->load(['product', 'location', 'user']);

        return response()->json([
            'data' => $this->formatMovement($movement),
        ], 201);
    }

    public function issue(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'uuid'],
            'location_id' => ['required', 'string', 'uuid'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [
            'quantity.regex' => 'The quantity must have at most 4 decimal places.',
        ]);

        // Validate user has access to this location
        $companyId = $this->companyContext->requireCompanyId();
        $this->locationContext->validateLocationAccess(
            $validated['location_id'],
            $companyId,
            $user
        );

        /** @var numeric-string $quantity */
        $quantity = (string) $validated['quantity'];

        try {
            $movement = $this->stockService->issue(
                productId: $validated['product_id'],
                locationId: $validated['location_id'],
                quantity: $quantity,
                reference: $validated['reference'],
                userId: $user->id,
            );

            $movement->load(['product', 'location', 'user']);

            return response()->json([
                'data' => $this->formatMovement($movement),
            ], 201);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => $e->getMessage(),
                    'details' => [
                        'product_id' => $e->productId,
                        'location_id' => $e->locationId,
                        'requested' => $e->requested,
                        'available' => $e->available,
                    ],
                ],
            ], 422);
        }
    }

    public function transfer(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'product_id' => ['required', 'string', 'uuid'],
            'from_location_id' => ['required', 'string', 'uuid'],
            'to_location_id' => ['required', 'string', 'uuid', 'different:from_location_id'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'regex:/^\d+(\.\d{1,4})?$/'],
            'reference' => ['required', 'string', 'max:255'],
        ], [
            'quantity.regex' => 'The quantity must have at most 4 decimal places.',
        ]);

        // Validate user has access to BOTH source and destination locations
        $companyId = $this->companyContext->requireCompanyId();
        $this->locationContext->validateLocationAccess(
            $validated['from_location_id'],
            $companyId,
            $user
        );
        $this->locationContext->validateLocationAccess(
            $validated['to_location_id'],
            $companyId,
            $user
        );

        /** @var numeric-string $quantity */
        $quantity = (string) $validated['quantity'];

        try {
            $this->stockService->transfer(
                productId: $validated['product_id'],
                fromLocationId: $validated['from_location_id'],
                toLocationId: $validated['to_location_id'],
                quantity: $quantity,
                reference: $validated['reference'],
                userId: $user->id,
            );

            return response()->json([
                'message' => 'Transfer completed successfully',
            ]);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => $e->getMessage(),
                    'details' => [
                        'product_id' => $e->productId,
                        'location_id' => $e->locationId,
                        'requested' => $e->requested,
                        'available' => $e->available,
                    ],
                ],
            ], 422);
        }
    }

    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();

        // Validate user has access to this location
        $companyId = $this->companyContext->requireCompanyId();
        $this->locationContext->validateLocationAccess(
            $validated['location_id'],
            $companyId,
            $user
        );

        $reasonCode = MovementReason::from($validated['reason_code']);

        /** @var numeric-string $newQuantity */
        $newQuantity = (string) $validated['new_quantity'];

        $movement = $this->stockService->adjust(
            productId: $validated['product_id'],
            locationId: $validated['location_id'],
            newQuantity: $newQuantity,
            // Free-text note → reference; fall back to the reason label when omitted.
            reason: $validated['reason'] ?? $reasonCode->label(),
            userId: $user->id,
            reasonCode: $reasonCode,
        );

        $movement->load(['product', 'location', 'user']);

        return response()->json([
            'data' => $this->formatMovement($movement),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatMovement(StockMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'product_id' => $movement->product_id,
            'product_name' => $movement->product->name,
            'location_id' => $movement->location_id,
            'location_name' => $movement->location->name,
            'movement_type' => $movement->movement_type->value,
            'quantity' => $movement->quantity,
            'quantity_before' => $movement->quantity_before,
            'quantity_after' => $movement->quantity_after,
            'reference' => $movement->reference,
            'notes' => $movement->notes,
            'user_id' => $movement->user_id,
            'user_name' => $movement->user?->name,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }
}
