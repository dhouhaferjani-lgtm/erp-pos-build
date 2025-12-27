<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a journal entry is created in the general ledger.
 *
 * This event is part of the audit trail for accounting operations.
 * It captures all essential data about the GL entry including the fiscal hash
 * chain information for compliance and tamper detection.
 *
 * Once dispatched, this event is immutable and forms part of the permanent
 * audit log for financial operations.
 */
final class JournalEntryCreated extends DomainEvent
{
    public function __construct(
        public readonly string $journalEntryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $entryNumber,
        public readonly string $entryDate,
        public readonly string $entryType,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $fiscalHash,
        public readonly int $chainSequence,
        public readonly string $createdAt,
    ) {
        parent::__construct($journalEntryId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'journal_entry.created';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, mixed>
     */
    public function getAuditData(): array
    {
        return [
            'journal_entry_id' => $this->journalEntryId,
            'entry_number' => $this->entryNumber,
            'entry_type' => $this->entryType,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'fiscal_hash' => $this->fiscalHash,
            'chain_sequence' => $this->chainSequence,
        ];
    }
}
