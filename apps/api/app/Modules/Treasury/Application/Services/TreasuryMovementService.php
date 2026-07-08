<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Application\DTOs\MovementIntent;
use App\Modules\Treasury\Application\DTOs\MovementResult;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
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

        // 4. SAVEPOINT for idempotency recovery (a unique-violation poisons the
        // transaction otherwise). Nested beginTransaction → PG SAVEPOINT.
        DB::beginTransaction();
        try {
            $nextOrdinal = $repo->next_movement_ordinal + 1;
            $previous = $repo->balance ?? '0';
            $balanceAfter = $intent->direction === MovementDirection::In
                ? bcadd($previous, $intent->amount, $scale)
                : bcsub($previous, $intent->amount, $scale);

            $movementId = (string) Str::uuid();
            $occurredAt = $intent->occurredAt ?? CarbonImmutable::now();

            DB::table('repository_movements')->insert([
                'id' => $movementId,
                'tenant_id' => $intent->tenantId,
                'company_id' => $intent->companyId,
                'payment_repository_id' => $intent->repositoryId,
                'direction' => $intent->direction->value,
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'balance_after' => $balanceAfter,
                'ordinal' => $nextOrdinal,
                'source_type' => $intent->sourceType->value,
                'source_id' => $intent->sourceId,
                'journal_entry_id' => $intent->journalEntryId,
                'idempotency_key' => $intent->idempotencyKey(),
                'transfer_group_id' => null,
                'reverses_movement_id' => $intent->reversesMovementId,
                'reason_code' => $intent->reasonCode?->value,
                'occurred_at' => $occurredAt,
                'created_by' => $intent->createdBy,
                'recorded_while_frozen' => $recordedWhileFrozen,
                'notes' => $intent->notes,
            ]);

            $repo->next_movement_ordinal = $nextOrdinal;
            $repo->balance = $balanceAfter;
            $repo->save();

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
