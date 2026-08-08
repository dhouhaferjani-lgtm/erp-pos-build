<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\RepositoryAdjustmentIntent;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentAmountBelowCurrencyPrecisionException;
use App\Modules\Treasury\Domain\Exceptions\AdjustmentToleranceAccountMissingException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\DTOs\RepositoryAdjustmentResult;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Facades\DB;

/**
 * Document + GL + movement, atomically — the orchestration for one repository
 * (cash) adjustment.
 *
 * Housed alongside {@see RepositoryTransferService} (the V3 gate's recorded
 * target shape) and extracted verbatim out of
 * `RepositoryAdjustmentController::store()`, which is now a thin HTTP adapter
 * that translates the two typed refusals into their existing 422 responses.
 *
 * See {@see RepositoryAdjustmentServiceInterface} for the contract, the
 * ordering guarantees and the "no CompanyContext" rule.
 */
final readonly class RepositoryAdjustmentService implements RepositoryAdjustmentServiceInterface
{
    public function __construct(
        private GeneralLedgerService $generalLedger,
        private TreasuryMovementServiceInterface $movementService,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    public function post(RepositoryAdjustmentIntent $intent): RepositoryAdjustmentResult
    {
        // Tenant+company scope — Treasury is company-scoped. firstOrFail (not
        // findOrFail on an unscoped query) so a foreign repository id is a miss,
        // never a cross-tenant write.
        /** @var PaymentRepository $repository */
        $repository = PaymentRepository::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('company_id', $intent->companyId)
            ->whereKey($intent->repositoryId)
            ->firstOrFail();

        if ($repository->gl_account_id === null) {
            throw new \DomainException(
                "Cannot post an adjustment: payment repository '{$repository->name}' ({$repository->code}) ".
                'has no linked GL account. Assign a GL account to this repository first.'
            );
        }

        /** @var User $user */
        $user = User::query()
            ->where('tenant_id', $intent->tenantId)
            ->whereKey($intent->userId)
            ->firstOrFail();

        // Audit fix 4 (K2): defense in depth for any tenant whose chart of
        // accounts predates the TN/FR seeder fix (or a custom chart that never
        // assigned these purposes) — check BEFORE the transaction so a missing
        // 658/758 purpose is a typed, graceful refusal rather than letting
        // Account::findByPurposeOrFail's bare RuntimeException escape
        // createRepositoryAdjustmentJournalEntry() as an HTTP 500.
        // findByPurposeOrFail's throwing semantics for every other GL caller are
        // untouched.
        $requiredPurpose = $intent->direction === MovementDirection::Out
            ? SystemAccountPurpose::PaymentToleranceExpense
            : SystemAccountPurpose::PaymentToleranceIncome;

        if (! $this->generalLedger->hasAccountForPurpose($intent->companyId, $requiredPurpose)) {
            throw new AdjustmentToleranceAccountMissingException($requiredPurpose, $intent->companyId);
        }

        // Gate C1 — ROUND ONCE, AT THE BOUNDARY (rule 19), then feed the ONE
        // normalized value to all three artifacts: the document, the journal
        // entry and the movement. Previously only the document was scaled while
        // the JE and the movement stored the raw request string, so on any
        // currency with scale < 3 the justifying document stated a different
        // amount than the fact it justifies. Scale comes from the repository's
        // OWN currency via the constructor-injected resolver (never a bare
        // no-arg getScale() — this service runs in listener/queue contexts too);
        // bcformatStrict truncates, which is why this is the single rounding
        // point and every downstream consumer receives the already-normalized
        // string.
        $scale = $this->scaleResolver->getScale($repository->currency);
        /** @var numeric-string $amount */
        $amount = CurrencyScale::bcformatStrict($intent->amount, $scale);

        // Gate C2 — an amount below the currency's smallest unit normalizes to
        // zero. Left unchecked it reaches the pgsql-only CHECK (amount > 0) as
        // SQLSTATE 23514 → 500, rolling back the whole adjustment; the sqlite
        // suite structurally cannot see that (CLAUDE.md rule 20). Refuse here,
        // BEFORE any insert.
        if (bccomp($amount, '0', $scale) <= 0) {
            throw new AdjustmentAmountBelowCurrencyPrecisionException(
                $intent->amount,
                $repository->currency,
                $scale,
            );
        }

        return DB::transaction(function () use ($intent, $repository, $user, $amount): RepositoryAdjustmentResult {
            $adjustmentId = $intent->adjustmentId;

            /** @var string $glAccountId */
            $glAccountId = $repository->gl_account_id;

            // DOCUMENT FIRST (DPA lane V3): mint the justifying
            // `repository_adjustments` row BEFORE the GL entry and the movement,
            // so the `source_id` both of them carry addresses a row that already
            // exists. `firstOrCreate` keyed on the id — the very discriminator
            // the movement's idempotency key is built from
            // (`adjustment:{id}:{leg}`) — means a replay of the same adjustment
            // id resolves to the existing document instead of inserting a second
            // one, mirroring record()'s own replay contract. `pos_shift_id` is
            // therefore write-once at INSERT and is never backfilled onto an
            // existing document (V3 gate).
            $adjustment = RepositoryAdjustment::query()->firstOrCreate(
                ['id' => $adjustmentId],
                [
                    'tenant_id' => $intent->tenantId,
                    'company_id' => $intent->companyId,
                    'payment_repository_id' => $repository->id,
                    'direction' => $intent->direction,
                    // Already normalized once at the boundary above — stored
                    // verbatim, so the document is byte-identical with the JE
                    // and the movement BY CONSTRUCTION, not by coincidence.
                    'amount' => $amount,
                    'currency' => $repository->currency,
                    'reason_code' => $intent->reasonCode,
                    'reason_text' => $intent->reasonText,
                    'pos_shift_id' => $intent->posShiftId,
                    'created_by' => $user->id,
                ],
            );

            // Gate I1 — on a REPLAY (the document already exists) reuse the
            // journal entry this document already justifies instead of posting a
            // second one. The partial unique index added by
            // 2026_08_08_120100_unique_journal_entries_source_repository_adjustment.php
            // is the database-level backstop for the same invariant.
            /** @var ?JournalEntry $entry */
            $entry = $adjustment->wasRecentlyCreated ? null : $adjustment->journalEntry;

            if ($entry === null) {
                // GL post FIRST (postEntryNow takes the company advisory lock),
                // then the movement port (which takes the repository row lock
                // second) — global lock order (spine BLOCKER-1).
                $entry = $this->generalLedger->createRepositoryAdjustmentJournalEntry(
                    companyId: $intent->companyId,
                    tenantId: $intent->tenantId,
                    adjustmentId: $adjustmentId,
                    repositoryGlAccountId: $glAccountId,
                    direction: $intent->direction,
                    amount: $amount,
                    date: $intent->occurredAt ?? now(),
                    user: $user,
                    description: "Repository adjustment ({$intent->reasonCode->value}): {$intent->reasonText}",
                    currencyCode: $repository->currency,
                );
            }

            $result = $this->movementService->record(new MovementIntent(
                repositoryId: $repository->id,
                tenantId: $intent->tenantId,
                companyId: $intent->companyId,
                direction: $intent->direction,
                amount: $amount,
                currency: $repository->currency,
                sourceType: MovementSourceType::Adjustment,
                sourceId: $adjustmentId,
                idempotencyLeg: $intent->idempotencyLeg,
                journalEntryId: $entry->id,
                occurredAt: $intent->occurredAt,
                reasonCode: $intent->reasonCode,
                reversesMovementId: null,
                createdBy: $user->id,
                notes: $intent->reasonText,
                // Server-computed, interactive semantics in BOTH callers: the
                // manual endpoint is a click, and the G3 shift-variance listener
                // derives the amount on the server from a committed cash count.
                // Neither is an offline device replay, so a frozen repository
                // must refuse rather than record-and-alert.
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

            return new RepositoryAdjustmentResult(
                adjustmentId: $adjustmentId,
                journalEntryId: $entry->id,
                movementId: $result->movementId,
                balanceAfter: $result->balanceAfter,
                ordinal: $result->ordinal,
                wasIdempotentHit: $result->wasIdempotentHit,
                normalizedAmount: $amount,
            );
        });
    }
}
