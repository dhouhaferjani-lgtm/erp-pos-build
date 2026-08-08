<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnLineKind;

/**
 * One requested line of a supplier goods-return note (DPA lane V8).
 *
 * A DTO rather than an array so the caller (Procurement's credit-note posting
 * orchestrator) cannot hand Inventory a loosely-typed payload, and so the
 * `kind` discriminator travels as an enum instead of a magic string.
 */
final readonly class SupplierGoodsReturnLineData
{
    /**
     * @param  string  $poLineId  The `document_lines` row (a purchase-order line) the units were ordered against.
     * @param  string  $quantity  Units going back, at the canonical 4-dp stock scale. Deliberately
     *                            typed `string`, not `numeric-string`: this DTO is the boundary a
     *                            caller in another module hands data across, so the numeric/positive
     *                            contract is ENFORCED at runtime by
     *                            `SupplierGoodsReturnNoteService::createDraft()` rather than merely
     *                            asserted in a docblock a caller can be wrong about.
     * @param  string|null  $goodsReceiptId  The receipt the units arrived on, where resolvable.
     * @param  string|null  $preferredLocationId  Where the units are believed to sit (typically the
     *                                            receipt's destination). A hint: the service falls back
     *                                            to the largest stock-bearing location when it holds no
     *                                            stock, and refuses when nothing holds any.
     */
    public function __construct(
        public string $poLineId,
        public string $productId,
        public ?string $variantId,
        public SupplierGoodsReturnLineKind $kind,
        public string $quantity,
        public ?string $goodsReceiptId = null,
        public ?string $goodsReceiptLineId = null,
        public ?string $preferredLocationId = null,
    ) {}
}
