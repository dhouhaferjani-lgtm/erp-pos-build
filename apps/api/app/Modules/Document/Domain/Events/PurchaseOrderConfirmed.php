<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a purchase order is confirmed.
 *
 * This event triggers landed cost allocation and marks the order as committed.
 * Confirmed purchase orders are ready for goods receipt.
 *
 * Key use cases:
 * - Trigger landed cost allocation to purchase lines
 * - Lock in supplier prices and terms
 * - Start goods receipt workflow
 * - Track order lifecycle for audit trail
 * - Monitor purchase order patterns for procurement analytics
 */
final class PurchaseOrderConfirmed extends DomainEvent
{
    public function __construct(
        public readonly string $purchaseOrderId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $total,
        public readonly string $currency,
        public readonly string $confirmedBy,
        public readonly string $confirmedAt,
    ) {
        parent::__construct($purchaseOrderId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'purchase_order.confirmed';
    }
}
