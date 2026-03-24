<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an opening balance batch is successfully posted.
 *
 * Dispatched from AccountingOpeningService::postBatch() after the batch
 * is posted as a historical journal entry and all rows are marked as posted.
 *
 * Once dispatched, this event is immutable and forms part of the permanent
 * audit log for financial operations.
 */
final class OpeningBalancePosted extends DomainEvent
{
    public function __construct(
        public readonly string $batchId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly int $entryCount,
        public readonly string $totalAmount,
        public readonly string $postedAt,
    ) {
        parent::__construct($batchId);
    }

    /**
     * Get the event name for logging and auditing purposes.
     */
    public function getEventName(): string
    {
        return 'accounting.opening_balance.posted';
    }

    /**
     * Get the data to be included in audit logs.
     *
     * @return array<string, int|string>
     */
    public function getAuditPayload(): array
    {
        return [
            'batch_id' => $this->batchId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_count' => $this->entryCount,
            'total_amount' => $this->totalAmount,
            'posted_at' => $this->postedAt,
        ];
    }
}
