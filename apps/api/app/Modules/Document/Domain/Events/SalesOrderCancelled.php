<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a sales order is cancelled.
 *
 * This event triggers the release of all stock reservations for the order.
 * Cancellations are tracked for fraud detection and operational analytics.
 *
 * Key use cases:
 * - Release all stock reservations for the cancelled order
 * - Track cancellation patterns for fraud detection
 * - Monitor customer behavior (frequent cancellations may indicate issues)
 * - Audit trail for order lifecycle
 */
final class SalesOrderCancelled extends DomainEvent
{
    public function __construct(
        public readonly string $salesOrderId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $cancellationReason,
        public readonly string $cancelledBy,
        public readonly string $cancelledAt,
    ) {
        parent::__construct($salesOrderId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'sales_order.cancelled';
    }
}
