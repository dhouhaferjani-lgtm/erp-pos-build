<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Supplier goods-return note lifecycle states (DPA lane V8).
 *
 * draft     — the note exists and states what is going back, but NO stock has
 *             moved and no number has been stamped. This is the state a
 *             deferred "guided AP modal" lane will leave a note in when the
 *             goods have not physically left yet.
 * confirmed — the units have left: one Issue movement per line, plus (bonus
 *             lines only) the quantity-neutral WAC un-dilution. Terminal.
 *
 * Deliberately only two cases. `GoodsReceiptStatus` (draft|posted) is the
 * sibling precedent; `TransferStatus`'s four-state machine exists because a
 * transfer has an in-transit window and a cancel path, neither of which a
 * goods-return note has today. Cancelling a CONFIRMED note means reversing real
 * stock motion, which needs its own reversing document — a case added here with
 * no transition behind it would be exactly the placeholder this codebase forbids.
 */
enum SupplierGoodsReturnNoteStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';

    public function canBeConfirmed(): bool
    {
        return $this === self::Draft;
    }

    public function isTerminal(): bool
    {
        return $this === self::Confirmed;
    }
}
