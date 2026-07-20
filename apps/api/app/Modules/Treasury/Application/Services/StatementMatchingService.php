<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\BankStatementMatchExecution;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\StatementLineIgnoreReason;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Enums\StatementMatchType;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Domain\CurrencyScale;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StatementMatchingService
{
    public function __construct(
        private StatementActionRegistry $actions,
        private CurrencyScaleResolverInterface $scaleResolver,
    ) {}

    /** @param list<array{movementId: string, amount: string}> $allocations */
    public function allocate(string $lineId, array $allocations, string $userId): void
    {
        DB::transaction(function () use ($lineId, $allocations, $userId): void {
            [$statement, $line] = $this->lockMutableAggregate($lineId, $userId);
            $this->allocateLocked(
                $statement,
                $line,
                $allocations,
                $userId,
                StatementMatchType::Manual,
                false,
            );
        });
    }

    public function unallocate(string $lineId, ?string $movementId, string $userId): void
    {
        DB::transaction(function () use ($lineId, $movementId, $userId): void {
            [$statement, $line] = $this->lockMutableAggregate($lineId, $userId);
            $query = BankStatementLineAllocation::query()
                ->where('bank_statement_line_id', $line->id)
                ->when($movementId !== null, fn ($builder) => $builder->where('repository_movement_id', $movementId))
                ->lockForUpdate();
            $allocations = $query->get();
            $movementIds = $this->allocationMovementIds($allocations);
            if ($movementIds !== []) {
                $this->lockMovements($statement, $movementIds);
                $query->delete();
            }
            $this->recomputeStatus($statement, $line);
        });
    }

    public function ignore(
        string $lineId,
        StatementLineIgnoreReason $reason,
        string $text,
        string $userId,
    ): void {
        DB::transaction(function () use ($lineId, $reason, $text, $userId): void {
            [, $line] = $this->lockMutableAggregate($lineId, $userId);
            if (trim($text) === '') {
                throw new DomainException('An ignore explanation is required.');
            }
            $hasAllocations = BankStatementLineAllocation::query()
                ->where('bank_statement_line_id', $line->id)
                ->lockForUpdate()
                ->exists();
            $hasExecutions = BankStatementMatchExecution::query()
                ->where('bank_statement_line_id', $line->id)
                ->lockForUpdate()
                ->exists();
            if ($hasAllocations || $hasExecutions) {
                throw new DomainException('A statement line with allocations or executions cannot be ignored.');
            }
            $line->forceFill([
                'match_status' => StatementLineMatchStatus::Ignored,
                'ignore_reason' => $reason,
                'ignore_text' => trim($text),
            ])->save();
        });
    }

    public function unignore(string $lineId, string $userId): void
    {
        DB::transaction(function () use ($lineId, $userId): void {
            [$statement, $line] = $this->lockMutableAggregate($lineId, $userId);
            $line->forceFill(['ignore_reason' => null, 'ignore_text' => null])->save();
            $this->recomputeStatus($statement, $line);
        });
    }

    /** @param array<string, mixed> $params */
    public function executeAndAllocate(
        string $lineId,
        MatchActionType $action,
        array $params,
        string $userId,
    ): void {
        DB::transaction(function () use ($lineId, $action, $params, $userId): void {
            [$statement, $line] = $this->lockMutableAggregate($lineId, $userId);
            $handler = $this->actions->handler($action);
            $actionKey = "stmtline:{$line->id}:{$action->value}";
            $expectedDigest = StatementActionDigest::make($action, $line, $params);
            $execution = BankStatementMatchExecution::query()
                ->where('action_key', $actionKey)
                ->lockForUpdate()
                ->first();

            if ($execution instanceof BankStatementMatchExecution) {
                if ($execution->action_type !== $action
                    || ! hash_equals($execution->semantic_digest, $expectedDigest)) {
                    throw new DomainException('Statement action replay semantic digest mismatch.');
                }
                $movementIds = $execution->produced_repository_movement_ids;
            } else {
                $result = $handler->execute($line, $params, $userId);
                if (! hash_equals($expectedDigest, $result->semanticDigest)) {
                    throw new DomainException('Statement action handler returned an unexpected semantic digest.');
                }
                if (($result->targetType === null) !== ($result->targetId === null)) {
                    throw new DomainException('Statement action target type and id must be supplied together.');
                }
                $movementIds = array_values(array_unique($result->movementIds));
                if ($movementIds === []) {
                    throw new DomainException('Statement action did not produce a repository movement.');
                }
                BankStatementMatchExecution::query()->create([
                    'bank_statement_line_id' => $line->id,
                    'action_type' => $action,
                    'action_key' => $actionKey,
                    'semantic_digest' => $expectedDigest,
                    'target_type' => $result->targetType,
                    'target_id' => $result->targetId,
                    'produced_repository_movement_ids' => $movementIds,
                    'executed_by' => $userId,
                    'executed_at' => now(),
                ]);
            }

            // Read immutable movement amounts without taking a partial lock set.
            // allocateLocked() acquires the complete union (existing + produced)
            // once, in stable ID order, before it reads any allocation sums.
            $movements = $this->findMovements($statement, $movementIds);
            $allocations = [];
            foreach ($movementIds as $movementId) {
                $movement = $movements->get($movementId);
                if (! $movement instanceof RepositoryMovement) {
                    throw new DomainException('Statement action movement could not be resolved.');
                }
                $allocations[] = ['movementId' => $movement->id, 'amount' => $movement->amount];
            }
            $matchType = in_array($action, [
                MatchActionType::AcquirerFee,
                MatchActionType::CreateExpense,
                MatchActionType::CreateIncome,
            ], true) ? StatementMatchType::CreatedFromLine : StatementMatchType::SuggestionConfirmed;
            $this->allocateLocked($statement, $line, $allocations, $userId, $matchType, true, true);
        });
    }

    /**
     * @param  list<array{movementId: string, amount: string}>  $requested
     */
    private function allocateLocked(
        BankStatement $statement,
        BankStatementLine $line,
        array $requested,
        string $userId,
        StatementMatchType $matchType,
        bool $allowOppositeDirection,
        bool $idempotentExisting = false,
    ): void {
        if ($line->match_status === StatementLineMatchStatus::Ignored) {
            throw new DomainException('Unignore the statement line before allocating it.');
        }
        if ($requested === []) {
            throw new DomainException('At least one movement allocation is required.');
        }
        $scale = $this->scaleResolver->getScale($statement->currency);
        $existingForLine = BankStatementLineAllocation::query()
            ->where('bank_statement_line_id', $line->id)
            ->lockForUpdate()
            ->get();
        $requestedByMovement = [];
        foreach ($requested as $allocation) {
            $movementId = $allocation['movementId'];
            if (isset($requestedByMovement[$movementId])) {
                throw new DomainException('A movement can appear only once in an allocation request.');
            }
            $amount = $this->canonicalPositiveAmount($allocation['amount'], $scale);
            $existing = $existingForLine->firstWhere('repository_movement_id', $movementId);
            if ($existing instanceof BankStatementLineAllocation && $idempotentExisting) {
                if ($existing->match_type !== $matchType
                    || bccomp($existing->matched_amount, $amount, $scale) !== 0) {
                    throw new DomainException('Existing action allocation does not match the replayed execution.');
                }

                continue;
            }
            if ($existing instanceof BankStatementLineAllocation) {
                throw new DomainException('This movement is already allocated to the statement line.');
            }
            $requestedByMovement[$movementId] = $amount;
        }

        $movementIds = array_values(array_unique([
            ...$this->allocationMovementIds($existingForLine),
            ...array_keys($requestedByMovement),
        ]));
        sort($movementIds, SORT_STRING);
        $movements = $this->lockMovements($statement, $movementIds);

        foreach ($requestedByMovement as $movementId => $amount) {
            $movement = $movements->get($movementId);
            if (! $movement instanceof RepositoryMovement) {
                throw new DomainException('The selected movement does not belong to the statement repository.');
            }
            if (! $allowOppositeDirection && $movement->direction !== $line->direction) {
                throw new DomainException('Manual allocation movement direction must match the statement line direction.');
            }
            $allocated = (string) BankStatementLineAllocation::query()
                ->where('repository_movement_id', $movementId)
                ->sum('matched_amount');
            if (bccomp(bcadd($allocated, $amount, $scale), $movement->amount, $scale) > 0) {
                throw new DomainException('Allocation total cannot exceed the repository movement amount.');
            }
        }

        $lineTotal = $this->signedLineTotal($line, $existingForLine, $movements, $scale);
        foreach ($requestedByMovement as $movementId => $amount) {
            $movement = $movements->get($movementId);
            if (! $movement instanceof RepositoryMovement) {
                throw new DomainException('The selected movement does not belong to the statement repository.');
            }
            $lineTotal = $movement->direction === $line->direction
                ? bcadd($lineTotal, $amount, $scale)
                : bcsub($lineTotal, $amount, $scale);
        }
        if (bccomp($lineTotal, '0', $scale) < 0 || bccomp($lineTotal, $line->amount, $scale) > 0) {
            throw new DomainException('Signed allocation total cannot be negative or exceed the statement line amount.');
        }

        foreach ($requestedByMovement as $movementId => $amount) {
            BankStatementLineAllocation::query()->create([
                'bank_statement_line_id' => $line->id,
                'repository_movement_id' => $movementId,
                'matched_amount' => $amount,
                'match_type' => $matchType,
                'matched_by' => $userId,
                'matched_at' => now(),
            ]);
        }
        $this->recomputeStatus($statement, $line);
    }

    /** @return array{BankStatement, BankStatementLine} */
    private function lockMutableAggregate(string $lineId, string $userId): array
    {
        $seed = BankStatementLine::query()->find($lineId);
        if (! $seed instanceof BankStatementLine) {
            throw new DomainException('Statement line was not found.');
        }
        $statement = BankStatement::query()->lockForUpdate()->find($seed->bank_statement_id);
        if (! $statement instanceof BankStatement) {
            throw new DomainException('Statement line parent was not found.');
        }
        $line = BankStatementLine::query()->lockForUpdate()->find($lineId);
        if (! $line instanceof BankStatementLine || $line->bank_statement_id !== $statement->id) {
            throw new DomainException('Statement line changed while it was being locked.');
        }
        if (in_array($statement->status, [BankStatementStatus::Reconciled, BankStatementStatus::Voided], true)) {
            throw new DomainException("A {$statement->status->value} statement cannot be changed.");
        }
        if (! User::query()->where('tenant_id', $statement->tenant_id)->whereKey($userId)->exists()) {
            throw new DomainException('The matching user does not belong to the statement tenant.');
        }
        if ($statement->status === BankStatementStatus::Imported) {
            $statement->status = BankStatementStatus::Reconciling;
            $statement->save();
        }

        return [$statement, $line];
    }

    /**
     * @param  list<string>  $movementIds
     * @return Collection<string, RepositoryMovement>
     */
    private function lockMovements(BankStatement $statement, array $movementIds): Collection
    {
        if ($movementIds === []) {
            return new Collection;
        }
        $sorted = array_values(array_unique($movementIds));
        sort($sorted, SORT_STRING);
        $movements = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('currency', $statement->currency)
            ->whereIn('id', $sorted)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($movements->count() !== count($sorted)) {
            throw new DomainException('The selected movement does not belong to the statement repository.');
        }

        return $movements;
    }

    /**
     * @param  list<string>  $movementIds
     * @return Collection<string, RepositoryMovement>
     */
    private function findMovements(BankStatement $statement, array $movementIds): Collection
    {
        $sorted = array_values(array_unique($movementIds));
        sort($sorted, SORT_STRING);
        $movements = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('currency', $statement->currency)
            ->whereIn('id', $sorted)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        if ($movements->count() !== count($sorted)) {
            throw new DomainException('Statement action movement could not be resolved.');
        }

        return $movements;
    }

    private function recomputeStatus(BankStatement $statement, BankStatementLine $line): void
    {
        $scale = $this->scaleResolver->getScale($statement->currency);
        $allocations = BankStatementLineAllocation::query()
            ->where('bank_statement_line_id', $line->id)
            ->lockForUpdate()
            ->get();
        $movementIds = $this->allocationMovementIds($allocations);
        $movements = $this->lockMovements($statement, $movementIds);
        $total = $this->signedLineTotal($line, $allocations, $movements, $scale);
        $resolvedByCreation = BankStatementMatchExecution::query()
            ->where('bank_statement_line_id', $line->id)
            ->whereIn('action_type', [MatchActionType::CreateExpense, MatchActionType::CreateIncome])
            ->exists();
        $line->forceFill([
            'match_status' => StatementLineMatchStatus::derive(false, $resolvedByCreation, $total, $line->amount, $scale),
            'ignore_reason' => null,
            'ignore_text' => null,
        ])->save();
    }

    /**
     * @param  Collection<int, BankStatementLineAllocation>  $allocations
     * @param  Collection<string, RepositoryMovement>  $movements
     * @return numeric-string
     */
    private function signedLineTotal(
        BankStatementLine $line,
        Collection $allocations,
        Collection $movements,
        int $scale,
    ): string {
        return $allocations->reduce(function (string $total, BankStatementLineAllocation $allocation) use ($line, $movements, $scale): string {
            $movement = $movements->get($allocation->repository_movement_id);
            if (! $movement instanceof RepositoryMovement) {
                throw new DomainException('An allocation references a movement outside the statement repository.');
            }

            return $movement->direction === $line->direction
                ? bcadd($total, $allocation->matched_amount, $scale)
                : bcsub($total, $allocation->matched_amount, $scale);
        }, CurrencyScale::bcformatStrict('0', $scale));
    }

    /** @return numeric-string */
    private function canonicalPositiveAmount(string $amount, int $scale): string
    {
        $pattern = $scale === 0 ? '/^\d+$/' : '/^\d+(?:\.\d{1,'.$scale.'})?$/';
        if (preg_match($pattern, $amount) !== 1) {
            throw new DomainException('Allocation amount must respect the statement currency precision.');
        }
        $canonical = CurrencyScale::bcformatStrict($amount, $scale);
        if (bccomp($canonical, '0', $scale) <= 0) {
            throw new DomainException('Allocation amount must be positive.');
        }

        return $canonical;
    }

    /**
     * @param  Collection<int, BankStatementLineAllocation>  $allocations
     * @return list<string>
     */
    private function allocationMovementIds(Collection $allocations): array
    {
        return array_values($allocations
            ->map(static fn (BankStatementLineAllocation $allocation): string => $allocation->repository_movement_id)
            ->unique()
            ->sort()
            ->values()
            ->all());
    }
}
