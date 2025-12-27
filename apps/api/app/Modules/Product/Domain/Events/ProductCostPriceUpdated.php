<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a product's cost price is updated due to WAC calculation.
 *
 * This is an audit event (NOT fiscal) used for:
 * - Tracking cost price changes over time
 * - Recording automatic sale price recalculations
 * - Anomaly detection (sudden cost spikes)
 * - Operational reporting and analytics
 *
 * NOT part of fiscal hash chain - stored in audit log only.
 */
final class ProductCostPriceUpdated extends DomainEvent
{
    public function __construct(
        public readonly string $productId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $productSku,
        public readonly string $oldCostPrice,
        public readonly string $newCostPrice,
        public readonly string $oldSalePrice,
        public readonly string $newSalePrice,
        public readonly string $reason,
        public readonly ?string $referenceDocument,
    ) {
        parent::__construct($productId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'product.cost_price_updated';
    }
}
