<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain\Enums;

/**
 * Reason a supplier credit note was issued (Procurement-to-Pay, Phase 1).
 *
 * Drives the GL + quantity_invoiced matrix explicitly (by intent), rather than
 * inferring stock state:
 *
 *  - PriceAdjustment: a price reduction with no goods movement. Reverses VAT +
 *    Inventory for the HT portion; does NOT touch quantity_invoiced.
 *  - GoodsReturn: goods returned to the supplier. Reverses VAT + Inventory for the
 *    HT portion AND decrements quantity_invoiced on the linked PO line(s),
 *    reopening them for re-invoicing.
 *
 * DEFERRED (Phase 2): the "returned goods no longer in stock → expense /
 * purchase price-variance" variant. Phase 1 always credits Inventory.
 */
enum SupplierCreditNoteReason: string
{
    case PriceAdjustment = 'price_adjustment';
    case GoodsReturn = 'goods_return';

    /**
     * Whether this reason reverses the quantity already invoiced on the PO line.
     */
    public function decrementsQuantityInvoiced(): bool
    {
        return match ($this) {
            self::GoodsReturn => true,
            self::PriceAdjustment => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PriceAdjustment => 'Price Adjustment',
            self::GoodsReturn => 'Goods Return',
        };
    }
}
