<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a delivery note is confirmed.
 *
 * This is a fiscal event that is part of the hash chain for Tunisia compliance.
 * Once dispatched, this event is immutable and should never be modified.
 *
 * Key differences from InvoicePosted:
 * - DN is hashed on CONFIRM, not POST (no GL entries for DN)
 * - DN has its own separate hash chain per company
 * - Required for Tunisia fiscal compliance (tamper-proof delivery documents)
 */
final class DeliveryNoteConfirmed extends DomainEvent
{
    public function __construct(
        public readonly string $deliveryNoteId,
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
        parent::__construct($deliveryNoteId);
    }

    /**
     * Get the data to be used for hash chain calculation.
     *
     * @return array<string, string|int>
     */
    public function getHashableData(): array
    {
        return [
            'document_number' => $this->documentNumber,
            'document_type' => 'delivery_note',
            'confirmed_at' => $this->confirmedAt,
            'total' => $this->total,
            'currency' => $this->currency,
            'fiscal_hash' => $this->fiscalHash,
            'chain_sequence' => $this->chainSequence,
        ];
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'delivery_note.confirmed';
    }
}
