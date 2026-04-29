<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Dispatched when a POS receipt is created in the pending_seal state.
 *
 * Fired by ReceiptCreationService::createReceipt() after the receipt row is
 * persisted with fiscal_status = pending_seal.  At this point no fiscal hash
 * exists and the terminal chain has not advanced.  The complementary
 * ReceiptCreated event is dispatched later, by
 * ReceiptFinalizationService::finalize(), once the receipt is sealed.
 *
 * Rule #8 — Events are immutable forever.
 * This event exists so that ReceiptCreated can keep its original, immutable
 * signature (non-nullable fiscalHash + chainSequence).  Widening those fields
 * to nullable would have violated Rule #8; instead we introduce this separate
 * lifecycle event.
 */
final class ReceiptDrafted extends DomainEvent
{
    public function __construct(
        public readonly string $receiptId,
        public readonly string $companyId,
        public readonly string $terminalId,
        public readonly string $cashierId,
        public readonly string $receiptNumber,
        public readonly string $total,
        public readonly string $currency,
        public readonly string $postedAt,
    ) {
        parent::__construct($receiptId);
    }

    public function getEventName(): string
    {
        return 'receipt.drafted';
    }
}
