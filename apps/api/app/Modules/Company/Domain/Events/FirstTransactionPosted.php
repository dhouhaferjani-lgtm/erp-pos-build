<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a company's first transaction is posted.
 *
 * This event permanently locks the fiscal year configuration:
 * - Fiscal year start month can NEVER be changed again
 * - This is a critical compliance event for audit trail
 *
 * Triggered by:
 * - First invoice posting
 * - First credit note posting
 * - First journal entry posting (manual or automated)
 *
 * This is a one-time, irreversible event per company.
 */
final class FirstTransactionPosted extends DomainEvent
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $documentId,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $postedAt,
    ) {
        parent::__construct($companyId);
    }

    public function getEventName(): string
    {
        return 'company.first_transaction.posted';
    }
}
