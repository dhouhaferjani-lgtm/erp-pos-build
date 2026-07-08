<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Events;

use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a repository movement (append-only ledger row) is
 * recorded by TreasuryMovementService::record().
 *
 * This is the audit-trail counterpart to the `repository_movements` table:
 * the row itself is the durable record of the money movement, but this
 * event is what lets Compliance capture actor + full movement context in
 * `audit_events` without the Treasury module depending on Compliance.
 *
 * Immutable — never modify once deployed.
 */
final class RepositoryMovementRecorded extends DomainEvent
{
    public function __construct(
        public readonly string $movementId,
        public readonly string $repositoryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly MovementDirection $direction,
        public readonly string $amount,
        public readonly string $balanceAfter,
        public readonly string $currency,
        public readonly MovementSourceType $sourceType,
        public readonly string $sourceId,
        public readonly ?string $journalEntryId,
        public readonly int $ordinal,
        public readonly bool $recordedWhileFrozen,
        public readonly string $occurredAt,
    ) {
        parent::__construct($movementId);
    }

    public function getEventName(): string
    {
        return 'treasury.repository.movement_recorded';
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    public function getAuditPayload(): array
    {
        return [
            'movement_id' => $this->movementId,
            'repository_id' => $this->repositoryId,
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'balance_after' => $this->balanceAfter,
            'currency' => $this->currency,
            'source_type' => $this->sourceType->value,
            'source_id' => $this->sourceId,
            'journal_entry_id' => $this->journalEntryId,
            'ordinal' => $this->ordinal,
            'recorded_while_frozen' => $this->recordedWhileFrozen,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
