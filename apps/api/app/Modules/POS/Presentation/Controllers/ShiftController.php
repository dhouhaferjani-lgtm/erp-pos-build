<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Exceptions\ShiftAlreadyOpenException;
use App\Modules\POS\Domain\Exceptions\ShiftNotOpenException;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Domain\ZReport;
use App\Modules\POS\Presentation\Requests\CloseShiftRequest;
use App\Modules\POS\Presentation\Requests\OpenShiftRequest;
use App\Modules\POS\Presentation\Resources\ShiftResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

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
        Gate::authorize('pos.manage_shifts');

        $terminal = Terminal::byCode($request->validated('terminal_code'))
            ->where('company_id', $this->companyContext->getCompanyId())
            ->firstOrFail();

        // Device-authoritative (v3) terminals mint + author their own shift
        // locally (SESSION_OPEN); pos_shifts is a projection of the synced
        // fiscal events. The REST open is retired with a hard 409 so a stray
        // web/admin call can never fork a competing shift (mirrors
        // ZReportSyncController's Z_SESSION_DEVICE_AUTHORITY_REQUIRED).
        if ((int) ($terminal->fiscal_schema_version ?? 2) >= 3) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_DEVICE_AUTHORITY_REQUIRED',
                    'message' => sprintf(
                        'Shift open is retired for device-authoritative terminal %s. The device authors SESSION_OPEN locally; pos_shifts is a projection.',
                        $terminal->id,
                    ),
                ],
            ], 409);
        }

        try {
            $cashierId = $request->validated('cashier_id');
            /** @var User $currentUser */
            $currentUser = $request->user();
            $cashier = $cashierId
                ? User::findOrFail($cashierId)
                : $currentUser;
            /** @var User $cashier */
            $shift = $this->shiftManagementService->openShift(
                $terminal,
                $cashier,
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
        Gate::authorize('pos.manage_shifts');

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

        // Device-authoritative (v3) terminals author SESSION_CLOSE + Z_REPORT
        // locally; pos_shifts is closed by the projection. The REST close is
        // retired with a hard 409 so a stray web/admin call can never close a
        // device shift out from under the device (Decision 3).
        if ((int) ($shift->terminal->fiscal_schema_version ?? 2) >= 3) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_DEVICE_AUTHORITY_REQUIRED',
                    'message' => sprintf(
                        'Shift close is retired for device-authoritative terminal %s. The device authors SESSION_CLOSE locally; pos_shifts is a projection.',
                        $shift->terminal_id,
                    ),
                ],
            ], 409);
        }

        // Require a Z report before the shift can be closed (BG10)
        $zReport = ZReport::query()->where('shift_id', $shift->id)->first();
        if ($zReport === null) {
            return response()->json([
                'error' => [
                    'code' => 'Z_REPORT_REQUIRED',
                    'message' => 'A Z report must be generated before the shift can be closed.',
                ],
            ], 422);
        }

        try {
            /** @var User $user */
            $user = $request->user();
            $closedShift = $this->shiftManagementService->closeShift(
                $shift,
                $request->validated('actual_cash'),
                $user
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
     * GET /api/v1/pos/shifts/current/{terminalCode}
     */
    public function current(string $terminalCode): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $terminal = Terminal::byCode($terminalCode)
            ->where('company_id', $this->companyContext->getCompanyId())
            ->firstOrFail();

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
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::with(['terminal', 'cashier', 'closedBy'])
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
        Gate::authorize('pos.operate_terminal');

        $query = Shift::query()
            ->whereHas('terminal', function (Builder $q) {
                $q->whereRaw('company_id = ?', [$this->companyContext->requireCompanyId()]);
            })
            ->with(['terminal', 'cashier', 'closedBy']);

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

    /**
     * List receipts for a shift (transaction history)
     *
     * GET /api/v1/pos/shifts/{id}/receipts
     */
    public function receipts(string $id, Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::with('terminal')->findOrFail($id);

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        $receipts = Receipt::where('terminal_id', $shift->terminal_id)
            ->where('cashier_id', $shift->cashier_id)
            ->where('posted_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, function ($query) use ($shift) {
                $query->where('posted_at', '<=', $shift->closed_at);
            })
            ->with(['lines.product', 'payments', 'vatDetails'])
            ->orderByDesc('posted_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $receipts->items(),
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }
}
