<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when an invoice is fully paid.
 */
final class InvoicePaid extends DomainEvent
{
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $totalPaid,
        public readonly string $paidAt,
    ) {
        parent::__construct($invoiceId);
    }

    /**
     * Get the event name for audit logging.
     */
    public function getEventName(): string
    {
        return 'invoice.paid';
    }
}
