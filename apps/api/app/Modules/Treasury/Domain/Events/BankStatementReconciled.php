<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class BankStatementReconciled extends DomainEvent
{
    public function __construct(
        public readonly string $statementId,
        public readonly string $repositoryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $checkpoint,
        public readonly string $closingBalance,
        public readonly string $signedIgnoredTotal,
        public readonly bool $ignoredTotalAcknowledged,
        public readonly string $createdBy,
    ) {
        parent::__construct($statementId);
    }

    public function getEventName(): string
    {
        return 'treasury.bank_statement.reconciled';
    }

    /** @return array<string, string|bool> */
    public function getAuditPayload(): array
    {
        return [
            'statement_id' => $this->statementId,
            'repository_id' => $this->repositoryId,
            'checkpoint' => $this->checkpoint,
            'closing_balance' => $this->closingBalance,
            'signed_ignored_total' => $this->signedIgnoredTotal,
            'ignored_total_acknowledged' => $this->ignoredTotalAcknowledged,
            'created_by' => $this->createdBy,
        ];
    }
}
