<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised when a sales order is confirmed.
 *
 * This event triggers stock reservation and marks the order as committed.
 * Unlike quotes, confirmed sales orders represent firm commitments.
 *
 * Key use cases:
 * - Trigger stock reservation for order lines
 * - Lock in prices and terms
 * - Start fulfillment workflow
 * - Track order lifecycle for fraud detection
 * - Monitor order confirmation patterns
 */
final class SalesOrderConfirmed extends DomainEvent
{
    /**
     * @param  array<int, array{line_id: string, product_id: string, quantity: string, location_id: string}>  $lines
     */
    public function __construct(
        public readonly string $salesOrderId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $documentNumber,
        public readonly string $partnerId,
        public readonly string $total,
        public readonly string $currency,
        public readonly array $lines,
        public readonly string $confirmedBy,
        public readonly string $confirmedAt,
    ) {
        parent::__construct($salesOrderId);
    }

    /**
     * Get the event name for logging purposes.
     */
    public function getEventName(): string
    {
        return 'sales_order.confirmed';
    }
}
