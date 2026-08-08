<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
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
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Modules\Treasury\Presentation\Requests\AdjustRepositoryRequest;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gated manual repository (cash) adjustment endpoint — Treasury spine Task
 * 23. A count-variance / correction / theft-loss / other manual movement
 * that moves the repository balance via the write port AND posts a balanced
 * GL entry (cash ↔ variance account), all inside one transaction so the
 * movement always carries a non-null journal_entry_id (recon-readiness).
 *
 * DPA lane V3: the adjustment UUID that the JE and the movement both carry as
 * their `source_id` now addresses a real `repository_adjustments` document,
 * minted first inside the same transaction. See
 * {@see RepositoryAdjustment} for why no FK runs
 * from movements back to that table.
 */
final class RepositoryAdjustmentController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly GeneralLedgerService $generalLedger,
        private readonly TreasuryMovementService $movementService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
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

        if (! Str::isUuid($id)) {
            abort(404);
        }

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

        // Audit fix 4 (K2): defense in depth for any tenant whose chart of
        // accounts predates the TN/FR seeder fix (or a custom chart that never
        // assigned these purposes) — check BEFORE the transaction so a missing
        // 658/758 purpose is a graceful, translated 422 rather than letting
        // Account::findByPurposeOrFail's bare RuntimeException escape
        // createRepositoryAdjustmentJournalEntry() as an HTTP 500. Scoped to
        // this endpoint only; findByPurposeOrFail's throwing semantics for
        // every other GL caller are untouched.
        $requiredPurpose = $direction === MovementDirection::Out
            ? SystemAccountPurpose::PaymentToleranceExpense
            : SystemAccountPurpose::PaymentToleranceIncome;

        if (! $this->generalLedger->hasAccountForPurpose($companyId, $requiredPurpose)) {
            return response()->json([
                'error' => __('messages.treasury.adjustment_tolerance_account_missing', [
                    'purpose' => $requiredPurpose->label(),
                ]),
            ], 422);
        }

        // Gate C1 — ROUND ONCE, AT THE BOUNDARY (rule 19), then feed the ONE
        // normalized value to all three artifacts: the document, the journal
        // entry and the movement. Previously only the document was scaled while
        // the JE and the movement stored the raw request string, so on any
        // currency with scale < 3 (the FormRequest's regex ceiling, and the
        // column's own decimal(15,3)) the justifying document stated a
        // different amount than the fact it justifies — reproduced on Postgres
        // as doc 25.000 vs movement/JE 25.005 for an EUR repository. Scale comes
        // from the repository's OWN currency via the constructor-injected
        // resolver (never a bare no-arg getScale()); bcformatStrict truncates,
        // which is why this is the single rounding point and every downstream
        // consumer receives the already-normalized string.
        $scale = $this->scaleResolver->getScale($repository->currency);
        /** @var numeric-string $amount */
        $amount = CurrencyScale::bcformatStrict($amount, $scale);

        // Gate C2 — an amount below the currency's smallest unit (EUR 0.005,
        // or ANY sub-unit amount for a scale-0 currency such as XOF/JPY) passes
        // `required|numeric|gt:0|regex:{1,3}` and then normalizes to zero. Left
        // unchecked it reached the pgsql-only CHECK (amount > 0) as SQLSTATE
        // 23514 → HTTP 500, rolling back the whole adjustment — a failure the
        // sqlite suite structurally cannot see (CLAUDE.md rule 20). Refuse it
        // here, BEFORE any insert, as a graceful translated 422.
        if (bccomp($amount, '0', $scale) <= 0) {
            return response()->json([
                'error' => __('messages.treasury.adjustment_amount_below_currency_precision', [
                    'amount' => (string) $validated['amount'],
                    'currency' => $repository->currency,
                    'decimals' => (string) $scale,
                ]),
            ], 422);
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

            // DOCUMENT FIRST (DPA lane V3): mint the justifying
            // `repository_adjustments` row BEFORE the GL entry and the movement,
            // so the `source_id` both of them carry addresses a row that already
            // exists. `firstOrCreate` keyed on the id — the very discriminator
            // the movement's idempotency key is built from
            // (`adjustment:{id}:main`) — means a replay of the same adjustment
            // id resolves to the existing document instead of inserting a
            // second one, mirroring record()'s own replay contract.
            $adjustment = RepositoryAdjustment::query()->firstOrCreate(
                ['id' => $adjustmentId],
                [
                    'tenant_id' => $tenantId,
                    'company_id' => $companyId,
                    'payment_repository_id' => $repository->id,
                    'direction' => $direction,
                    // Already normalized once at the boundary above — stored
                    // verbatim, so the document is byte-identical with the JE
                    // and the movement BY CONSTRUCTION, not by coincidence.
                    'amount' => $amount,
                    'currency' => $repository->currency,
                    'reason_code' => $reasonCode,
                    'reason_text' => $reasonText,
                    'created_by' => $user->id,
                ],
            );

            // Gate I1 — on a REPLAY (the document already exists) reuse the
            // journal entry this document already justifies instead of posting a
            // second one. Previously the GL post ran unconditionally, ahead of
            // record()'s idempotent short-circuit, so a replayed adjustment id
            // minted an extra POSTED repository_adjustment entry with no
            // compensating movement and nothing pointing at it. The partial
            // unique index added by
            // 2026_08_08_120100_unique_journal_entries_source_repository_adjustment.php
            // is the database-level backstop for the same invariant.
            $entry = $adjustment->wasRecentlyCreated ? null : $adjustment->journalEntry;

            if ($entry === null) {
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
            }

            $result = $this->movementService->record(new MovementIntent(
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

            // Close the loop: the document links back to the JE and the movement
            // it justifies. Only on the write that actually created it — a
            // replay must leave the original linkage untouched rather than
            // repoint the document at a re-derived journal entry.
            if ($adjustment->wasRecentlyCreated) {
                $adjustment->forceFill([
                    'journal_entry_id' => $entry->id,
                    'movement_id' => $result->movementId,
                ])->save();
            }

            return $result;
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
