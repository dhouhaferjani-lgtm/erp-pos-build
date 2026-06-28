<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Immutable event raised once per purchase-order line when goods are physically received.
 *
 * Carries only scalars / numeric strings — no Eloquent models, no floats.
 * The movementId is the idempotency anchor: a re-dispatch for the same
 * movement (e.g. retry) must be a no-op in downstream listeners.
 *
 * Accounting subscribes to this event to post Dr Inventory / Cr 408 (GR-IR).
 */
final class GoodsReceived extends DomainEvent
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productId,
        public readonly string $locationId,
        public readonly string $poLineId,
        /** Idempotency anchor — UUID of the StockMovement created by recordPurchase(). */
        public readonly string $movementId,
        /** Numeric string — quantity received for this line (4 dp). */
        public readonly string $receivedQty,
        /** Numeric string — landed unit cost captured BEFORE the float cast in GoodsReceiptService. */
        public readonly string $unitCost,
        /** ISO 4217 currency code, e.g. 'TND'. */
        public readonly string $currency,
    ) {
        parent::__construct($movementId);
    }

    public function getEventName(): string
    {
        return 'inventory.goods_received';
    }
}
