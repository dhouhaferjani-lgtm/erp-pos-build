<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when any document (invoice, credit note, etc.) is fully paid.
 *
 * This is the versioned successor to InvoicePaid. The old event is kept
 * immutable (Rule #8) for backward compatibility with stored audit data.
 * New dispatches use this event which includes documentType for clarity.
 */
final class DocumentFullyPaid extends DomainEvent
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $documentType,
        public readonly string $partnerId,
        public readonly string $totalPaid,
        public readonly string $paidAt,
    ) {
        parent::__construct($documentId);
    }

    public function getEventName(): string
    {
        return 'document.fully_paid';
    }
}
