<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a return note is confirmed.
 *
 * This event triggers stock receipt (customer returns goods) and marks the note as fiscally sealed.
 * Confirmed return notes are ready for credit note creation if needed.
 *
 * Key use cases:
 * - Trigger stock return (increase inventory)
 * - Lock in return details with fiscal hash chain
 * - Track return lifecycle for audit trail
 * - Monitor return patterns for quality/fraud analysis
 * - Link to credit notes for financial reconciliation
 */
final class ReturnNoteConfirmed extends DomainEvent
{
    public function __construct(
        public readonly string $returnNoteId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $total,
        public readonly string $currency,
        public readonly string $fiscalHash,
        public readonly int $chainSequence,
        public readonly string $confirmedAt,
    ) {
        parent::__construct($returnNoteId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'return_note.confirmed';
    }
}
