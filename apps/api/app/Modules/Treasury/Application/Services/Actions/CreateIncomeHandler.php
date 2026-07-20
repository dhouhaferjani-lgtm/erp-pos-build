<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services\Actions;

use App\Modules\Treasury\Application\Contracts\StatementActionHandlerInterface;
use App\Modules\Treasury\Application\DTOs\ExecutionResult;
use App\Modules\Treasury\Application\Services\StatementActionDigest;
use App\Modules\Treasury\Domain\BankStatementLine;
use App\Modules\Treasury\Domain\Enums\MatchActionType;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\IncomeCreationRequestedFromStatement;
use App\Modules\Treasury\Domain\RepositoryMovement;
use DomainException;

final readonly class CreateIncomeHandler implements StatementActionHandlerInterface
{
    public function supports(MatchActionType $action): bool
    {
        return $action === MatchActionType::CreateIncome;
    }

    /** @param array<string, mixed> $params */
    public function execute(BankStatementLine $line, array $params, string $userId): ExecutionResult
    {
        $accountId = $this->requiredString($params, 'income_account_id');
        $statement = $line->statement()->firstOrFail();
        $event = new IncomeCreationRequestedFromStatement(
            tenantId: $statement->tenant_id,
            companyId: $statement->company_id,
            lineId: $line->id,
            repositoryId: $line->payment_repository_id,
            locationId: $line->location_id,
            amount: $line->amount,
            valueDate: $line->value_date->toDateString(),
            userId: $userId,
            incomeAccountId: $accountId,
            sourceName: $this->optionalString($params, 'source_name'),
            notes: $this->optionalString($params, 'notes'),
        );
        event($event);
        $documentId = $event->documentId();
        if ($documentId === null) {
            throw new DomainException('Income creation did not return a document.');
        }
        $movement = RepositoryMovement::query()
            ->where('tenant_id', $statement->tenant_id)
            ->where('company_id', $statement->company_id)
            ->where('payment_repository_id', $line->payment_repository_id)
            ->where('source_type', MovementSourceType::Income)
            ->where('source_id', $documentId)
            ->first();
        if (! $movement instanceof RepositoryMovement) {
            throw new DomainException('Created income did not produce a repository movement.');
        }

        return new ExecutionResult(
            [$movement->id],
            'income_document',
            $documentId,
            StatementActionDigest::make(MatchActionType::CreateIncome, $line, $params),
        );
    }

    /** @param array<string, mixed> $params */
    private function requiredString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new DomainException("Statement action parameter {$key} is required.");
        }

        return trim($value);
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
