<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

/**
 * One `(product, location)` tuple of delivered quantity for an invoice.
 *
 * Plan CF CF-D11 / fiscal gate N2-C1. The tuple — not the product — is the unit,
 * because a product's delivered quantity can legitimately span two locations in two
 * verified ways: a multi-DN invoice consolidating deliveries from different
 * warehouses, and per-line `location_id` inside a single DN (a shape whose own
 * docblock states the intent verbatim: "Per-line location for multi-location
 * orders", `DocumentLine.php:220-227`, honoured per line by `issueStock()`'s
 * `$line->location ?? $deliveryNote->location`).
 *
 * Keying by product alone would force a caller to pick ONE location for the whole
 * quantity, and `receiveStockBack()` iterates strictly per line — so 3 units
 * delivered from L1 and 2 from L2 would restock 5 into one location: two units
 * created where they never left, silently, with no refusal, because a location DOES
 * resolve — it is just wrong for part of the quantity.
 */
final class DeliveredQuantityTuple
{
    /**
     * @param  numeric-string  $delivered  Total issued for this tuple on confirmed delivery notes.
     * @param  numeric-string  $alreadyReturned  Netted from prior non-cancelled return notes.
     * @param  numeric-string  $remaining  `delivered − alreadyReturned`, floored at zero.
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $delivered,
        public readonly string $alreadyReturned,
        public readonly string $remaining,
    ) {}
}
