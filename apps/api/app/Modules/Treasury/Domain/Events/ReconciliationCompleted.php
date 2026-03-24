<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a bank reconciliation is completed.
 *
 * Dispatched from BankReconciliationService::completeReconciliation().
 * Immutable — never modify once deployed.
 */
final class ReconciliationCompleted extends DomainEvent
{
    public function __construct(
        public readonly string $reconciliationId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $repositoryId,
        public readonly int $matchedCount,
        public readonly string $matchedTotal,
        public readonly string $completedAt,
    ) {
        parent::__construct($reconciliationId);
    }

    public function getEventName(): string
    {
        return 'treasury.reconciliation.completed';
    }

    /**
     * @return array<string, int|string>
     */
    public function getAuditPayload(): array
    {
        return [
            'reconciliation_id' => $this->reconciliationId,
            'repository_id' => $this->repositoryId,
            'matched_count' => $this->matchedCount,
            'matched_total' => $this->matchedTotal,
            'completed_at' => $this->completedAt,
        ];
    }
}
