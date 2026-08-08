<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

/**
 * What the user said about the goods when they cancelled the invoice.
 *
 * The owner ruling's core constraint: "whether a return note needs to be created or
 * not, **the decision is explicit, never silent — whatever it may be**". So there is
 * no null mode and no default. Every recorded cancellation of a goods-bearing invoice
 * carries one of these, and each one is a different statement about physical reality:
 *
 *  - `WillReturn` — the goods are coming back later. A DRAFT return note is created,
 *    pre-linked to the cancelled invoice, for the physical return to complete.
 *  - `AlreadyReturned` — the goods came back on a stated date. The return note is
 *    created AND confirmed, dated as stated, behind the scenes.
 *  - `NoReturn` — the goods are genuinely gone. No stock comes back and the cost
 *    stays charged. The explicit "no" is itself recorded, which is the whole point.
 *  - `NoGoodsIssued` — nothing was ever delivered against this invoice, so there is
 *    nothing to return. DISTINCT from `NoReturn` (CF-D6 / frontend N-1): "the goods
 *    stayed out" and "the goods never left" are different facts, and recording the
 *    first when the second is true would tell an auditor the customer kept units
 *    that never shipped.
 *  - `NotApplicable` — a services-only invoice; there are no physical lines at all.
 *
 * The last two are recorded by the SERVER's reading of the invoice, not by a radio
 * button the user clicked — the modal's option 3 posts `NoGoodsIssued` in the
 * no-delivery branch and `NoReturn` in the goods-issued branch, which is why T10's
 * option→mode mapping is a table per BRANCH rather than per option.
 */
enum ReturnDecisionMode: string
{
    case WillReturn = 'will_return';

    case AlreadyReturned = 'already_returned';

    case NoReturn = 'no_return';

    case NoGoodsIssued = 'no_goods_issued';

    case NotApplicable = 'not_applicable';

    /**
     * Whether this mode moves physical units, and therefore needs a return note, a
     * delivered-quantity resolution and a restock location.
     */
    public function bearsGoods(): bool
    {
        return $this === self::WillReturn || $this === self::AlreadyReturned;
    }

    /**
     * Whether the return note is sealed as part of the cancel, rather than left as a
     * draft for the physical return to complete later.
     */
    public function sealsTheReturnNote(): bool
    {
        return $this === self::AlreadyReturned;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
