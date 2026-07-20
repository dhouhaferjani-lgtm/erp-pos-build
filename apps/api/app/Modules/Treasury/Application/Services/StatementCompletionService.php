<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Treasury\Domain\BankStatement;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\BankStatementLineAllocation;
use App\Modules\Treasury\Domain\BankStatementMatchExecution;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\StatementLineMatchStatus;
use App\Modules\Treasury\Domain\Events\BankStatementReconciled;
use App\Modules\Treasury\Domain\Events\BankStatementReopened;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class StatementCompletionService
{
    public function __construct(private CurrencyScaleResolverInterface $scaleResolver) {}

    public function complete(
        string $statementId,
        string $userId,
        bool $acknowledgeIgnored = false,
    ): BankStatement {
        return DB::transaction(function () use ($statementId, $userId, $acknowledgeIgnored): BankStatement {
            $statement = BankStatement::query()->whereKey($statementId)->lockForUpdate()->first();
            if (! $statement instanceof BankStatement) {
                throw new DomainException('Bank statement was not found.');
            }
            $actor = User::query()->where('tenant_id', $statement->tenant_id)->find($userId);
            if (! $actor instanceof User) {
                throw new DomainException('The completion user does not belong to the statement tenant.');
            }
            if ($statement->status !== BankStatementStatus::Reconciling) {
                throw new DomainException('Only a reconciling bank statement can be completed.');
            }

            /** @var Collection<int, BankStatementLine> $lines */
            $lines = BankStatementLine::query()
                ->where('bank_statement_id', $statement->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lineIds = $lines->modelKeys();

            /** @var Collection<int, BankStatementLineAllocation> $allocations */
            $allocations = BankStatementLineAllocation::query()
                ->whereIn('bank_statement_line_id', $lineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            /** @var Collection<int, BankStatementMatchExecution> $executions */
            $executions = BankStatementMatchExecution::query()
                ->whereIn('bank_statement_line_id', $lineIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $movementIds = $allocations->pluck('repository_movement_id')->all();
            foreach ($executions as $execution) {
                array_push($movementIds, ...$execution->produced_repository_movement_ids);
            }
            $movementIds = array_values(array_unique($movementIds));
            sort($movementIds, SORT_STRING);

            /** @var Collection<string, RepositoryMovement> $movements */
            $movements = RepositoryMovement::query()
                ->where('tenant_id', $statement->tenant_id)
                ->where('company_id', $statement->company_id)
                ->where('payment_repository_id', $statement->payment_repository_id)
                ->where('currency', $statement->currency)
                ->whereIn('id', $movementIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($movements->count() !== count($movementIds)) {
                throw new DomainException('A statement allocation references a movement outside its repository.');
            }

            $repository = PaymentRepository::query()
                ->where('tenant_id', $statement->tenant_id)
                ->where('company_id', $statement->company_id)
                ->whereKey($statement->payment_repository_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNoEarlierOpenStatement($statement);
            $scale = $this->scaleResolver->getScale($statement->currency);
            $signedStatementTotal = '0';
            $signedIgnoredTotal = '0';
            $hasIgnoredLines = false;

            foreach ($lines as $line) {
                $lineAllocations = $allocations->where('bank_statement_line_id', $line->id);
                if ($line->match_status === StatementLineMatchStatus::Ignored) {
                    $hasIgnoredLines = true;
                    if ($lineAllocations->isNotEmpty()
                        || $executions->where('bank_statement_line_id', $line->id)->isNotEmpty()) {
                        throw new DomainException('An ignored statement line cannot retain allocations or executions.');
                    }
                    $signedIgnoredTotal = $line->direction === MovementDirection::In
                        ? bcadd($signedIgnoredTotal, $line->amount, $scale)
                        : bcsub($signedIgnoredTotal, $line->amount, $scale);

                    continue;
                }
                if (! in_array($line->match_status, [
                    StatementLineMatchStatus::Matched,
                    StatementLineMatchStatus::ResolvedByCreation,
                ], true)) {
                    throw new DomainException('Every statement line must be terminal before completion.');
                }

                $signedLineTotal = '0';
                foreach ($lineAllocations as $allocation) {
                    $movement = $movements->get($allocation->repository_movement_id);
                    if (! $movement instanceof RepositoryMovement) {
                        throw new DomainException('A statement allocation movement was not locked.');
                    }
                    $signedLineTotal = $movement->direction === $line->direction
                        ? bcadd($signedLineTotal, $allocation->matched_amount, $scale)
                        : bcsub($signedLineTotal, $allocation->matched_amount, $scale);
                }
                if (bccomp($signedLineTotal, $line->amount, $scale) !== 0) {
                    throw new DomainException('A terminal statement line does not have an exact signed allocation total.');
                }
                $signedStatementTotal = $line->direction === MovementDirection::In
                    ? bcadd($signedStatementTotal, $line->amount, $scale)
                    : bcsub($signedStatementTotal, $line->amount, $scale);
            }

            foreach ($movements as $movement) {
                $globalAllocated = (string) BankStatementLineAllocation::query()
                    ->where('repository_movement_id', $movement->id)
                    ->sum('matched_amount');
                if (bccomp($globalAllocated, $movement->amount, $scale) > 0) {
                    throw new DomainException('A repository movement is overallocated.');
                }
            }

            $expectedDelta = bcsub($statement->closing_balance, $statement->opening_balance, $scale);
            $expectedNonIgnoredDelta = bcsub($expectedDelta, $signedIgnoredTotal, $scale);
            if (bccomp($signedStatementTotal, $expectedNonIgnoredDelta, $scale) !== 0) {
                throw new DomainException('Signed non-ignored statement lines do not equal closing balance minus opening balance, less ignored lines.');
            }
            if ($hasIgnoredLines && (! $acknowledgeIgnored || ! $actor->can('bank-statements.reopen'))) {
                throw new DomainException('Ignored statement lines require explicit acknowledgment by a user allowed to reopen statements.');
            }

            $checkpoint = $this->checkpointFor($statement);
            $statement->status = BankStatementStatus::Reconciled;
            $statement->save();
            PaymentRepository::query()->whereKey($repository->id)->update([
                'last_reconciled_at' => $checkpoint,
                'last_reconciled_balance' => $statement->closing_balance,
            ]);
            DB::afterCommit(fn () => event(new BankStatementReconciled(
                statementId: $statement->id,
                repositoryId: $repository->id,
                tenantId: $statement->tenant_id,
                companyId: $statement->company_id,
                checkpoint: $checkpoint->toIso8601String(),
                closingBalance: $statement->closing_balance,
                signedIgnoredTotal: $signedIgnoredTotal,
                ignoredTotalAcknowledged: $acknowledgeIgnored,
                createdBy: $userId,
            )));

            return $statement->fresh() ?? $statement;
        });
    }

    public function reopen(string $statementId, string $userId): BankStatement
    {
        return DB::transaction(function () use ($statementId, $userId): BankStatement {
            $statement = BankStatement::query()->whereKey($statementId)->lockForUpdate()->first();
            if (! $statement instanceof BankStatement) {
                throw new DomainException('Bank statement was not found.');
            }
            if (! User::query()->where('tenant_id', $statement->tenant_id)->whereKey($userId)->exists()) {
                throw new DomainException('The reopen user does not belong to the statement tenant.');
            }
            if ($statement->status !== BankStatementStatus::Reconciled) {
                throw new DomainException('Only a reconciled bank statement can be reopened.');
            }
            $laterExists = BankStatement::query()
                ->where('payment_repository_id', $statement->payment_repository_id)
                ->where('status', BankStatementStatus::Reconciled)
                ->where('period_end', '>', $statement->period_end)
                ->lockForUpdate()
                ->exists();
            if ($laterExists) {
                throw new DomainException('A statement cannot be reopened while a later statement remains reconciled.');
            }

            $repository = PaymentRepository::query()
                ->where('tenant_id', $statement->tenant_id)
                ->where('company_id', $statement->company_id)
                ->whereKey($statement->payment_repository_id)
                ->lockForUpdate()
                ->firstOrFail();
            $statement->status = BankStatementStatus::Reconciling;
            $statement->save();

            $latest = BankStatement::query()
                ->where('payment_repository_id', $statement->payment_repository_id)
                ->where('status', BankStatementStatus::Reconciled)
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->first();
            PaymentRepository::query()->whereKey($repository->id)->update([
                'last_reconciled_at' => $latest instanceof BankStatement ? $this->checkpointFor($latest) : null,
                'last_reconciled_balance' => $latest?->closing_balance,
            ]);
            $recomputedCheckpoint = $latest instanceof BankStatement ? $this->checkpointFor($latest) : null;
            DB::afterCommit(fn () => event(new BankStatementReopened(
                statementId: $statement->id,
                repositoryId: $repository->id,
                tenantId: $statement->tenant_id,
                companyId: $statement->company_id,
                recomputedCheckpoint: $recomputedCheckpoint?->toIso8601String(),
                recomputedBalance: $latest?->closing_balance,
                createdBy: $userId,
            )));

            return $statement->fresh() ?? $statement;
        });
    }

    private function assertNoEarlierOpenStatement(BankStatement $statement): void
    {
        $exists = BankStatement::query()
            ->where('payment_repository_id', $statement->payment_repository_id)
            ->where('period_end', '<', $statement->period_end)
            ->whereNotIn('status', [BankStatementStatus::Reconciled, BankStatementStatus::Voided])
            ->exists();
        if ($exists) {
            throw new DomainException('An earlier active statement must be reconciled or voided first.');
        }
    }

    private function checkpointFor(BankStatement $statement): CarbonImmutable
    {
        $timezone = (string) Company::query()->whereKey($statement->company_id)->value('timezone');

        return CarbonImmutable::parse($statement->period_end->toDateString(), $timezone)
            ->endOfDay()
            ->utc();
    }
}
