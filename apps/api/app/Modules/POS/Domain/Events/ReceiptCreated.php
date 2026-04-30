<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS receipt is fiscally sealed (post-finalization).
 *
 * NF525 TICKET event — fired by ReceiptFinalizationService::finalize() after the
 * fiscal hash is computed and the terminal chain advances.  At this point
 * fiscalHash and chainSequence are always set.
 *
 * The preceding lifecycle event (receipt created as pending_seal) is
 * ReceiptDrafted, fired by ReceiptCreationService::createReceipt().
 *
 * Rule #8 — this event is immutable forever.  Do not widen fiscalHash or
 * chainSequence to nullable.  Introduce a versioned successor (ReceiptCreatedV2)
 * instead if the signature must change.
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
        public readonly string $fiscalHash,
        public readonly int $chainSequence,
        public readonly string $postedAt,
    ) {
        parent::__construct($receiptId);
    }

    public function getEventName(): string
    {
        return 'receipt.created';
    }
}
