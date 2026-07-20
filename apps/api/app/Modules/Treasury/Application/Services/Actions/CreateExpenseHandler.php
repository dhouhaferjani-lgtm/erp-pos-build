<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\ExpenseCreationRequestedFromStatement;
use App\Modules\Treasury\Domain\RepositoryMovement;
use DomainException;

final readonly class CreateExpenseHandler implements StatementActionHandlerInterface
{
    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::CreateExpense;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $statement = $line->statement()->firstOrFail();
        $event = new ExpenseCreationRequestedFromStatement(
            tenantId: $statement->tenant_id,
            companyId: $statement->company_id,
            lineId: $line->id,
            repositoryId: $line->payment_repository_id,
            locationId: $line->location_id,
            amount: $line->amount,
            valueDate: $line->value_date->toDateString(),
            userId: $userId,
            expenseCategoryId: $this->optionalString($params, 'expense_category_id'),
            vendorName: $this->optionalString($params, 'vendor_name'),
            notes: $this->optionalString($params, 'notes'),
        );
        event($event);
        $documentId = $event->documentId();
        if ($documentId === null) {
            throw new DomainException('Expense creation did not return a document.');
        }
        $movement = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $line->payment_repository_id)
            ->where('source_type', MovementSourceType::Expense)
            ->where('source_id', $documentId)
            ->first();
        if (! $movement instanceof RepositoryMovement) {
            throw new DomainException('Created expense did not produce a repository movement.');
        }

        return new ExecutionResult(
            [$movement->id],
            'expense_document',
            $documentId,
            StatementActionDigest::make(MatchActionType::CreateExpense, $line, $params),
        );
    }

    /** @param array<string, mixed> $params */
    private function optionalString(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw new DomainException("Statement action parameter {$key} must be a string.");
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
