<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS receipt is voided.
 *
 * NF525 ANNULATION event - every void must be audit-logged
 * with the reason and who performed the void.
 */
final class ReceiptVoided extends DomainEvent
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $receiptNumber,
        public readonly string $voidReason,
        public readonly string $voidedBy,
        public readonly string $voidedAt,
    ) {
        parent::__construct($receiptId);
    }

    public function getEventName(): string
    {
        return 'receipt.voided';
    }
}
