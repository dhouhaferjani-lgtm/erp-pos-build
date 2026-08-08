<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * A NEGATIVE correction would drive `available` (= quantity − reserved) below
 * zero (DPA V7 / D1a).
 *
 * This reproduces `issue()`'s boundary exactly — V7 deletes that endpoint and
 * routes its traffic here, so the guard had to come with it. It strictly
 * subsumes a raw on-hand check (reserved ≥ 0 ⇒ available ≤ quantity). POSITIVE
 * deltas skip it entirely, so a line whose on-hand is already negative (POS
 * oversell paths write `stock_levels` directly) stays correctable upward.
 *
 * OVERRIDABLE, unlike `issue()`'s refusal — because the endpoint this document
 * actually replaces (`adjust()`) had no reservation guard at all, so a hard
 * refusal would be a capability regression with no remedy in the product: there
 * is no `stock-reservations` consumer in `apps/web/src`, and the only
 * UI-reachable release is cancelling the source order, which un-promises a
 * still-valid order and records no loss. `ignore_reservations: true`, gated on
 * `inventory.adjustments.post` and audited on the header, is the remedy; after
 * it `available` goes negative, which is the TRUTHFUL state — the stock is gone
 * and the orders are still promised.
 */
class AdjustmentExceedsAvailableException extends DomainException
{
    /**
     * @param  numeric-string  $quantityBefore
     * @param  numeric-string  $reserved
     * @param  numeric-string  $available
     * @param  numeric-string  $deltaQuantity  The SIGNED (negative) delta that was refused
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $quantityBefore,
        public readonly string $reserved,
        public readonly string $available,
        public readonly string $deltaQuantity,
        public readonly int $quantityDecimals,
    ) {
        parent::__construct(
            "Adjustment of {$deltaQuantity} for product {$productId} at location {$locationId} exceeds "
            ."available stock (on hand {$quantityBefore}, reserved {$reserved}, available {$available})."
        );
    }
}
