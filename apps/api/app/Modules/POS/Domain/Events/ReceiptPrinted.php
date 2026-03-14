<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a receipt is printed or reprinted.
 *
 * NF525 compliance requires every receipt copy to be logged.
 * This event enables downstream audit trail processing.
 */
final class ReceiptPrinted extends DomainEvent
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $terminalId,
        public readonly string $companyId,
        public readonly string $userId,
        public readonly string $printType,
        public readonly int $copyNumber,
        public readonly string $printMethod,
        public readonly string $printedAt,
    ) {
        parent::__construct($receiptId);
    }

    public function getEventName(): string
    {
        return 'receipt.printed';
    }
}
