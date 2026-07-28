<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Services\ShiftManagementService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Presentation\Requests\SyncShiftCloseRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * POS Sync Controller
 *
 * Handles shift synchronization between offline Tauri POS terminals and the server.
 */
final class SyncController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly ShiftManagementService $shiftManagementService,
    ) {}

    /**
     * Sync close a shift that was closed offline.
     *
     * POST /api/v1/pos/shifts/{id}/sync-close
     *
     * Accepts shift close data with an offline `closed_at` timestamp.
     */
    public function syncCloseShift(SyncShiftCloseRequest $request, string $id): JsonResponse
    {
        Gate::authorize('pos.manage_shifts');

        $companyId = $this->companyContext->getCompanyId();

        $shift = Shift::with('terminal')
            ->whereHas('terminal', function (Builder $q) use ($companyId): void {
                $q->whereRaw('company_id = ?', [$companyId]);
            })
            ->findOrFail($id);

        // Device-authoritative (v3) terminals author SESSION_CLOSE locally;
        // pos_shifts is closed by the projection. The legacy sync-close mirror
        // is retired with a hard 409 (Decision 3; mirrors ZReportSyncController).
        if ((int) ($shift->terminal->fiscal_schema_version ?? 2) >= 3) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_DEVICE_AUTHORITY_REQUIRED',
                    'message' => sprintf(
                        'Shift sync-close is retired for device-authoritative terminal %s. The device authors SESSION_CLOSE locally; pos_shifts is a projection.',
                        $shift->terminal_id,
                    ),
                ],
            ], 409);
        }

        if (! $shift->isOpen()) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_NOT_OPEN',
                    'message' => 'Shift is not open and cannot be closed.',
                ],
            ], 422);
        }

        /** @var User $user */
        $user = Auth::user();

        $closedAt = Carbon::parse($request->validated('closed_at'));

        $closedShift = $this->shiftManagementService->closeShift(
            shift: $shift,
            actualCash: $request->validated('actual_cash'),
            closedBy: $user,
            closedAt: $closedAt,
        );

        return response()->json([
            'data' => [
                'id' => $closedShift->id,
                'status' => $closedShift->status->value,
                'expected_cash' => $closedShift->expected_cash,
                'actual_cash' => $closedShift->actual_cash,
                'variance' => $closedShift->variance,
                'closed_at' => $closedShift->closed_at?->toIso8601String(),
            ],
        ]);
    }
}
