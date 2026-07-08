<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\MovementResult;
use App\Modules\Treasury\Application\DTOs\TransferIntent;
use App\Modules\Treasury\Application\DTOs\TransferResult;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use App\Modules\Treasury\Domain\Exceptions\CurrencyMismatchException;
use App\Modules\Treasury\Domain\Exceptions\IdempotencyConflictException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single write port every treasury money-movement converges onto
 * (Treasury Money-Movement Spine, Task 11 — spec §5.4).
 *
 * See {@see TreasuryMovementServiceInterface} for the contract. The ordering
 * below is load-bearing (concurrency + idempotency correctness) — do NOT
 * reorder.
 */
final readonly class TreasuryMovementService implements TreasuryMovementServiceInterface
{
    public function __construct(
        private CurrencyScaleResolverInterface $scaleResolver,
        private GeneralLedgerService $generalLedger,
    ) {}

    public function record(MovementIntent $intent): MovementResult
    {
        // 0. MED-9: the caller MUST own an outer transaction (so the GL post +
        // this movement + the SET LOCAL GUC are atomic). Enforce it — a
        // top-level call would silently lose the row lock at statement end.
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('TreasuryMovementService::record() must be called inside a DB::transaction (with the GL post).');
        }

        $scale = $this->scaleResolver->getScale($intent->currency);

        // 1. Lock the repository row. NOTE global lock order (BLOCKER-1): the
        // caller has ALREADY taken the GL company advisory lock via
        // postEntryNow. The repo lock comes second, always.
        /** @var PaymentRepository $repo */
        $repo = PaymentRepository::query()
            ->where('tenant_id', $intent->tenantId)
            ->where('company_id', $intent->companyId)
            ->whereKey($intent->repositoryId)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Currency guard (review F12): intent must match repository (and thus
        // company) currency.
        if ($repo->currency !== $intent->currency) {
            throw new CurrencyMismatchException($intent->repositoryId, $repo->currency, $intent->currency);
        }

        // 3. Freeze policy (review F5 + HIGH-4/5): rejection is driven by the
        // EXPLICIT allowWhileFrozen flag, never inferred from sourceType
        // (returns are Refund; server DEPOSIT_RECEIPT is FiscalEvent — both
        // would be misclassified).
        $recordedWhileFrozen = false;
        if ($repo->frozen_at !== null) {
            if ($intent->allowWhileFrozen) {
                $recordedWhileFrozen = true; // offline device replay: record + alert, never throw
            } else {
                throw new RepositoryFrozenException($intent->repositoryId, (string) $repo->frozen_reason);
            }
        }

        // MED-10: set the port GUC in the OUTER transaction, BEFORE the
        // savepoint, so a duplicate-key rollback-to-savepoint cannot unset it
        // and trip the Task-22 trigger. GUCs are a Postgres concept — SET LOCAL
        // is invalid on sqlite (test driver), so it is pgsql-only DDL.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
        }

        $occurredAt = $intent->occurredAt ?? CarbonImmutable::now();

        // 4. SAVEPOINT for idempotency recovery (a unique-violation poisons the
        // transaction otherwise). Nested beginTransaction → PG SAVEPOINT.
        DB::beginTransaction();
        try {
            [$movementId, $balanceAfter, $nextOrdinal] = $this->insertMovementLeg(
                repository: $repo,
                direction: $intent->direction,
                amount: $intent->amount,
                currency: $intent->currency,
                scale: $scale,
                sourceType: $intent->sourceType,
                sourceId: $intent->sourceId,
                idempotencyKey: $intent->idempotencyKey(),
                journalEntryId: $intent->journalEntryId,
                transferGroupId: null,
                reversesMovementId: $intent->reversesMovementId,
                reasonCode: $intent->reasonCode,
                recordedWhileFrozen: $recordedWhileFrozen,
                occurredAt: $occurredAt,
                createdBy: $intent->createdBy,
                notes: $intent->notes,
            );

            DB::commit(); // release savepoint
        } catch (QueryException $e) {
            DB::rollBack(); // to savepoint — undoes ordinal + balance, no gap
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return $this->handleIdempotentHit($intent); // SELECT existing, validate semantics or throw
        }

        DB::afterCommit(fn () => event($this->buildRecordedEvent(
            $movementId,
            $intent,
            $balanceAfter,
            $nextOrdinal,
            $occurredAt,
            $recordedWhileFrozen,
        )));

        return new MovementResult($movementId, $balanceAfter, $nextOrdinal, false);
    }

    public function transfer(TransferIntent $intent): TransferResult
    {
        $scale = $this->scaleResolver->getScale($intent->currency);
        $isPgsql = DB::connection()->getDriverName() === 'pgsql';

        return DB::transaction(function () use ($intent, $scale, $isPgsql): TransferResult {
            // 1. GLOBAL LOCK ORDER (BLOCKER-1): take the GL company advisory lock
            // FIRST — before ANY repository row lock — so this transfer can never
            // deadlock against a record()+GL flow (which also takes advisory THEN
            // repo, via postEntryNow). Same key/shape as postEntryNow, so a JE
            // post below re-acquires the same transaction-scoped lock harmlessly.
            // GUC/advisory locking is a Postgres concept — no-op on sqlite (test
            // driver).
            if ($isPgsql) {
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$intent->companyId]);
            }

            // 2. Lock BOTH repositories, sorted by id. Sorting is what prevents a
            // deadlock between opposing concurrent A↔B transfers: every transfer
            // acquires the two row locks in the SAME (id-ascending) order.
            $sortedIds = [$intent->fromRepositoryId, $intent->toRepositoryId];
            sort($sortedIds);

            /** @var array<string, PaymentRepository> $locked */
            $locked = [];
            foreach ($sortedIds as $repositoryId) {
                $locked[$repositoryId] = PaymentRepository::query()
                    ->where('tenant_id', $intent->tenantId)
                    ->where('company_id', $intent->companyId)
                    ->whereKey($repositoryId)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $fromRepo = $locked[$intent->fromRepositoryId];
            $toRepo = $locked[$intent->toRepositoryId];

            // 3. Currency guard (review F12): the intent must match BOTH
            // repositories' currency (each of which was backfilled from — and so
            // matches — the company currency).
            if ($fromRepo->currency !== $intent->currency) {
                throw new CurrencyMismatchException($fromRepo->id, $fromRepo->currency, $intent->currency);
            }
            if ($toRepo->currency !== $intent->currency) {
                throw new CurrencyMismatchException($toRepo->id, $toRepo->currency, $intent->currency);
            }

            // 4. Open the port (Task-22 trigger gate). pgsql-only DDL.
            if ($isPgsql) {
                DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
            }

            // 5. GL: post EXACTLY ONE journal entry — and only when the two
            // repositories map to DIFFERENT gl_account_id. A transfer between two
            // repositories backed by the same GL account has no net GL effect, so
            // no entry is posted and both legs carry a null journal_entry_id (the
            // spec's nullable exemption for same-GL-account transfer legs). The
            // advisory lock (step 1) is already held, so postEntryNow's own
            // re-acquire is a no-op and the global order is preserved.
            $legJournalEntryId = null;
            if ($fromRepo->gl_account_id !== $toRepo->gl_account_id && $intent->journalEntryId !== null) {
                /** @var JournalEntry $entry */
                $entry = JournalEntry::query()->whereKey($intent->journalEntryId)->firstOrFail();
                $actor = $intent->createdBy !== null ? User::find($intent->createdBy) : null;
                $this->generalLedger->postEntryNow($entry, $actor, $intent->currency);
                $legJournalEntryId = $intent->journalEntryId;
            }

            $occurredAt = $intent->occurredAt ?? CarbonImmutable::now();

            // 6. Two legs — NOT two public record() calls. Each advances its own
            // repository's gapless ordinal + cached balance; both share the
            // intent's transfer_group_id and net to zero.
            [$outId, $outBalance, $outOrdinal] = $this->insertMovementLeg(
                repository: $fromRepo,
                direction: MovementDirection::Out,
                amount: $intent->amount,
                currency: $intent->currency,
                scale: $scale,
                sourceType: MovementSourceType::Transfer,
                sourceId: $intent->transferGroupId,
                idempotencyKey: "transfer:{$intent->transferGroupId}:out",
                journalEntryId: $legJournalEntryId,
                transferGroupId: $intent->transferGroupId,
                reversesMovementId: null,
                reasonCode: null,
                recordedWhileFrozen: false,
                occurredAt: $occurredAt,
                createdBy: $intent->createdBy,
                notes: $intent->notes,
            );

            [$inId, $inBalance, $inOrdinal] = $this->insertMovementLeg(
                repository: $toRepo,
                direction: MovementDirection::In,
                amount: $intent->amount,
                currency: $intent->currency,
                scale: $scale,
                sourceType: MovementSourceType::Transfer,
                sourceId: $intent->transferGroupId,
                idempotencyKey: "transfer:{$intent->transferGroupId}:in",
                journalEntryId: $legJournalEntryId,
                transferGroupId: $intent->transferGroupId,
                reversesMovementId: null,
                reasonCode: null,
                recordedWhileFrozen: false,
                occurredAt: $occurredAt,
                createdBy: $intent->createdBy,
                notes: $intent->notes,
            );

            // 7. Fire RepositoryMovementRecorded for BOTH legs after commit — so
            // no listener observes an uncommitted (or rolled-back) transfer.
            DB::afterCommit(function () use (
                $intent,
                $fromRepo,
                $toRepo,
                $legJournalEntryId,
                $occurredAt,
                $outId,
                $outBalance,
                $outOrdinal,
                $inId,
                $inBalance,
                $inOrdinal,
            ): void {
                event($this->buildTransferLegEvent(
                    $outId,
                    $fromRepo->id,
                    $intent,
                    MovementDirection::Out,
                    $outBalance,
                    $outOrdinal,
                    $legJournalEntryId,
                    $occurredAt,
                ));
                event($this->buildTransferLegEvent(
                    $inId,
                    $toRepo->id,
                    $intent,
                    MovementDirection::In,
                    $inBalance,
                    $inOrdinal,
                    $legJournalEntryId,
                    $occurredAt,
                ));
            });

            return new TransferResult(
                new MovementResult($outId, $outBalance, $outOrdinal, false),
                new MovementResult($inId, $inBalance, $inOrdinal, false),
            );
        });
    }

    /**
     * Insert one append-only movement leg against an ALREADY-LOCKED repository,
     * advance its gapless ordinal, and update its cached balance.
     *
     * The single implementation of the ordinal/balance/insert sequence, shared
     * by {@see record()} and both {@see transfer()} legs — so the three writers
     * cannot drift. The caller owns transaction/savepoint framing and lock
     * acquisition; this method only writes.
     *
     * @param  numeric-string  $amount
     * @return array{0: string, 1: numeric-string, 2: int} [movementId, balanceAfter, ordinal]
     */
    private function insertMovementLeg(
        PaymentRepository $repository,
        MovementDirection $direction,
        string $amount,
        string $currency,
        int $scale,
        MovementSourceType $sourceType,
        string $sourceId,
        string $idempotencyKey,
        ?string $journalEntryId,
        ?string $transferGroupId,
        ?string $reversesMovementId,
        ?MovementReasonCode $reasonCode,
        bool $recordedWhileFrozen,
        CarbonInterface $occurredAt,
        ?string $createdBy,
        ?string $notes,
    ): array {
        $nextOrdinal = $repository->next_movement_ordinal + 1;
        $previous = $repository->balance ?? '0';
        /** @var numeric-string $balanceAfter */
        $balanceAfter = $direction === MovementDirection::In
            ? bcadd($previous, $amount, $scale)
            : bcsub($previous, $amount, $scale);

        $movementId = (string) Str::uuid();

        DB::table('repository_movements')->insert([
            'id' => $movementId,
            'tenant_id' => $repository->tenant_id,
            'company_id' => $repository->company_id,
            'payment_repository_id' => $repository->id,
            'direction' => $direction->value,
            'amount' => $amount,
            'currency' => $currency,
            'balance_after' => $balanceAfter,
            'ordinal' => $nextOrdinal,
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'journal_entry_id' => $journalEntryId,
            'idempotency_key' => $idempotencyKey,
            'transfer_group_id' => $transferGroupId,
            'reverses_movement_id' => $reversesMovementId,
            'reason_code' => $reasonCode?->value,
            'occurred_at' => $occurredAt,
            'created_by' => $createdBy,
            'recorded_while_frozen' => $recordedWhileFrozen,
            'notes' => $notes,
        ]);

        $repository->next_movement_ordinal = $nextOrdinal;
        $repository->balance = $balanceAfter;
        $repository->save();

        return [$movementId, $balanceAfter, $nextOrdinal];
    }

    /**
     * Build the RepositoryMovementRecorded event for one transfer leg.
     */
    private function buildTransferLegEvent(
        string $movementId,
        string $repositoryId,
        TransferIntent $intent,
        MovementDirection $direction,
        string $balanceAfter,
        int $ordinal,
        ?string $journalEntryId,
        CarbonInterface $occurredAt,
    ): RepositoryMovementRecorded {
        return new RepositoryMovementRecorded(
            movementId: $movementId,
            repositoryId: $repositoryId,
            tenantId: $intent->tenantId,
            companyId: $intent->companyId,
            direction: $direction,
            amount: $intent->amount,
            balanceAfter: $balanceAfter,
            currency: $intent->currency,
            sourceType: MovementSourceType::Transfer,
            sourceId: $intent->transferGroupId,
            journalEntryId: $journalEntryId,
            ordinal: $ordinal,
            recordedWhileFrozen: false,
            occurredAt: $occurredAt->toIso8601String(),
            createdBy: $intent->createdBy,
            reasonCode: null,
            reversesMovementId: null,
            transferGroupId: $intent->transferGroupId,
        );
    }

    /**
     * Resolve a unique-violation collision on idempotency_key: the movement was
     * already recorded. Return the existing row when every semantic field
     * matches (a genuine replay); throw when the same key was reused for a
     * materially different movement.
     *
     * NOTE: `journal_entry_id` is deliberately NOT part of the comparison — see
     * the replay contract on {@see TreasuryMovementServiceInterface::record()}.
     */
    private function handleIdempotentHit(MovementIntent $intent): MovementResult
    {
        $existing = RepositoryMovement::query()
            ->where('idempotency_key', $intent->idempotencyKey())
            ->first();

        if ($existing === null) {
            // The unique violation was on a DIFFERENT constraint (e.g. the
            // (payment_repository_id, ordinal) gapless guard under a concurrent
            // writer) — not an idempotent replay. Surface it loudly rather than
            // masking a real ordinal race as a no-op.
            throw new IdempotencyConflictException(
                $intent->idempotencyKey(),
                'unique violation did not correspond to an existing movement with this idempotency key',
            );
        }

        $mismatches = [];
        if ($existing->payment_repository_id !== $intent->repositoryId) {
            $mismatches[] = "repository {$existing->payment_repository_id} != {$intent->repositoryId}";
        }
        if ($existing->direction !== $intent->direction) {
            $mismatches[] = "direction {$existing->direction->value} != {$intent->direction->value}";
        }
        if (bccomp($existing->amount, $intent->amount, $this->scaleResolver->getScale($intent->currency)) !== 0) {
            $mismatches[] = "amount {$existing->amount} != {$intent->amount}";
        }
        if ($existing->currency !== $intent->currency) {
            $mismatches[] = "currency {$existing->currency} != {$intent->currency}";
        }
        if ($existing->source_type !== $intent->sourceType) {
            $mismatches[] = "source_type {$existing->source_type->value} != {$intent->sourceType->value}";
        }
        if ($existing->source_id !== $intent->sourceId) {
            $mismatches[] = "source_id {$existing->source_id} != {$intent->sourceId}";
        }

        if ($mismatches !== []) {
            throw new IdempotencyConflictException($intent->idempotencyKey(), implode('; ', $mismatches));
        }

        return new MovementResult(
            $existing->id,
            $existing->balance_after,
            $existing->ordinal,
            true,
        );
    }

    private function buildRecordedEvent(
        string $movementId,
        MovementIntent $intent,
        string $balanceAfter,
        int $ordinal,
        CarbonInterface $occurredAt,
        bool $recordedWhileFrozen,
    ): RepositoryMovementRecorded {
        return new RepositoryMovementRecorded(
            movementId: $movementId,
            repositoryId: $intent->repositoryId,
            tenantId: $intent->tenantId,
            companyId: $intent->companyId,
            direction: $intent->direction,
            amount: $intent->amount,
            balanceAfter: $balanceAfter,
            currency: $intent->currency,
            sourceType: $intent->sourceType,
            sourceId: $intent->sourceId,
            journalEntryId: $intent->journalEntryId,
            ordinal: $ordinal,
            recordedWhileFrozen: $recordedWhileFrozen,
            occurredAt: $occurredAt->toIso8601String(),
            createdBy: $intent->createdBy,
            reasonCode: $intent->reasonCode,
            reversesMovementId: $intent->reversesMovementId,
            transferGroupId: null,
        );
    }

    /**
     * Precise unique-violation detection ONLY — never the broad SQLSTATE class.
     *
     * On pgsql (production), SQLSTATE 23505 is the exact unique_violation code;
     * the broader class '23000' also covers FK and NOT-NULL violations on some
     * drivers and must NEVER be treated as "unique" or a genuine FK/NOT-NULL bug
     * would be misrouted into {@see handleIdempotentHit} and silently swallowed.
     *
     * sqlite (test driver only) reports EVERY constraint violation — unique, FK,
     * NOT NULL — under the same SQLSTATE '23000' with the same driver code 19
     * (SQLITE_CONSTRAINT); there is no distinct SQLSTATE for unique violations
     * on that driver. Disambiguate there via the driver-specific message text
     * ("UNIQUE constraint failed"), which is sqlite's own unique-constraint
     * signal.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000' && DB::connection()->getDriverName() === 'sqlite') {
            $message = (string) ($e->errorInfo[2] ?? '');

            return str_contains($message, 'UNIQUE constraint failed');
        }

        return false;
    }
}
