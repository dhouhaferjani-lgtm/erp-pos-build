<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a draft journal entry is posted (made permanent with fiscal hash).
 *
 * Dispatched from GeneralLedgerService::postEntry() after the entry
 * transitions from Draft to Posted status with a fiscal hash.
 *
 * Once dispatched, this event is immutable and forms part of the permanent
 * audit log for financial operations.
 */
final class JournalEntryPosted extends DomainEvent
{
    public function __construct(
        public readonly string $entryId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $entryNumber,
        public readonly string $totalDebit,
        public readonly string $totalCredit,
        public readonly string $postedAt,
    ) {
        parent::__construct($entryId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'accounting.journal_entry.posted';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, string>
     */
    public function getAuditPayload(): array
    {
        return [
            'entry_id' => $this->entryId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_number' => $this->entryNumber,
            'total_debit' => $this->totalDebit,
            'total_credit' => $this->totalCredit,
            'posted_at' => $this->postedAt,
        ];
    }
}
