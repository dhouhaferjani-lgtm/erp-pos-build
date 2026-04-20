<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Services\CashDrawerService;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Presentation\Requests\RecordDepositRequest;
use App\Modules\POS\Presentation\Requests\RecordPayoutRequest;
use App\Modules\POS\Presentation\Resources\CashDrawerOperationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Controller for cash drawer operations.
 *
 * Handles:
 * - Recording deposits to safe
 * - Recording payouts (refunds, petty cash)
 * - Getting shift operations history
 * - Getting current drawer balance
 */
final class CashDrawerController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly CashDrawerService $cashDrawerService,
    ) {}

    /**
     * Record cash deposit to safe
     *
     * POST /api/v1/pos/cash-drawer/deposit
     */
    public function deposit(RecordDepositRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        /** @var Shift $shift */
        $shift = Shift::findOrFail($request->validated('shift_id'));

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        // Verify shift is open
        if (! $shift->isOpen()) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_NOT_OPEN',
                    'message' => 'Cannot record deposit on closed shift',
                ],
            ], 409);
        }

        /** @var User $user */
        $user = $request->user();

        $operation = $this->cashDrawerService->recordDeposit(
            $shift,
            $request->validated('amount'),
            $user,
            $request->validated('reason')
        );

        return response()->json([
            'data' => CashDrawerOperationResource::make($operation),
        ], 201);
    }

    /**
     * Record cash payout
     *
     * POST /api/v1/pos/cash-drawer/payout
     */
    public function payout(RecordPayoutRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        /** @var Shift $shift */
        $shift = Shift::findOrFail($request->validated('shift_id'));

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        // Verify shift is open
        if (! $shift->isOpen()) {
            return response()->json([
                'error' => [
                    'code' => 'SHIFT_NOT_OPEN',
                    'message' => 'Cannot record payout on closed shift',
                ],
            ], 409);
        }

        /** @var User $user */
        $user = $request->user();

        $operation = $this->cashDrawerService->recordPayout(
            $shift,
            $request->validated('amount'),
            $user,
            $request->validated('reason')
        );

        return response()->json([
            'data' => CashDrawerOperationResource::make($operation),
        ], 201);
    }

    /**
     * Get all operations for a shift
     *
     * GET /api/v1/pos/cash-drawer/{shiftId}/operations
     */
    public function operations(string $shiftId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::findOrFail($shiftId);

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        $operations = $this->cashDrawerService->getShiftOperations($shift);

        return response()->json([
            'data' => CashDrawerOperationResource::collection($operations),
        ]);
    }

    /**
     * Get current drawer balance for shift
     *
     * GET /api/v1/pos/cash-drawer/{shiftId}/balance
     */
    public function balance(string $shiftId): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $shift = Shift::findOrFail($shiftId);

        // Verify shift belongs to current company
        if ($shift->terminal->company_id !== $this->companyContext->getCompanyId()) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Shift does not belong to your company',
                ],
            ], 403);
        }

        $expectedCash = $this->cashDrawerService->calculateExpectedCash($shift);

        // Get operation summaries by type
        $openingAmount = $this->cashDrawerService->getTotalByType($shift, 'OPENING');
        $salesAmount = $this->cashDrawerService->getTotalByType($shift, 'SALE');
        $refundsAmount = $this->cashDrawerService->getTotalByType($shift, 'REFUND');
        $depositsAmount = $this->cashDrawerService->getTotalByType($shift, 'DEPOSIT');
        $payoutsAmount = $this->cashDrawerService->getTotalByType($shift, 'PAYOUT');

        return response()->json([
            'data' => [
                'shift_id' => $shift->id,
                'shift_number' => $shift->shift_number,
                'status' => $shift->status->value,
                'expected_cash' => $expectedCash,
                'breakdown' => [
                    'opening' => $openingAmount,
                    'sales' => $salesAmount,
                    'refunds' => $refundsAmount,
                    'deposits' => $depositsAmount,
                    'payouts' => $payoutsAmount,
                ],
            ],
        ]);
    }
}
