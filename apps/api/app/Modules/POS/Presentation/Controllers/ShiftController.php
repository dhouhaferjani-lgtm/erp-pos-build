<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Exceptions\ShiftAlreadyOpenException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Requests\CloseShiftRequest;
use App\Modules\POS\Presentation\Requests\OpenShiftRequest;
use App\Modules\POS\Presentation\Resources\ShiftResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller for POS shift management.
 *
 * Handles:
 * - Opening shifts with opening balance
 * - Closing shifts with cash count
 * - Getting current shift status
 * - Listing shift history
 */
final class ShiftController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ShiftManagementService $shiftManagementService,
    ) {}

    /**
     * Open a new shift
     *
     * POST /api/v1/pos/shifts/open
     */
    public function open(OpenShiftRequest $request): JsonResponse
    {
        $terminal = Terminal::findOrFail($request->validated('terminal_id'));

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        try {
            $shift = $this->shiftManagementService->openShift(
                $terminal,
                $request->user(),
                $request->validated('opening_cash')
            );

            return response()->json([
                'data' => ShiftResource::make($shift),
            ], 201);
        } catch (ShiftAlreadyOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_ALREADY_OPEN',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * Close current shift
     *
     * POST /api/v1/pos/shifts/{id}/close
     */
    public function close(string $id, CloseShiftRequest $request): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        try {
            $closedShift = $this->shiftManagementService->closeShift(
                $shift,
                $request->validated('actual_cash'),
                $request->user()
            );

            return response()->json([
                'data' => ShiftResource::make($closedShift),
            ]);
        } catch (ShiftNotOpenException $e) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_NOT_OPEN',
                    'message' => $e->getMessage(),
                ],
            ], 409);
        }
    }

    /**
     * Get current open shift for terminal
     *
     * GET /api/v1/pos/shifts/current/{terminalId}
     */
    public function current(string $terminalId): JsonResponse
    {
        $terminal = Terminal::findOrFail($terminalId);

        // Verify terminal belongs to current company
        if ($terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Terminal does not belong to your company',
                ],
            ], 403);
        }

        $shift = $this->shiftManagementService->getCurrentShift($terminal);

        if (! $shift) {
            return response()->json([
                'data' => null,
            ]);
        }

        return response()->json([
            'data' => ShiftResource::make($shift),
        ]);
    }

    /**
     * Get shift details
     *
     * GET /api/v1/pos/shifts/{id}
     */
    public function show(string $id): JsonResponse
    {
        $shift = Shift::with(['terminal', 'cashier', 'closedByUser'])
            ->findOrFail($id);

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        return response()->json([
            'data' => ShiftResource::make($shift),
        ]);
    }

    /**
     * List shifts with filters
     *
     * GET /api/v1/pos/shifts
     */
    public function index(Request $request): JsonResponse
    {
        $query = Shift::query()
            ->whereHas('terminal', function ($q) {
                $q->where('company_id', $this->companyContext->getCompanyId());
            })
            ->with(['terminal', 'cashier', 'closedByUser']);

        // Filter by terminal
        if ($request->filled('terminal_id')) {
            $query->where('terminal_id', $request->input('terminal_id'));
        }

        // Filter by cashier
        if ($request->filled('cashier_id')) {
            $query->where('cashier_id', $request->input('cashier_id'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by date range
        if ($request->filled('from_date')) {
            $query->where('opened_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('opened_at', '<=', $request->input('to_date'));
        }

        $shifts = $query->orderByDesc('opened_at')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data' => ShiftResource::collection($shifts->items()),
            'meta' => [
                'current_page' => $shifts->currentPage(),
                'last_page' => $shifts->lastPage(),
                'per_page' => $shifts->perPage(),
                'total' => $shifts->total(),
            ],
        ]);
    }
}
