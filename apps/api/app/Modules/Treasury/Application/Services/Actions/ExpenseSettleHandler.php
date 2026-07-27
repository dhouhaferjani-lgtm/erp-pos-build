<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\ExpenseSettlementRequestedFromStatement;
use App\Modules\Treasury\Domain\RepositoryMovement;
use DomainException;

final readonly class ExpenseSettleHandler implements StatementActionHandlerInterface
{
    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::ExpenseSettle;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $expenseId = $this->requiredString($params, 'expense_id');
        $paymentMethodId = $params['payment_method_id'] ?? null;
        if ($paymentMethodId !== null && ! is_string($paymentMethodId)) {
            throw new DomainException('Statement action parameter payment_method_id must be a string.');
        }
        $statement = $line->statement()->firstOrFail();
        event(new ExpenseSettlementRequestedFromStatement(
            tenantId: $statement->tenant_id,
            companyId: $statement->company_id,
            expenseId: $expenseId,
            repositoryId: $line->payment_repository_id,
            paymentMethodId: $paymentMethodId,
            paymentDate: $line->value_date->toDateString(),
            userId: $userId,
        ));
        $movement = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $line->payment_repository_id)
            ->where('source_type', MovementSourceType::Expense)
            ->where('source_id', $expenseId)
            ->where('idempotency_key', MovementSourceType::Expense->value.":{$expenseId}:settlement")
            ->first();
        if (! $movement instanceof RepositoryMovement) {
            throw new DomainException('Expense settlement did not produce a repository movement.');
        }

        return new ExecutionResult(
            movementIds: [$movement->id],
            targetType: 'expense_document',
            targetId: $expenseId,
            semanticDigest: StatementActionDigest::make(MatchActionType::ExpenseSettle, $line, $params),
        );
    }

    /** @param array<string, mixed> $params */
    private function requiredString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Statement action parameter {$key} is required.");
        }

        return $value;
    }
}
