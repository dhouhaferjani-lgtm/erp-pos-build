<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Inventory\Application\Services\StockReservationService;
use App\Modules\Inventory\Domain\Enums\ReleaseReason;
use App\Modules\Inventory\Domain\Enums\ReservationSource;
use App\Modules\Inventory\Domain\StockReservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * API controller for stock reservation management.
 *
 * Provides endpoints for:
 * - Listing reservations with filters
 * - Creating manual reservations
 * - Releasing reservations
 * - Getting reservation breakdown for a product/location
 */
class StockReservationController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly StockReservationService $reservationService,
    ) {}

    /**
     * List stock reservations with optional filters.
     *
     * Query parameters:
     * - product_id: Filter by product
     * - location_id: Filter by location
     * - source_type: Filter by source type (sales_order, ecommerce_cart, etc.)
     * - source_id: Filter by source document ID
     * - status: active, expired, released (default: active)
     * - per_page: Items per page (default: 20)
     */
    public function index(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $query = StockReservation::query()
            ->where('company_id', $company->id)
            ->with(['product', 'location', 'createdBy']);

        // Filter by status
        $status = $request->input('status', 'active');
        match ($status) {
            'active' => $query->active(),
            'expired' => $query->expired(),
            'released' => $query->whereNotNull('released_at'),
            default => null,
        };

        // Filter by product
        if ($request->has('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        // Filter by location
        if ($request->has('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        // Filter by source type
        if ($request->has('source_type')) {
            $query->where('source_type', $request->input('source_type'));
        }

        // Filter by source ID
        if ($request->has('source_id')) {
            $query->where('source_id', $request->input('source_id'));
        }

        $perPage = (int) $request->input('per_page', 20);
        $reservations = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $reservations->getCollection()->map(fn (StockReservation $reservation) => [
            'id' => $reservation->id,
            'product_id' => $reservation->product_id,
            'product_name' => $reservation->product?->name,
            'location_id' => $reservation->location_id,
            'location_name' => $reservation->location?->name,
            'quantity' => $reservation->quantity,
            'source_type' => $reservation->source_type->value,
            'source_type_label' => $reservation->source_type->label(),
            'source_id' => $reservation->source_id,
            'source_line_id' => $reservation->source_line_id,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'expired_at' => $reservation->expired_at?->toIso8601String(),
            'released_at' => $reservation->released_at?->toIso8601String(),
            'release_reason' => $reservation->release_reason?->value,
            'release_reason_label' => $reservation->release_reason?->label(),
            'is_active' => $reservation->isActive(),
            'is_expired' => $reservation->isExpired(),
            'time_until_expiry' => $reservation->getTimeUntilExpiry(),
            'created_by' => $reservation->createdBy?->name,
            'created_at' => $reservation->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $reservations->currentPage(),
                'last_page' => $reservations->lastPage(),
                'per_page' => $reservations->perPage(),
                'total' => $reservations->total(),
            ],
        ]);
    }

    /**
     * Get a single reservation by ID.
     */
    public function show(string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $reservation = StockReservation::query()
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->with(['product', 'location', 'createdBy', 'releasedBy'])
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $reservation->id,
                'product_id' => $reservation->product_id,
                'product_name' => $reservation->product?->name,
                'location_id' => $reservation->location_id,
                'location_name' => $reservation->location?->name,
                'quantity' => $reservation->quantity,
                'source_type' => $reservation->source_type->value,
                'source_type_label' => $reservation->source_type->label(),
                'source_id' => $reservation->source_id,
                'source_line_id' => $reservation->source_line_id,
                'expires_at' => $reservation->expires_at?->toIso8601String(),
                'expired_at' => $reservation->expired_at?->toIso8601String(),
                'released_at' => $reservation->released_at?->toIso8601String(),
                'released_by' => $reservation->releasedBy?->name,
                'release_reason' => $reservation->release_reason?->value,
                'release_reason_label' => $reservation->release_reason?->label(),
                'priority' => $reservation->priority,
                'notes' => $reservation->notes,
                'is_active' => $reservation->isActive(),
                'is_expired' => $reservation->isExpired(),
                'time_until_expiry' => $reservation->getTimeUntilExpiry(),
                'total_value' => $reservation->getTotalValue(),
                'created_by' => $reservation->createdBy?->name,
                'created_at' => $reservation->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Create a manual stock reservation.
     *
     * This is typically used for manual holds, quality checks, or transfers.
     */
    public function store(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'quantity' => ['required', 'numeric', 'min:0.0001'],
            'source_type' => ['required', Rule::enum(ReservationSource::class)],
            'source_id' => ['required', 'uuid'],
            'source_line_id' => ['nullable', 'uuid'],
            'priority' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $reservation = $this->reservationService->reserve(
                company: $company,
                productId: $validated['product_id'],
                locationId: $validated['location_id'],
                quantity: (string) $validated['quantity'],
                sourceType: ReservationSource::from($validated['source_type']),
                sourceId: $validated['source_id'],
                sourceLineId: $validated['source_line_id'] ?? null,
                priority: $validated['priority'] ?? 0,
                notes: $validated['notes'] ?? null,
            );

            return response()->json([
                'data' => [
                    'id' => $reservation->id,
                    'product_id' => $reservation->product_id,
                    'location_id' => $reservation->location_id,
                    'quantity' => $reservation->quantity,
                    'source_type' => $reservation->source_type->value,
                    'source_id' => $reservation->source_id,
                    'expires_at' => $reservation->expires_at?->toIso8601String(),
                    'created_at' => $reservation->created_at?->toIso8601String(),
                ],
                'message' => 'Reservation created successfully',
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => [
                    'code' => 'INSUFFICIENT_STOCK',
                    'message' => $e->getMessage(),
                ],
            ], 422);
        }
    }

    /**
     * Release a reservation manually.
     */
    public function release(Request $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $reservation = StockReservation::query()
            ->where('company_id', $company->id)
            ->where('id', $id)
            ->firstOrFail();

        if (! $reservation->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_RELEASED',
                    'message' => 'Reservation is already released',
                ],
            ], 422);
        }

        $this->reservationService->release(
            reservation: $reservation,
            reason: ReleaseReason::ManualRelease,
            releasedBy: (string) auth()->id(),
        );

        return response()->json([
            'message' => 'Reservation released successfully',
        ]);
    }

    /**
     * Get reservation breakdown for a product at a location.
     *
     * Shows all active reservations grouped by source.
     */
    public function breakdown(Request $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validate([
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
        ]);

        $reservations = StockReservation::query()
            ->where('company_id', $company->id)
            ->where('product_id', $validated['product_id'])
            ->where('location_id', $validated['location_id'])
            ->active()
            ->with(['createdBy'])
            ->orderBy('created_at')
            ->get();

        $data = $reservations->map(fn (StockReservation $reservation) => [
            'id' => $reservation->id,
            'quantity' => $reservation->quantity,
            'source_type' => $reservation->source_type->value,
            'source_type_label' => $reservation->source_type->label(),
            'source_id' => $reservation->source_id,
            'expires_at' => $reservation->expires_at?->toIso8601String(),
            'time_until_expiry' => $reservation->getTimeUntilExpiry(),
            'created_by' => $reservation->createdBy?->name,
            'created_at' => $reservation->created_at?->toIso8601String(),
        ]);

        $totalReserved = $reservations->sum('quantity');

        return response()->json([
            'data' => $data,
            'meta' => [
                'total_reservations' => $reservations->count(),
                'total_quantity_reserved' => $totalReserved,
            ],
        ]);
    }
}
