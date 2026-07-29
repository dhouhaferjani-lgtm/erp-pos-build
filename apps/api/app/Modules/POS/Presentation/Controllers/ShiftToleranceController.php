<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\POS\Domain\Shift;
use App\Modules\Treasury\Application\DTOs\TolerancePaymentReceiptDTO;
use App\Modules\Treasury\Application\Services\PaymentToleranceQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/pos/shifts/{id}/tolerance-receipts
 *
 * Receipt-level drill-down behind the Z-report `tolerance_summary` block: one
 * row per receipt in the shift that incurred a non-zero tolerance write-off,
 * so an auditor can trace a shift total back to the sales that produced it.
 *
 * Read-only. Scoping mirrors `ShiftController::receipts()` exactly — authorize
 * `pos.operate_terminal`, then reject a shift whose terminal belongs to another
 * company. `pos_shifts` carries no `company_id` of its own, which is why the
 * check goes through the terminal.
 *
 * The row set comes from `PaymentToleranceQueryService`, whose window is
 * `posted_at BETWEEN shift.opened_at AND (closed_at OR now())`. On an OPEN
 * shift the upper bound is wall-clock now, so successive calls can return
 * different rows; callers needing a reproducible cutoff must wait for the
 * shift to close.
 */
final class ShiftToleranceController extends Controller
{
    public function __construct(
        private readonly PaymentToleranceQueryService $queryService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(string $id): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::with('terminal')->findOrFail($id);

        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        return response()->json([
            'data' => array_map(
                static fn (TolerancePaymentReceiptDTO $dto): array => $dto->toArray(),
                $this->queryService->receiptsWithToleranceForShift($id),
            ),
        ]);
    }
}
