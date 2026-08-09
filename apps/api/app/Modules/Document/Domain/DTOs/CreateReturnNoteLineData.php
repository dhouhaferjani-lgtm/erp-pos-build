<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

/**
 * One line of a return note being drafted.
 *
 * Plan CF T2. Money and quantity are **strings** end to end (rule 19) — no float
 * ever touches these values. `unit_price` here is B2B **net/HT**: this is the
 * `documents` lane, not the POS `SALE_RECEIPT` lane where `unit_price` is
 * tax-inclusive.
 *
 * `locationId` is per-line and load-bearing for the guided cancel flow: CF-D11
 * emits one line per `(product_id, location_id)` tuple so a product delivered from
 * two locations restocks to both, and `receiveStockBack()` iterates strictly per
 * line (`ReturnNoteService.php:172,187`).
 *
 * A zero (or negative) quantity is rejected by the constructor. The standalone
 * `POST /return-notes` path already refuses one at the request layer
 * (`CreateDocumentRequest.php:120`, `lines.*.quantity` is `gt:0`) but the composite
 * cancel path does not pass through that request at all (CF-D7 / N2-m2), and a
 * zero line would still enter the sealed `total` — `receiveStockBack()` skips it
 * (`ReturnNoteService.php:178-181`) but the totals pass does not.
 */
final class CreateReturnNoteLineData
{
    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $unitPrice
     * @param  numeric-string|null  $taxRate
     * @param  numeric-string|null  $discountPercent
     * @param  numeric-string|null  $discountAmount
     */
    public function __construct(
        public readonly ?string $productId,
        public readonly string $description,
        public readonly string $quantity,
        public readonly string $unitPrice,
        public readonly ?string $taxRate = null,
        public readonly ?string $discountPercent = null,
        public readonly ?string $discountAmount = null,
        public readonly ?string $locationId = null,
        public readonly ?string $notes = null,
    ) {
        if (bccomp($this->quantity, '0', 4) <= 0) {
            throw new \InvalidArgumentException(
                'A return note line must carry a quantity greater than zero; got '.$this->quantity.'.'
            );
        }
    }
}
