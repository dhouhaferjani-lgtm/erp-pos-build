<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentAmountBelowCurrencyPrecisionException;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentToleranceAccountMissingException;
use App\Modules\Treasury\Presentation\Requests\AdjustRepositoryRequest;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Gated manual repository (cash) adjustment endpoint — Treasury spine Task
 * 23. A count-variance / correction / theft-loss / other manual movement
 * that moves the repository balance via the write port AND posts a balanced
 * GL entry (cash ↔ variance account), all inside one transaction so the
 * movement always carries a non-null journal_entry_id (recon-readiness).
 *
 * DPA lane V3: the adjustment UUID that the JE and the movement both carry as
 * their `source_id` addresses a real `repository_adjustments` document, minted
 * first inside the same transaction.
 *
 * DPA lane G3 (V3-gate blocking precondition): the orchestration itself now
 * lives in {@see RepositoryAdjustmentServiceInterface}, so the POS shift-close
 * cash-variance listener reuses it rather than duplicating it. This controller
 * is the HTTP adapter: authorization + scope + the two translated 422 refusals
 * the endpoint has always returned.
 */
final class RepositoryAdjustmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly RepositoryAdjustmentServiceInterface $adjustmentService,
    ) {}

    public function store(AdjustRepositoryRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();

        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($id)) {
            abort(404);
        }

        $intent = new RepositoryAdjustmentIntent(
            repositoryId: $id,
            tenantId: (string) $company->tenant_id,
            companyId: $company->id,
            direction: MovementDirection::from((string) $validated['direction']),
            amount: (string) $validated['amount'],
            reasonCode: MovementReasonCode::from((string) $validated['reason_code']),
            reasonText: (string) $validated['reason_text'],
            userId: $user->id,
            adjustmentId: (string) Str::uuid(),
        );

        try {
            $result = $this->adjustmentService->post($intent);
        } catch (AdjustmentToleranceAccountMissingException $e) {
            // Audit fix 4 (K2): a missing 658/758 purpose is a graceful,
            // translated 422 rather than a 500.
            return response()->json([
                'error' => __('messages.treasury.adjustment_tolerance_account_missing', [
                    'purpose' => $e->purpose->label(),
                ]),
            ], 422);
        } catch (AdjustmentAmountBelowCurrencyPrecisionException $e) {
            // Gate C2: sub-smallest-unit amounts are refused before any insert.
            return response()->json([
                'error' => __('messages.treasury.adjustment_amount_below_currency_precision', [
                    'amount' => $e->requestedAmount,
                    'currency' => $e->currency,
                    'decimals' => (string) $e->scale,
                ]),
            ], 422);
        }

        return response()->json([
            'message' => __('messages.created', ['resource' => 'Adjustment']),
            'data' => [
                'movement_id' => $result->movementId,
                'balance_after' => $result->balanceAfter,
                'ordinal' => $result->ordinal,
                'idempotent_replay' => $result->wasIdempotentHit,
            ],
        ], 201);
    }
}
