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
use App\Modules\Treasury\Domain\Exceptions\RepositoryNotSeededException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryAdjustment;
use App\Modules\Treasury\Domain\RepositoryMovement;
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
 *
 * ── B-2: THE GO-LIVE SEEDING GUARD AND ITS SHIFT-VARIANCE CARVE-OUT ─────────
 * post() refuses an adjustment on a repository Treasury has never seen hold
 * money ({@see RepositoryNotSeededException}, predicate on isNeverSeeded()),
 * because the only operator-facing path to that state is someone typing their
 * opening cash float into "Adjust balance" — which books it as REVENUE (7580).
 *
 * The refusal is scoped to `$intent->posShiftId === null`, and that carve-out
 * is load-bearing, not cosmetic. There are exactly TWO callers of post():
 *
 *   - RepositoryAdjustmentController::store() — the interactive endpoint. Never
 *     sets `posShiftId`. This is the misuse surface, and the ONLY one.
 *   - PostShiftCashVarianceAdjustment — the POS shift-close listener. ALWAYS
 *     sets `posShiftId: $event->shiftId` (it is also the listener's document-id
 *     and idempotency-leg discriminator, so it cannot go missing without
 *     breaking that listener's replay contract first).
 *
 * The edge case the carve-out exists for is REAL: a first shift close on a till
 * that was never seeded — no float, no cash sales, but a non-zero counted
 * variance (someone put money in or took money out of the drawer). That till is
 * "never seeded" by every conjunct of the predicate, and refusing it would
 * re-create the exact defect lane G3 was built to fix: a cash variance that
 * moves the drawer and books NOTHING to the GL, silently.
 *
 * DECISION: FAIL OPEN for the shift-variance path — book it. Rationale from the
 * code's own reality rather than preference: (a) the listener's amount is
 * SERVER-COMPUTED from a committed physical cash count, never operator-typed,
 * so it cannot be a disguised float entry; (b) it books through 658/758 as a
 * variance, which is what a variance IS — there is no misclassification to
 * prevent; (c) that whole leg ships behind `treasury.shift_variance_gl_enabled`
 * = false and the launch posture keeps it false (runbook step 5), so the case is
 * unreachable at launch anyway; and (d) the listener already refuses-and-audits
 * six distinct ways and adding a seventh silent refusal there would be exactly
 * the "missing GL leg nobody notices" failure mode it was written against.
 *
 * MERGE NOTE (parallel lane R-8, .worktrees/r8-sv-queue): that lane is changing
 * PostShiftCashVarianceAdjustment (queueing / retry disposition). This guard
 * does NOT touch the listener — it discriminates on the intent DTO field the
 * listener already passes, so the two are orthogonal and merge in either order.
 * The one thing R-8 must not do is drop `posShiftId` from the intent; if it ever
 * did, the listener's own UUIDv5/idempotency contract would break first, and
 * ShiftCashVarianceAdjustmentTest would go red before this guard mattered.
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

        // B-2 GO-LIVE SEEDING GUARD (owner sheet B-2, research §1.2 Case 1/Case 3,
        // Recommendation 2). See the predicate rationale on isNeverSeeded() and
        // RepositoryNotSeededException. Placed after the scale is resolved (the
        // balance comparison needs it — rule 19 forbids a hardcoded bcmath scale)
        // and before ANY write, so a refused seeding attempt leaves nothing behind.
        if ($intent->posShiftId === null && $this->isNeverSeeded($repository, $scale)) {
            throw new RepositoryNotSeededException(
                $repository->id,
                $repository->name,
                $repository->code,
            );
        }

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

    /**
     * "Treasury has no record of this repository ever holding money."
     *
     * ── The three conjuncts, and why each one is there ───────────────────────
     *
     * 1. NO MOVEMENTS. `repository_movements` is append-only and is the ONLY
     *    way money enters or leaves a repository (the port is the sole writer;
     *    2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php
     *    is the pgsql backstop). A till that has processed even one cash sale
     *    has a `fiscal_event` movement from TreasuryReceiptBridge; a bank
     *    account that has taken one payment has a `payment` movement. So "zero
     *    movements" is exactly "this repository has never traded" — which is
     *    what separates the go-live seeding misuse from a genuine first
     *    variance on a working till. A real first variance happens at the close
     *    of a shift that sold something, and that shift laid down movements.
     *
     * 2. NO PRIOR ADJUSTMENTS. Redundant with (1) in the happy path — post()
     *    is transactional, so an adjustment always leaves a movement behind —
     *    but it is the belt: any repository that has already been adjusted has
     *    demonstrably been "activated", and a second correction on it must not
     *    be refused. Cheap, and it makes the refusal strictly narrower.
     *
     * 3. ZERO CACHED BALANCE. In PRODUCTION this conjunct can never change the
     *    outcome, and that is the point of stating it. A repository is BORN at
     *    balance 0 (PaymentRepositoryController::store passes '0.00';
     *    PaymentRepository::$attributes carries `balance => 0`; the column is
     *    NOT fillable) and the INSERT trigger above rejects a non-zero balance
     *    minted with no backing movement — so `balance != 0` IMPLIES movements
     *    exist, and (1) has already returned false. Its job is to keep the
     *    guard from firing on a repository whose balance was scaffolded by some
     *    OTHER means (the test/seed factory brackets its INSERT with
     *    `app.treasury_movement_port = 'on'` precisely to do this without
     *    laying down a phantom opening movement). Such a repository HAS been
     *    seeded — just not through the movement port — and refusing a
     *    correction on it would be wrong. Fail-open on ambiguity: this guard's
     *    only job is to catch the empty-till-plus-float shape.
     *
     * ── What it deliberately does NOT discriminate on ────────────────────────
     * Not the reason code. Research Recommendation 2 scoped the refusal to
     * `correction`/`other`, but the reason code is operator-chosen free choice
     * on a dropdown, so scoping by it would let the same misuse through under
     * `count_variance`. On a repository that has never held money there is no
     * honest reading of ANY inbound adjustment other than "I am seeding this
     * till" — an OUT adjustment is refused by the insufficient-balance rule
     * anyway. The caller-origin carve-out below is what keeps the guard narrow.
     */
    private function isNeverSeeded(PaymentRepository $repository, int $scale): bool
    {
        if (bccomp($repository->balance, '0', $scale) !== 0) {
            return false;
        }

        $hasMovement = RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->exists();

        if ($hasMovement) {
            return false;
        }

        return ! RepositoryAdjustment::query()
            ->where('payment_repository_id', $repository->id)
            ->exists();
    }
}
