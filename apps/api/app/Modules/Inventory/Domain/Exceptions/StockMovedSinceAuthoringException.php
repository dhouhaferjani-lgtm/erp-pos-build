<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * The on-hand quantity moved between the moment the operator authored a
 * correction line and the moment it was posted (DPA V7 / D15).
 *
 * Thrown from INSIDE `costLock->acquire` → after `lockStockLevel()`, which is the
 * only place `quantity_before` is authoritative: checking it anywhere earlier
 * reproduces verbatim the lost-update race the whole document exists to close.
 *
 * Overridable, explicitly and never silently: `acknowledge_stale: true` on the
 * POST body, audited on the header's `stale_acknowledged_at` /
 * `stale_acknowledged_by_user_id`.
 */
class StockMovedSinceAuthoringException extends DomainException
{
    /**
     * @param  numeric-string  $observedBefore  What the operator saw when authoring
     * @param  numeric-string  $quantityBefore  What the row actually holds under the lock
     */
    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly ?string $variantId,
        public readonly ?string $batchUuid,
        public readonly string $observedBefore,
        public readonly string $quantityBefore,
        public readonly int $quantityDecimals,
    ) {
        parent::__construct(
            "Stock for product {$productId} at location {$locationId} moved since authoring: "
            ."observed {$observedBefore}, now {$quantityBefore}."
        );
    }
}
