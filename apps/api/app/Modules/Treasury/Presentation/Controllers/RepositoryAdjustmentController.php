<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\MovementResult;
use App\Modules\Treasury\Application\Services\TreasuryMovementService;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Presentation\Requests\AdjustRepositoryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gated manual repository (cash) adjustment endpoint — Treasury spine Task
 * 23. A count-variance / correction / theft-loss / other manual movement
 * that moves the repository balance via the write port AND posts a balanced
 * GL entry (cash ↔ variance account), all inside one transaction so the
 * movement always carries a non-null journal_entry_id (recon-readiness).
 */
final class RepositoryAdjustmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedger,
        private readonly TreasuryMovementService $movementService,
    ) {}

    public function store(AdjustRepositoryRequest $request, string $id): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $companyId = $company->id;
        $tenantId = $company->tenant_id;

        $validated = $request->validated();
        $direction = MovementDirection::from((string) $validated['direction']);
        $reasonCode = MovementReasonCode::from((string) $validated['reason_code']);
        /** @var numeric-string $amount */
        $amount = (string) $validated['amount'];
        $reasonText = (string) $validated['reason_text'];

        /** @var User $user */
        $user = $request->user();

        // Tenant+company scope — Treasury is company-scoped.
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if ($repository->gl_account_id === null) {
            throw new \DomainException(
                "Cannot post an adjustment: payment repository '{$repository->name}' ({$repository->code}) ".
                'has no linked GL account. Assign a GL account to this repository first.'
            );
        }

        $result = DB::transaction(function () use (
            $repository,
            $direction,
            $amount,
            $reasonCode,
            $reasonText,
            $tenantId,
            $companyId,
            $user,
        ): MovementResult {
            $adjustmentId = (string) Str::uuid();

            /** @var string $glAccountId */
            $glAccountId = $repository->gl_account_id;

            // GL post FIRST (postEntryNow takes the company advisory lock),
            // then the movement port (which takes the repository row lock
            // second) — global lock order (spine BLOCKER-1).
            $entry = $this->generalLedger->createRepositoryAdjustmentJournalEntry(
                companyId: $companyId,
                tenantId: $tenantId,
                adjustmentId: $adjustmentId,
                repositoryGlAccountId: $glAccountId,
                direction: $direction,
                amount: $amount,
                date: now(),
                user: $user,
                description: "Repository adjustment ({$reasonCode->value}): {$reasonText}",
                currencyCode: $repository->currency,
            );

            return $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $tenantId,
                companyId: $companyId,
                direction: $direction,
                amount: $amount,
                currency: $repository->currency,
                sourceType: MovementSourceType::Adjustment,
                sourceId: $adjustmentId,
                idempotencyLeg: 'main',
                journalEntryId: $entry->id,
                occurredAt: null,
                reasonCode: $reasonCode,
                reversesMovementId: null,
                createdBy: $user->id,
                notes: $reasonText,
                allowWhileFrozen: false,
            ));
        });

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
