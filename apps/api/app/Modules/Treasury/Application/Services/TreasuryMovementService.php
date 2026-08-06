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
use App\Modules\Treasury\Domain\Exceptions\InsufficientRepositoryBalanceException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryCheckpointException;
use App\Modules\Treasury\Domain\Exceptions\RepositoryFrozenException;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        $occurredAt = $intent->occurredAt ?? CarbonImmutable::now();

        // Replay precedes mutable transition policy. A retry of a movement that
        // predates a subsequently established checkpoint returns the existing
        // row; it is not a new backdated write.
        if (RepositoryMovement::query()->where('idempotency_key', $intent->idempotencyKey())->exists()) {
            return $this->handleIdempotentHit($intent);
        }

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

        $recordedBehindCheckpoint = $this->checkpointDisposition(
            $repo,
            $occurredAt,
            $intent->allowBehindCheckpoint,
        );

        // 3.5 W-5b Option B (owner ruling 2026-08-05): a physical/pseudo-
        // physical repository (cash_register/safe/virtual, allow_negative =
        // false by type-derived default) must never be driven below zero;
        // a bank_account may run an authorised overdraft (allow_negative =
        // true) and is never touched by this guard. Only an OUTFLOW can
        // drive the balance down, so INFLOW is exempt outright. Exact zero
        // is NOT negative (bccomp < 0, not <= 0) — draining a till to the
        // last cent is allowed. Mirrors the allowWhileFrozen policy shape
        // exactly: the explicit intent->allowNegative flag (queued/replay/
        // bridge writers only — see MovementIntent docblock) RECORDS the
        // movement and alerts instead of throwing, so a queue worker never
        // dies replaying a fact that already physically happened.
        $recordedNegative = false;
        if ($intent->direction === MovementDirection::Out && ! $repo->allow_negative) {
            $resultingBalance = bcsub($repo->balance ?? '0', $intent->amount, $scale);
            if (bccomp($resultingBalance, '0', $scale) < 0) {
                if ($intent->allowNegative) {
                    $recordedNegative = true; // derived/replay writer: record + alert, never throw
                } else {
                    throw new InsufficientRepositoryBalanceException(
                        $intent->repositoryId,
                        $repo->balance ?? '0',
                        $intent->amount,
                        $resultingBalance,
                        $intent->currency,
                    );
                }
            }
        }

        // MED-10: set the port GUC in the OUTER transaction, BEFORE the
        // savepoint, so a duplicate-key rollback-to-savepoint cannot unset it
        // and trip the Task-22 trigger. GUCs are a Postgres concept — SET LOCAL
        // is invalid on sqlite (test driver), so it is pgsql-only DDL.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
        }

        // Fix 2 (cutover-hardening): reset the GUC on EVERY exit path of the port's
        // body via `finally` — success, idempotent hit, OR any exception (e.g. a
        // non-unique QueryException rethrown below, or an IdempotencyConflict from
        // handleIdempotentHit). A leaked 'on' would let a later NON-port
        // `UPDATE … balance` in the SAME outer transaction slip past the Task-22
        // trigger. The `SET … 'on'` above stays BEFORE the savepoint (MED-10).
        try {
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
                    recordedBehindCheckpoint: $recordedBehindCheckpoint,
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
                $recordedBehindCheckpoint,
            )));

            if ($recordedBehindCheckpoint) {
                DB::afterCommit(fn () => Log::warning('Treasury movement recorded behind reconciliation checkpoint', [
                    'movement_id' => $movementId,
                    'repository_id' => $repo->id,
                    'occurred_at' => $occurredAt->toIso8601String(),
                    'checkpoint' => $repo->last_reconciled_at?->toIso8601String(),
                ]));
            }

            if ($recordedNegative) {
                // W-5b Option B alert lane: the writer forced an outflow through
                // via intent->allowNegative that this repository would otherwise
                // have refused. No thrown exception, no failed_jobs entry — just
                // a structured warning carrying everything a human needs to spot
                // and reconcile the now-negative till. Mirrors the
                // recordedBehindCheckpoint Log::warning above (the only ACTIVE
                // alert-emission precedent in this port for a "recorded but
                // flagged" movement).
                DB::afterCommit(fn () => Log::warning('Treasury movement recorded a negative repository balance', [
                    'movement_id' => $movementId,
                    'repository_id' => $repo->id,
                    'tenant_id' => $intent->tenantId,
                    'company_id' => $intent->companyId,
                    'amount' => $intent->amount,
                    'currency' => $intent->currency,
                    'balance_after' => $balanceAfter,
                    'source_type' => $intent->sourceType->value,
                    'source_id' => $intent->sourceId,
                ]));
            }

            return new MovementResult($movementId, $balanceAfter, $nextOrdinal, false);
        } finally {
            // MED-10 + Fix 2: the port is done — reset the GUC so a later non-port
            // write in the SAME outer transaction cannot ride the still-open guard.
            $this->closePort();
        }
    }

    public function transfer(TransferIntent $intent): TransferResult
    {
        // Fix 4 (self-transfer guard): a repository cannot transfer to itself —
        // it would take the same row lock twice and net to zero on one repo while
        // burning two ordinals. Reject before any locking. \DomainException → 422.
        if ($intent->fromRepositoryId === $intent->toRepositoryId) {
            throw new \DomainException('transfer() requires distinct source and destination repositories.');
        }

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

            // 4. Cross-GL-account legs MUST carry a JE (Fix 2 / spec invariant +
            // reconciliation §9.2). When the two repositories map to DIFFERENT
            // gl_account_id, a pre-posted balanced journalEntryId is mandatory —
            // writing null-JE cross-account legs would silently skip the GL post
            // and later FREEZE the repo during reconciliation. Refuse loudly.
            // Same-GL-account transfers legitimately carry null journal_entry_id.
            $crossGlAccount = $fromRepo->gl_account_id !== $toRepo->gl_account_id;
            if ($crossGlAccount && $intent->journalEntryId === null) {
                throw new \DomainException('transfer() across different GL accounts requires a journalEntryId (a pre-posted balanced JE); refusing to write null-JE cross-account legs.');
            }

            $occurredAt = $intent->occurredAt ?? CarbonImmutable::now();
            $outKey = "transfer:{$intent->transferGroupId}:out";
            $inKey = "transfer:{$intent->transferGroupId}:in";

            // As in record(), an existing pair is replayed before today's
            // checkpoint policy is applied to new writes.
            if (RepositoryMovement::query()->whereIn('idempotency_key', [$outKey, $inKey])->exists()) {
                return $this->handleTransferIdempotentHit($intent);
            }

            $this->checkpointDisposition($fromRepo, $occurredAt, false);
            $this->checkpointDisposition($toRepo, $occurredAt, false);

            // 5. MED-10: open the port GUC in the OUTER transaction, BEFORE the
            // savepoint, so a duplicate-key rollback-to-savepoint cannot unset it
            // and trip the Task-22 trigger. pgsql-only DDL (invalid on sqlite).
            if ($isPgsql) {
                DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
            }

            // Fix 2 (cutover-hardening): reset the GUC on EVERY exit path of the
            // port's body via `finally` — success, idempotent hit, OR any exception
            // (a non-unique QueryException, a failed GL post, or an
            // IdempotencyConflict from handleTransferIdempotentHit). The `SET …
            // 'on'` above stays BEFORE the savepoint (MED-10).
            try {
                // 6. SAVEPOINT for paired-leg idempotency recovery (Fix 1, mirrors
                // record()): the GL post + BOTH leg inserts + BOTH balance/ordinal
                // updates run inside a nested transaction (PG SAVEPOINT). On a replay
                // with the same transferGroupId, the out-leg's unique idempotency_key
                // poisons the transaction — rollback-to-savepoint reverts the JE post
                // and the ordinal/balance bumps, then handleTransferIdempotentHit
                // returns the already-written pair. Nested beginTransaction → SAVEPOINT.
                DB::beginTransaction();
                try {
                    // GL: post EXACTLY ONE journal entry — only when the two
                    // repositories cross gl_account_id (guaranteed non-null by step 4).
                    // The advisory lock (step 1) is already held, so postEntryNow's own
                    // re-acquire is a no-op and the global order is preserved.
                    $legJournalEntryId = null;
                    if ($crossGlAccount) {
                        /** @var JournalEntry $entry */
                        $entry = JournalEntry::query()->whereKey($intent->journalEntryId)->firstOrFail();
                        $actor = $intent->createdBy !== null ? User::find($intent->createdBy) : null;
                        $this->generalLedger->postEntryNow($entry, $actor, $intent->currency);
                        $legJournalEntryId = $intent->journalEntryId;
                    }

                    // Two legs — NOT two public record() calls. Each advances its own
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
                        recordedBehindCheckpoint: false,
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
                        recordedBehindCheckpoint: false,
                        occurredAt: $occurredAt,
                        createdBy: $intent->createdBy,
                        notes: $intent->notes,
                    );

                    DB::commit(); // release savepoint
                } catch (QueryException $e) {
                    DB::rollBack(); // to savepoint — reverts JE post + both legs, no gap
                    if (! $this->isUniqueViolation($e)) {
                        throw $e;
                    }

                    return $this->handleTransferIdempotentHit($intent); // SELECT both legs, validate or throw
                }

                // Fire RepositoryMovementRecorded for BOTH legs after commit — so
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
            } finally {
                // MED-10 + Fix 2: the port is done — reset the GUC on EVERY exit
                // path (success, idempotent hit, OR exception) so a later non-port
                // write in the SAME outer transaction cannot ride the still-open guard.
                $this->closePort();
            }
        });
    }

    public function freeze(string $repositoryId, string $reason): void
    {
        // Direct column write — ONLY frozen_at/frozen_reason, never balance
        // (Task 22's trigger will forbid balance writes outside this port; an
        // Eloquent ->save() risks carrying other dirty attributes along, so use
        // an explicit query-builder update scoped to exactly these two columns).
        PaymentRepository::query()
            ->whereKey($repositoryId)
            ->update([
                'frozen_at' => CarbonImmutable::now(),
                'frozen_reason' => $reason,
            ]);
    }

    public function unfreeze(string $repositoryId): void
    {
        PaymentRepository::query()
            ->whereKey($repositoryId)
            ->update([
                'frozen_at' => null,
                'frozen_reason' => null,
            ]);
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
        bool $recordedBehindCheckpoint,
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
            'recorded_behind_checkpoint' => $recordedBehindCheckpoint,
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
            recordedBehindCheckpoint: false,
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

    /**
     * Resolve a unique-violation collision on a transfer leg's idempotency_key:
     * the paired legs were already written under this transfer_group_id (Fix 1,
     * mirrors {@see handleIdempotentHit}). Both legs are SELECTed by their keys
     * (`transfer:{group}:out` / `:in`) and each leg's semantic fields are
     * validated against the intent. When both legs exist and every field matches
     * it is a genuine replay — return both as idempotent hits. When either leg is
     * missing (the unique violation was NOT a clean transfer replay — e.g. a
     * foreign row occupying one key, or an ordinal race) or any field disagrees,
     * fail loudly with IdempotencyConflictException rather than masking it.
     */
    private function handleTransferIdempotentHit(TransferIntent $intent): TransferResult
    {
        $outKey = "transfer:{$intent->transferGroupId}:out";
        $inKey = "transfer:{$intent->transferGroupId}:in";

        $existingOut = RepositoryMovement::query()->where('idempotency_key', $outKey)->first();
        $existingIn = RepositoryMovement::query()->where('idempotency_key', $inKey)->first();

        if ($existingOut === null || $existingIn === null) {
            throw new IdempotencyConflictException(
                $existingOut === null ? $outKey : $inKey,
                'unique violation did not correspond to a complete existing transfer leg pair for this transfer_group_id',
            );
        }

        $this->assertTransferLegMatches($existingOut, $intent, $intent->fromRepositoryId, MovementDirection::Out, $outKey);
        $this->assertTransferLegMatches($existingIn, $intent, $intent->toRepositoryId, MovementDirection::In, $inKey);

        return new TransferResult(
            new MovementResult($existingOut->id, $existingOut->balance_after, $existingOut->ordinal, true),
            new MovementResult($existingIn->id, $existingIn->balance_after, $existingIn->ordinal, true),
        );
    }

    /**
     * Validate one stored transfer leg against the replayed intent (repository,
     * direction, amount, currency, transfer_group_id). Throws
     * IdempotencyConflictException on any mismatch — the same key was reused for a
     * materially different leg. journal_entry_id is deliberately NOT compared (a
     * cross-account replay may re-derive the JE), mirroring record()'s contract.
     */
    private function assertTransferLegMatches(
        RepositoryMovement $existing,
        TransferIntent $intent,
        string $expectedRepositoryId,
        MovementDirection $expectedDirection,
        string $key,
    ): void {
        $mismatches = [];
        if ($existing->payment_repository_id !== $expectedRepositoryId) {
            $mismatches[] = "repository {$existing->payment_repository_id} != {$expectedRepositoryId}";
        }
        if ($existing->direction !== $expectedDirection) {
            $mismatches[] = "direction {$existing->direction->value} != {$expectedDirection->value}";
        }
        if (bccomp($existing->amount, $intent->amount, $this->scaleResolver->getScale($intent->currency)) !== 0) {
            $mismatches[] = "amount {$existing->amount} != {$intent->amount}";
        }
        if ($existing->currency !== $intent->currency) {
            $mismatches[] = "currency {$existing->currency} != {$intent->currency}";
        }
        if ($existing->transfer_group_id !== $intent->transferGroupId) {
            $mismatches[] = "transfer_group_id {$existing->transfer_group_id} != {$intent->transferGroupId}";
        }

        if ($mismatches !== []) {
            throw new IdempotencyConflictException($key, implode('; ', $mismatches));
        }
    }

    /**
     * MED-10 + Fix 2: reset the port GUC after the port's DML completes, so a
     * later non-port write in the SAME outer transaction cannot ride a still-open
     * guard past the Task-22 trigger. Complements the `SET ... 'on'` issued before
     * the savepoint, and is invoked from a `finally` in record()/transfer() so it
     * runs on EVERY exit path — success, idempotent hit, or exception. pgsql-only
     * DDL; no-op on sqlite (test driver).
     */
    private function closePort(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL app.treasury_movement_port = 'off'");
        }
    }

    private function buildRecordedEvent(
        string $movementId,
        MovementIntent $intent,
        string $balanceAfter,
        int $ordinal,
        CarbonInterface $occurredAt,
        bool $recordedWhileFrozen,
        bool $recordedBehindCheckpoint,
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
            recordedBehindCheckpoint: $recordedBehindCheckpoint,
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

    private function checkpointDisposition(
        PaymentRepository $repository,
        CarbonInterface $occurredAt,
        bool $allowBehindCheckpoint,
    ): bool {
        $checkpoint = $repository->last_reconciled_at;
        if ($checkpoint === null) {
            return false;
        }

        $timezone = (string) $repository->company()->value('timezone');
        $occurrenceDate = $occurredAt->toImmutable()->setTimezone($timezone)->toDateString();
        $checkpointDate = $checkpoint->toImmutable()->setTimezone($timezone)->toDateString();
        if ($occurrenceDate > $checkpointDate) {
            return false;
        }

        if ($allowBehindCheckpoint) {
            return true;
        }

        throw new RepositoryCheckpointException($repository->id, $occurredAt, $checkpoint);
    }
}
