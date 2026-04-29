<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS receipt is created and fiscally sealed.
 *
 * NF525 TICKET event - every receipt creation must be audit-logged
 * with its fiscal hash and chain sequence for compliance verification.
 */
final class ReceiptCreated extends DomainEvent
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly string $receiptNumber,
        public readonly string $total,
        public readonly string $currency,
        public readonly ?string $fiscalHash,
        public readonly ?int $chainSequence,
        public readonly string $postedAt,
    ) {
        parent::__construct($receiptId);
    }

    public function getEventName(): string
    {
        return 'receipt.created';
    }
}
