<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * What kind of unit a supplier goods-return note line hands back (DPA lane V8).
 *
 * The distinction is a COSTING one, not a paperwork one:
 *
 * ordinary — a PAID unit. It entered inventory at its landed cost, so it leaves
 *            at the current weighted average cost and the WAC itself does not
 *            move. Inventory value falls by quantity x WAC.
 * bonus    — a FREE unit. It entered through
 *            `recordPurchase(landedUnitCost: '0')` and DILUTED the WAC, so
 *            handing it back must UN-dilute it: inventory value is unchanged
 *            (giving back something that cost nothing costs nothing) and the
 *            per-unit cost rises to what the remaining paid units really cost.
 *
 * A boolean `is_bonus` column would have carried the same bit, but this is a
 * type column and CLAUDE rule 9 applies — and the two cases each name a
 * different costing rule, which a boolean cannot document.
 */
enum SupplierGoodsReturnLineKind: string
{
    case Ordinary = 'ordinary';
    case Bonus = 'bonus';

    /**
     * Does relieving this unit require restoring the value the exit destroyed?
     *
     * True only for bonus units: their cost basis is zero, so the implied
     * `quantity x WAC` value drop that any quantity decrement produces is not
     * economically real and must be given back to the surviving units.
     */
    public function requiresWacUndilution(): bool
    {
        return $this === self::Bonus;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ordinary => 'Paid units',
            self::Bonus => 'Bonus (free) units',
        };
    }
}
