<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class BankStatementReopened extends DomainEvent
{
    public function __construct(
        public readonly string $statementId,
        public readonly string $repositoryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly ?string $recomputedCheckpoint,
        public readonly ?string $recomputedBalance,
        public readonly string $createdBy,
    ) {
        parent::__construct($statementId);
    }

    public function getEventName(): string
    {
        return 'treasury.bank_statement.reopened';
    }

    /** @return array<string, string|null> */
    public function getAuditPayload(): array
    {
        return [
            'statement_id' => $this->statementId,
            'repository_id' => $this->repositoryId,
            'recomputed_checkpoint' => $this->recomputedCheckpoint,
            'recomputed_balance' => $this->recomputedBalance,
            'created_by' => $this->createdBy,
        ];
    }
}
